# Circuit Breaker

[![Packagist Version](https://img.shields.io/packagist/v/zeusi/circuit-breaker.svg)](https://packagist.org/packages/zeusi/circuit-breaker)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.1-777bb4.svg)](https://www.php.net/)
[![CI](https://github.com/antonioturdo/circuit-breaker/actions/workflows/ci.yml/badge.svg)](https://github.com/antonioturdo/circuit-breaker/actions/workflows/ci.yml)
[![PHPStan](https://img.shields.io/badge/phpstan-level%208-brightgreen.svg)](https://phpstan.org/)
[![Coverage](https://codecov.io/gh/antonioturdo/circuit-breaker/graph/badge.svg)](https://codecov.io/gh/antonioturdo/circuit-breaker)
[![License](https://img.shields.io/packagist/l/zeusi/circuit-breaker.svg)](LICENSE)

> A circuit breaker for PHP with combinable opening rules on failed and slow calls, configurable half-open policies, and state shared through APCu, Redis or Memcached.

When a service stops answering, or answers slowly, every call to it holds a PHP worker, until the application itself stops answering. A circuit breaker notices it and, for a while, tells your code to fall back instead of calling the service.

- **Combinable opening rules** over a sliding time window: failure rate, slow call rate, or both together, each with its own threshold.
- **Configurable recovery**: the service is tested with a number of trial calls, from the classic single one up, or with a ramp bringing the calls back gradually, so that one lucky success need not close the circuit.
- **Shared state** in APCu, Redis or Memcached, or in your own storage implementing three atomic primitives, safe under concurrent PHP-FPM workers and across servers. If the storage fails, calls are permitted and an event tells you.

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

A circuit breaker protects one service, identified by its name. Its state lives in the storage, under that name: instances with the same name share the circuit.

### Registering outcomes

The circuit measures whether the service is alive. Register:

- **a success** when the service answered, even with an application error unrelated to its health: not found, invalid data, access denied;
- **a failure** when it did not answer, or answered with an error of its own: timeout, connection refused, 5xx.

Register nothing when the call says nothing about the service, for example because building the request failed: a missing outcome is only a sample less.

Both methods also accept the duration of the call in seconds, for example `registerSuccess(0.25)`, needed by the rules on slow calls. A `CallExecutor` measures it for you (see [Running calls](#running-calls)).

### Two settings to get right

- **The client timeout must be shorter than `max_execution_time`.** A call killed by PHP registers no failure, so the circuit would never open.
- **`openDuration` should be at least as long as the call timeout.** Calls started before the circuit opened can still fail afterwards: if they fail while the circuit is testing the service again, they make it look still broken.

## Running calls

Calling the breaker around every request soon becomes repetitive: write it once, in an abstraction hiding its mechanics, such as a client of the service or a decorator of your HTTP client, so that the rest of the code calls the service as before. The library provides the pieces: a classifier of outcomes, a `CallExecutor` for synchronous calls and a `CallAttempt` for asynchronous ones.

### Classifying outcomes

The rules of [Registering outcomes](#registering-outcomes) depend on the client: which exceptions mean that the service did not answer, which ones that it answered with an error of the caller. An `OutcomeClassifierInterface` writes them once for each client or library:

```php
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Zeusi\CircuitBreaker\Execution\OutcomeClassifierInterface;
use Zeusi\CircuitBreaker\Outcome;

final class HttpClientOutcomeClassifier implements OutcomeClassifierInterface
{
    public function classifyException(\Throwable $exception): ?Outcome
    {
        return match (true) {
            $exception instanceof TransportExceptionInterface, // refused, timed out
            $exception instanceof ServerExceptionInterface => Outcome::Failure, // 5xx
            $exception instanceof ClientExceptionInterface, // 4xx: the service answered
            $exception instanceof RedirectionExceptionInterface => Outcome::Success,
            default => null, // nothing about the service: register nothing
        };
    }

    public function classifyResult(mixed $result): ?Outcome
    {
        return Outcome::Success; // the errors are thrown when the response is read
    }
}
```

When a class is too much, for example for a single kind of call, `CallbackOutcomeClassifier` takes the two methods as closures: `new CallbackOutcomeClassifier($classifyException, $classifyResult)`, where every result is a success when the second one is omitted.

### Synchronous calls

A `CallExecutor` acquires the permission, runs the call, measures its duration and registers its outcome. Whatever the outcome, the result of the call is returned and its exceptions are rethrown; when the circuit does not permit the call, it does not run and a `CallNotPermittedException` is thrown. A client of a payment service, for example:

```php
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Zeusi\CircuitBreaker\CircuitBreakerInterface;
use Zeusi\CircuitBreaker\Execution\CallExecutor;

final class PaymentGateway
{
    private readonly CallExecutor $executor;

    public function __construct(
        private readonly HttpClientInterface $client,
        CircuitBreakerInterface $breaker, // new CircuitBreaker('payments', ...)
    ) {
        $this->executor = new CallExecutor($breaker, new HttpClientOutcomeClassifier());
    }

    public function charge(Order $order): array
    {
        // the response is read within the call, so that the executor sees it complete
        return $this->executor->execute(fn () => $this->client->request('POST', '/charges', ['json' => $order])->toArray());
    }
}
```

The rest of the code charges orders without knowing about the breaker, and handles the rejection where it handles the unavailability of the service:

```php
try {
    $charge = $paymentGateway->charge($order);
} catch (CallNotPermittedException) {
    return ChargeResult::deferred(); // the circuit did not permit the call
}
```

### Asynchronous calls

Promises and lazy responses, such as those of Symfony HttpClient, complete after the executor would have returned, unlike calls awaited in a fiber (Amp, Revolt). Admit the call as a `CallAttempt`, then register its outcome when it completes, with the duration known by the client, or `null` when unknown:

```php
use Zeusi\CircuitBreaker\Execution\CallAttempt;
use Zeusi\CircuitBreaker\Outcome;

$attempt = CallAttempt::admit($breaker); // throws CallNotPermittedException when the call is not permitted
// start the call, then in whatever callback completes it:
$attempt->register(Outcome::Failure, $duration);                           // an outcome known directly, or
$attempt->register($classifier->classifyException($exception), $duration); // given by a classifier
```

The storages are synchronous: in an event loop, a Redis or Memcached round trip blocks every request in flight. With fibers, an adapter on an asynchronous client can implement `StorageInterface` and await inside its methods.

## How it works

- **Closed**: every call is permitted. When an outcome brings the rate of an opening rule (failed calls, or slow calls) to its threshold or above, with at least its `minimumRequests` outcomes in the time window, the circuit opens.
- **Open**: no call is permitted for `openDuration`.
- **Half-open**: the circuit tests the service with part of the calls, as decided by the half-open policy. As soon as an opening rule reaches its threshold over the half-open outcomes in the time window, without waiting for `minimumRequests`, the circuit opens again. Otherwise it closes when the policy allows it: at the end of a ramp, or once the permitted calls have all reported back, at the latest after the maximum duration.

There is no background process: a transition due to time happens on the first call that finds it due, and its event is emitted then. After a long period without calls, the circuit skips the half-open state and closes, as a half-open state without calls would at the end of its maximum duration. The `getState()` method returns the state the next call would find, without executing any transition.

### How outcomes are counted

The time window slides: the rates always cover the last `timeWindow` seconds.

- The window is split into at most 60 buckets of at least 1 second: a 30 second window has 30 buckets of 1 second, a 10 minute window 60 buckets of 10 seconds. Every outcome is a single atomic increment of the key of its bucket, outcome and duration band, so concurrent workers never lose one.
- A count covers the buckets spanning the window, so its start is precise within the duration of one bucket: 1 second for windows up to a minute, 1/60 of the window beyond.
- Every state counts only its own outcomes: the half-open state is never influenced by the failures that opened the circuit, and the closed state starts afresh after it. Nothing is ever deleted: older keys expire by themselves.
- An active service takes at most about 120 short keys (one per bucket for successes and for failures), a few tens of kilobytes, whatever its traffic and its rules on failures. Rules on slow calls split the keys into duration bands: at most about 360 keys with one slow call duration, 120 more for every other distinct one, rules with the same duration sharing their bands. Every outcome is still a single increment, while every count reads the keys of all bands.

### Configuration

| Parameter | Unit | Limits |
|---|---|---|
| `timeWindow` | seconds | 10 to 3600 |
| `openingRules` | | at least one |
| `openDuration` | seconds | 1 to 30 days |
| `halfOpenPolicy` | | maximum duration from 1 second to 30 days |

Durations are in seconds, as floats with millisecond precision: `0.25` is 250 milliseconds. Every parameter is required.

### Opening rules

The circuit opens when any opening rule requires it, so failed calls, slow calls, or both counted together can have thresholds of their own:

```php
use Zeusi\CircuitBreaker\Opening\SlowCallRateRule;

openingRules: [
    // opens when 50% of the calls fail
    new FailureRateRule(threshold: 50, minimumRequests: 10),
    // opens when 80% of the calls take 2 seconds or more
    new SlowCallRateRule(slowCallDuration: 2.0, threshold: 80, minimumRequests: 10),
],
```

Every rule opens the circuit when the rate it computes reaches its `threshold`, a percentage from 1 to 100, over at least `minimumRequests` outcomes. The rules on slow calls also take a `slowCallDuration`, in seconds, from which a call is slow.

- **`FailureRateRule`**: failed calls over all outcomes.
- **`SlowCallRateRule`**: slow calls, succeeded or failed, over the outcomes registered with a duration: calls registered without one count neither in the rate nor towards `minimumRequests`.
- **`FailureOrSlowCallRateRule`**: failed or slow calls over all outcomes, for runtimes such as PHP-FPM where a slow call holds a worker as long as a failed one.

A failed call reaching the slow call duration, such as a timeout, counts both as a failure and as a slow call. Durations are counted only when a rule needs them, at the cost of [more keys](#how-outcomes-are-counted).

### Half-open policies

The half-open policy decides which calls test the service and when the circuit closes, unless an opening rule opens it again first. There are two: a ramp, or a number of permitted calls.

A **ramp** permits a growing ratio of calls during its duration, then closes the circuit. It tests the service on real traffic and protects it from getting the full load at once:

```php
RampPolicy::createExponential(duration: 60, initialRatio: 0.01);       // 10% of the calls half way
RampPolicy::createPower(duration: 60, initialRatio: 0.01, exponent: 2); // about 26% half way
RampPolicy::createLinear(duration: 60, initialRatio: 0.01);            // about 50% half way
```

All three start from 1% of the calls: the exponential ramp spends more time on low ratios, a power curve starts more slowly the greater its exponent. The initial ratio is relative to your traffic: on a busy service, 1% may already be many calls.

You can also implement other curves with `RampCurveInterface`, mapping the progress of the ramp (0 to 1) to the ratio of calls permitted, and pass them to `new RampPolicy(duration: 60, curve: $curve)`.

**Permitted calls** permit the first calls of the half-open state and close the circuit once they all reported back, or at the latest after the maximum duration:

```php
use Zeusi\CircuitBreaker\HalfOpen\PermittedCallsPolicy;

PermittedCallsPolicy::createSingleTrial(maxDuration: 30);     // the classic single trial
new PermittedCallsPolicy(maxDuration: 30, permittedCalls: 5); // the first 5 calls
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

APCu has no background cleanup: expired entries linger until their slot is reused, so the number of entries grows for a while, then levels off. With many services, you may need to raise `apc.entries_hint` and `apc.shm_size`: when the cache fills up, APCu before 5.1.25 with the default `apc.ttl=0` clears it entirely, and every circuit restarts closed.

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
| `StorageFailed` | the storage failed: the call was permitted or its outcome dropped (`exception`) |

`CallRejected` is emitted for every rejected call: count it in a metric rather than logging it. Transitions are emitted once, even with many concurrent workers. Exceptions thrown by the listener are caught and ignored, so a failing listener never breaks the call it observes. Watch `StorageFailed`: until the storage recovers, every call is permitted and the circuit protects nothing. `getState()`, meant for diagnostics, throws the storage exception instead.

## Testing

Code using the circuit breaker can depend on `CircuitBreakerInterface` and replace it with a test double. To run a real circuit breaker in tests, use `InMemoryStorage` and pass a `ClockInterface` implementation to control time, to both the storage and the circuit breaker.

## License

MIT, see [LICENSE](LICENSE).
