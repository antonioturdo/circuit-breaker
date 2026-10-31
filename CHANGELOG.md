# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.4.0] - 2026-10-31

### Changed

- **BREAKING** — `CallGate` is removed: `CallAttempt::admit()` admits a call
  through a circuit breaker, and the attempt registers an outcome with
  `register()`, known directly or given by a classifier, instead of
  `registerResult()` and `registerException()`.
- **BREAKING** — `Configuration` limits `openDuration` and the maximum duration
  of the half-open policy to 1 hour, instead of 30 days.

## [0.3.0] - 2026-10-19

### Added

- `CallExecutor`: runs a call through a circuit breaker, registering its
  outcome and duration; `CallNotPermittedException` when the circuit does not
  permit the call.
- `CallGate`: admits calls and hands out a `CallAttempt` registering the
  outcome later, for asynchronous calls and integrations.
- `OutcomeClassifierInterface`: classifies the exceptions and results of the
  calls made with a client or a library; `CallbackOutcomeClassifier` for
  single calls.

## [0.2.0] - 2026-10-18

### Added

- `SlowCallRateRule`: opens the circuit when the rate of calls taking at least
  a given duration reaches a threshold, computed on the outcomes registered
  with a duration.
- `FailureOrSlowCallRateRule`: opens the circuit when the rate of calls that
  failed or took at least a given duration reaches a threshold, computed on
  all outcomes.
- Optional duration of the call, in seconds, in `registerSuccess()` and
  `registerFailure()`.

### Changed

- **BREAKING** — `Configuration` takes `openingRules`, a list of opening
  rules (`FailureRateRule` among them), instead of `failureRateThreshold` and
  `minimumRequests`. The circuit opens when any rule requires it.
- **BREAKING** — `registerSuccess()` and `registerFailure()` of
  `CircuitBreakerInterface` take an optional duration.
- **BREAKING** — `CircuitOpened` carries the cause of the opening
  (`openingCause`): the opening rule with the outcomes it matched and
  evaluated.
- **BREAKING** — `HalfOpenProgress` carries the number of half-open outcomes
  (`outcomes`) instead of `successes` and `failures`.

## [0.1.0] - 2026-10-17

### Added

- `CircuitBreaker`: closed, open and half-open states, opening on the failure
  rate of a sliding time window counted in buckets, with a minimum number of
  outcomes before deciding.
- Half-open policies: `RampPolicy` (exponential, linear or power curve, or a
  custom `RampCurveInterface`), `PermittedCallsPolicy` (including the single
  trial), or a custom `HalfOpenPolicyInterface`.
- Storages sharing the state with atomic operations: `InMemoryStorage`,
  `ApcuStorage`, `RedisStorage` (phpredis, standalone or cluster) and
  `MemcachedStorage`.
- Events for transitions, rejected calls and storage failures; calls are
  permitted while the storage fails.
- `ClockInterface` to control time in tests.

[Unreleased]: https://github.com/antonioturdo/circuit-breaker/compare/0.4.0...HEAD
[0.4.0]: https://github.com/antonioturdo/circuit-breaker/compare/0.3.0...0.4.0
[0.3.0]: https://github.com/antonioturdo/circuit-breaker/compare/0.2.0...0.3.0
[0.2.0]: https://github.com/antonioturdo/circuit-breaker/compare/0.1.0...0.2.0
[0.1.0]: https://github.com/antonioturdo/circuit-breaker/releases/tag/0.1.0
