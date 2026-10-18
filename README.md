# Circuit Breaker

[![Packagist Version](https://img.shields.io/packagist/v/zeusi/circuit-breaker.svg)](https://packagist.org/packages/zeusi/circuit-breaker)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.1-777bb4.svg)](https://www.php.net/)
[![CI](https://github.com/antonioturdo/circuit-breaker/actions/workflows/ci.yml/badge.svg)](https://github.com/antonioturdo/circuit-breaker/actions/workflows/ci.yml)
[![PHPStan](https://img.shields.io/badge/phpstan-level%208-brightgreen.svg)](https://phpstan.org/)
[![Coverage](https://codecov.io/gh/antonioturdo/circuit-breaker/graph/badge.svg)](https://codecov.io/gh/antonioturdo/circuit-breaker)
[![License](https://img.shields.io/packagist/l/zeusi/circuit-breaker.svg)](LICENSE)

> A circuit breaker for PHP with combinable opening rules on failed and slow calls, configurable half-open policies, and state shared through APCu, Redis or Memcached.

When a service your application depends on stops answering, or answers slowly, every call to it holds a PHP worker until it times out or completes, and the workers pile up until the application itself stops answering. A circuit breaker notices the failures or the slowness and, for a while, tells your code not to call that service: your code falls back instead, your workers stay free and the service gets time to recover.

- **Combinable opening rules** over a sliding time window: failure rate, slow call rate, or failed and slow calls together, each with its own threshold and minimum number of calls. The circuit opens when any rule requires it, so it reacts to a service that slows down, not only to one that fails.
- **Configurable recovery**: after an outage, a half-open policy decides how the service is tested. Besides the classic single trial, a ramp brings the calls back gradually instead of all at once, so a single lucky success is not enough to close the circuit.
- **Shared state** with atomic operations, safe under concurrent PHP-FPM workers and across servers.
- **Storage failures**: if the storage fails, calls are permitted and an event tells you.

## Installation

```bash
composer require zeusi/circuit-breaker
```

Requires 64-bit PHP 8.1 or later, plus the extension of the storage you use: `apcu`, `redis` (phpredis) or `memcached`.

## Usage

```php
use Zeusi\CircuitBreaker\CircuitBreaker;
use Zeusi\CircuitBreaker\Configuration;
use Zeusi\CircuitBreaker\HalfOpen\RampPolicy;
use Zeusi\CircuitBreaker\Opening\FailureRateRule;
use Zeusi\CircuitBreaker\Storage\RedisStorage;

$configuration = new Configuration(
    timeWindow: 60,   // seconds over which calls are counted
    openingRules: [
        // opens at 50% of failed calls, with at least 10 calls in the time window
        new FailureRateRule(threshold: 50, minimumRequests: 10),
    ],
    openDuration: 10, // seconds during which no call is permitted once open
    halfOpenPolicy: RampPolicy::createExponential(duration: 60, initialRatio: 0.01),
);

$breaker = new CircuitBreaker('payments', $configuration, new RedisStorage($redis));

if (!$breaker->acquirePermission()) {
    return ChargeResult::deferred(); // the circuit is open: fall back without calling the service
}

try {
    $result = $paymentGateway->charge($order);
} catch (GatewayUnavailableException $e) {
    $breaker->registerFailure();

    throw $e;
}

$breaker->registerSuccess();
```

A circuit breaker protects one service, identified by its name. It holds no state of its own, so creating one per call, or per host discovered at runtime, costs nothing.

### Registering outcomes

The circuit measures whether the service is alive. Register:

- **a success** when the service answered, even with an application error unrelated to its health: not found, invalid data, access denied;
- **a failure** when it did not answer, or answered with an error of its own: timeout, connection refused, 5xx;
- **nothing** when the call never left, for example because building the request failed.

A missing outcome is only a sample less: the circuit never waits for it.

Both methods also accept the duration of the call in seconds, for example `registerSuccess(0.25)`, needed by the slow call rate rule.

### Two settings to get right

- **The client timeout must be shorter than `max_execution_time`.** A call killed by PHP registers no failure, so the circuit would never open.
- **`openDuration` should be at least as long as the call timeout.** Calls started before the circuit opened can still fail afterwards: if they fail while the circuit is testing the service again, they make it look still broken.

## How it works

- **Closed**: every call is permitted. When an outcome brings the rate of an opening rule (failed calls, or slow calls) to its threshold or above, with at least its `minimumRequests` outcomes in the time window, the circuit opens.
- **Open**: no call is permitted for `openDuration`.
- **Half-open**: the circuit tests the service with part of the calls, as decided by the half-open policy. As soon as the rate of an opening rule over the half-open outcomes within the time window reaches its threshold, the circuit opens again; this state does not wait for `minimumRequests`. Otherwise it closes when the policy says enough has been observed, or at the latest after the policy's maximum duration.

There is no background process: a transition due to time happens on the first call that finds it due, and its event is emitted then. After a long period without calls, the circuit skips the half-open state and closes: no traffic reached the service in the meantime.

`getState()` returns the state the next call would find, without changing anything.

### How outcomes are counted

The time window slides: the failure rate always covers the last `timeWindow` seconds. Unlike consecutive fixed windows, a burst of failures across the boundary of two intervals is not split in half.

- The window is split into buckets of at least 1 second, at most 60: a 30 second window has 30 buckets of 1 second, a 10 minute window 60 buckets of 10 seconds. Every outcome is a single atomic increment, of the key of its bucket, outcome and duration band, so concurrent workers never lose one.
- A count covers the buckets spanning the window, so it is precise within one bucket: 1 second for windows up to a minute, 1/60 of the window beyond. With fewer than 10 buckets the count would approach a fixed window, hence the minimum of 10 seconds.
- Every state counts only its own outcomes: the half-open state is never influenced by the failures that opened the circuit, and the closed state starts afresh after it. Nothing is ever deleted: older keys expire by themselves.
- An active service takes at most about 120 short keys (one per bucket for successes and for failures), a few tens of kilobytes, whatever its traffic. Rules on slow calls split them by duration: with one slow call duration, at most about 360.

### Configuration

| Parameter | Unit | Limits |
|---|---|---|
| `timeWindow` | seconds | 10 to 3600 |
| `openingRules` | | at least one |
| `openDuration` | seconds | 1 to 30 days |
| `halfOpenPolicy` | | maximum duration from 1 second to 30 days |

Durations are floats, with millisecond precision. Every parameter is required.

### Opening rules

The circuit opens when any opening rule requires it, so failures and slowness can have thresholds of their own:

```php
use Zeusi\CircuitBreaker\Opening\SlowCallRateRule;

openingRules: [
    new FailureRateRule(threshold: 50, minimumRequests: 10),
    // opens when 80% of the calls take 2 seconds or more
    new SlowCallRateRule(slowCallDuration: 2.0, threshold: 80, minimumRequests: 10),
],
```

| Rule | Parameter | Unit | Limits |
|---|---|---|---|
| `FailureRateRule` | `threshold` | percentage of failed calls | 1 to 100 |
| | `minimumRequests` | calls in the time window | at least 1 |
| `SlowCallRateRule` | `slowCallDuration` | seconds from which a call is slow | at least 0.001 |
| | `threshold` | percentage of slow calls | 1 to 100 |
| | `minimumRequests` | calls with a duration in the time window | at least 1 |
| `FailureOrSlowCallRateRule` | `slowCallDuration` | seconds from which a call is slow | at least 0.001 |
| | `threshold` | percentage of failed or slow calls | 1 to 100 |
| | `minimumRequests` | calls in the time window | at least 1 |

A slow call is counted whether it succeeded or failed: a timeout counts for both rules. The slow call rate is computed on the outcomes registered with a duration, so calls registered without one neither raise nor dilute it. Durations are counted only when a rule needs them, in bands delimited by the slow call durations: every outcome is still a single storage increment, and each slow call duration adds a few keys to read.

A slow service holds PHP-FPM workers as long as a dead one: with PHP-FPM, a call that failed or was slow costs the same, and `FailureOrSlowCallRateRule` counts them together, a slow failure once, over all outcomes. It can sit next to a rule on failures only, with a lower threshold for errors:

```php
use Zeusi\CircuitBreaker\Opening\FailureOrSlowCallRateRule;

openingRules: [
    new FailureRateRule(threshold: 50, minimumRequests: 10),
    // opens when 70% of the calls failed or took 2 seconds or more
    new FailureOrSlowCallRateRule(slowCallDuration: 2.0, threshold: 70, minimumRequests: 10),
],
```

In asynchronous runtimes, where a slow call does not hold a worker, `SlowCallRateRule` with a threshold of its own opens only when the service is clearly degraded.

### Half-open policies

A **ramp** permits a growing ratio of calls during its duration, then closes the circuit. It tests the service on real traffic and protects it from getting the full load at once:

```php
RampPolicy::createExponential(duration: 60, initialRatio: 0.01); // 1%, doubling at regular intervals
RampPolicy::createLinear(duration: 60, initialRatio: 0.01);      // 1%, growing at a constant pace
```

The exponential ramp spends more time on low ratios: half way through, 10% of the calls are permitted, against about 50% for the linear one. The initial ratio is relative to your traffic: on a busy service, 1% may already be many calls.

The linear ramp is a power curve with exponent 1. A greater exponent starts more slowly, a lower one faster:

```php
RampPolicy::createPower(duration: 60, initialRatio: 0.01, exponent: 2); // about 26% half way
```

**Permitted calls** permit the first calls of the half-open state and close the circuit once they all reported back, or at the latest after the maximum duration:

```php
use Zeusi\CircuitBreaker\HalfOpen\PermittedCallsPolicy;

PermittedCallsPolicy::createSingleTrial(maxDuration: 30);     // the classic single trial
new PermittedCallsPolicy(maxDuration: 30, permittedCalls: 5); // the first 5 calls
```

A single successful trial closes the circuit even for a service that succeeds only now and then, and sends it the full traffic: the ramp is usually the safer choice.

A ramp can follow any curve, mapping its progress (0 to 1) to the ratio of calls permitted:

```php
use Zeusi\CircuitBreaker\HalfOpen\RampCurveInterface;

final class SteppedRampCurve implements RampCurveInterface
{
    public function computeAdmissionRatio(float $progress): float
    {
        return $progress < 0.5 ? 0.1 : 0.5; // 10%, then 50% from half way
    }
}

new RampPolicy(duration: 60, curve: new SteppedRampCurve());
```

## Storages

| Storage | Shared by | Notes |
|---|---|---|
| `ApcuStorage` | the processes of one PHP master (a PHP-FPM pool, a CLI command) | needs `apc.enable_cli=1` on the CLI |
| `RedisStorage` | every server | phpredis, standalone or cluster |
| `MemcachedStorage` | every server | the `memcached` extension |
| `InMemoryStorage` | the current process | tests and long-running processes |

The shared storages accept a prefix, to separate applications sharing the same backend. Without it, two applications calling the same service name share its circuit.

### APCu

APCu memory belongs to a single PHP master process, so every server, every PHP-FPM pool and every CLI command has its own circuits, opened by the failures it sees. When circuits must be shared across servers, use Redis or Memcached.

APCu has no background cleanup: an expired entry is removed when another entry is inserted in the same slot of its hash table. Since buckets are inserted continuously, the number of entries grows for a while, then stays around the live entries plus the number of slots (about four times `apc.entries_hint`, 4096 by default). This is expected and bounded. With many services, raise `apc.entries_hint` to keep lookups fast. If the rest of the application fills the cache, APCu before 5.1.25 with `apc.ttl=0` clears it entirely, and every circuit restarts closed.

### Redis

```php
$redis = new \Redis();
$redis->connect('127.0.0.1');

$storage = new RedisStorage($redis, prefix: 'shop:');
```

- The client must use no serializer nor compression: values are stored as plain integers.
- Read from the master: a replica lagging behind would give processes different views of the circuit.
- With Redis Cluster (`\RedisCluster`), the keys of a service share a hash tag, so they live on the same node. For this reason the prefixes, of the storage and of the client, must contain no braces.

### Memcached

```php
$memcached = new \Memcached();
$memcached->addServer('127.0.0.1', 11211);

$storage = new MemcachedStorage($memcached, prefix: 'shop:');
```

The keys of a service are kept on the same server.

## Events

Pass an event listener to observe the circuit, for logging, metrics or alerting:

```php
use Zeusi\CircuitBreaker\Event\CircuitOpened;
use Zeusi\CircuitBreaker\Event\EventInterface;
use Zeusi\CircuitBreaker\Event\EventListenerInterface;

final class CircuitLogger implements EventListenerInterface
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function handleEvent(EventInterface $event): void
    {
        if ($event instanceof CircuitOpened) {
            $this->logger->warning('Circuit opened', [
                'service' => $event->getService(),
                'rule' => $event->openingCause->openingRule::class,
                'outcomes' => \sprintf('%d of %d', $event->openingCause->matchingOutcomes, $event->openingCause->evaluatedOutcomes),
            ]);
        }
    }
}

$breaker = new CircuitBreaker('payments', $configuration, $storage, new CircuitLogger($logger));
```

| Event | When |
|---|---|
| `CircuitOpened` | the circuit opened, from closed or again from half-open (`previousState`), with its cause (`openingCause`): the opening rule as configured, with `matchingOutcomes` failed or slow calls out of `evaluatedOutcomes` |
| `CircuitHalfOpened` | the circuit started testing the service |
| `CircuitClosed` | the circuit closed |
| `CallRejected` | a call was not permitted (`state`: open or half-open) |
| `StorageFailed` | the storage failed and the call went through (`exception`) |

`CallRejected` is emitted for every rejected call: count it in a metric rather than logging it. Transitions are emitted once, even with many concurrent workers. Exceptions thrown by the listener are caught and ignored, so a failing listener never breaks the call it observes.

### When the storage fails

Calls are permitted, outcomes are dropped, and a `StorageFailed` event is emitted. The circuit does not protect the service until the storage recovers, so watch that event. `getState()`, meant for diagnostics, throws the storage exception instead.

## Testing

Code using the circuit breaker can depend on `CircuitBreakerInterface` and replace it with a test double. To run a real circuit breaker in tests, use `InMemoryStorage` and pass a `ClockInterface` implementation to control time, to both the storage and the circuit breaker.

## License

MIT, see [LICENSE](LICENSE).
