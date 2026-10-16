# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/antonioturdo/circuit-breaker/compare/0.1.0...HEAD
[0.1.0]: https://github.com/antonioturdo/circuit-breaker/releases/tag/0.1.0
