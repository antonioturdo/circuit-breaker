<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Storage;

use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;
use Zeusi\CircuitBreaker\Outcome;
use Zeusi\CircuitBreaker\State;

/**
 * The storage keys of a circuit.
 *
 * Every key starts with "cb:v1:{service}": the version separates incompatible formats, the service between braces is
 * the partition of the keys (see StorageInterface). The outcomes and the calls of a state are kept apart by the period
 * spent in it, for example "cb:v1:{payments}:h:mv3242h7:calls" and "cb:v1:{payments}:h:mv3242h7:f:tmpuoa".
 *
 * @internal
 */
final class KeyScheme
{
    private readonly string $keyPrefix;

    /**
     * @throws InvalidArgumentException if the service name is empty or contains braces
     */
    public function __construct(string $service)
    {
        if ($service === '' || strpbrk($service, '{}') !== false) {
            throw new InvalidArgumentException(\sprintf('The service name must be non-empty and contain no braces, "%s" given', $service));
        }

        $this->keyPrefix = \sprintf('cb:v1:{%s}', $service);
    }

    public function getStateKey(): string
    {
        return $this->keyPrefix . ':state';
    }

    /**
     * Gets the key counting the calls asking for permission during the period spent in the given half-open state.
     */
    public function getHalfOpenCallsKey(CircuitState $state): string
    {
        return $this->getPeriodPrefix($state) . ':calls';
    }

    /**
     * Gets the key counting the outcomes of a bucket during the period spent in the given state.
     *
     * @param int $bucket identifier of the bucket, chosen by the outcome counter; in base 36 in the key
     */
    public function getOutcomeKey(CircuitState $state, Outcome $outcome, int $bucket): string
    {
        return \sprintf('%s:%s:%s', $this->getPeriodPrefix($state), $outcome === Outcome::Success ? 's' : 'f', base_convert((string) $bucket, 10, 36));
    }

    /**
     * Gets the prefix of the keys of the period spent in the given state.
     *
     * A period is identified by the kind of state and its start time in base 36. The kind keeps periods of different
     * kinds apart even if they started in the same millisecond, for example a half-open period and the closed period
     * before the opening on servers with wrong clocks.
     */
    private function getPeriodPrefix(CircuitState $state): string
    {
        $kind = match ($state->state) {
            State::Closed => 'c',
            State::Open => 'o',
            State::HalfOpen => 'h',
        };

        return \sprintf('%s:%s:%s', $this->keyPrefix, $kind, base_convert((string) $state->since, 10, 36));
    }
}
