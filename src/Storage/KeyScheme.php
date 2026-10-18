<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Storage;

use Zeusi\CircuitBreaker\Counting\DurationBand;
use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;
use Zeusi\CircuitBreaker\Outcome;
use Zeusi\CircuitBreaker\State;

/**
 * The storage keys of a circuit.
 *
 * Every key starts with "cb:v1:{service}": the version separates incompatible formats, the service between braces is
 * the partition of the keys (see StorageInterface). The outcomes and the calls of a state are kept apart by the period
 * spent in it, for example "cb:v1:{payments}:h:mv3242h7:calls" and "cb:v1:{payments}:h:mv3242h7:f1jk-:tmpuoa".
 * An outcome count is coded by s (successes) or f (failures) followed by the code of its duration band, empty when
 * durations are not counted ("s"), or for outcomes without a duration; otherwise both bounds of the band, so that
 * bands of different configurations never share a key ("s0-1jk", "f1jk-").
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
     * Gets the key counting the outcomes of a duration band in a bucket of the period spent in the given state.
     *
     * @param int $bucket identifier of the bucket, chosen by the outcome counter; in base 36 in the key
     */
    public function getOutcomeKey(CircuitState $state, Outcome $outcome, DurationBand $band, int $bucket): string
    {
        return $this->buildBucketKey($state, ($outcome === Outcome::Success ? 's' : 'f') . self::getBandCode($band), $bucket);
    }

    /**
     * Gets the code of a duration band: empty for the band without bounds, otherwise its bounds in base 36.
     *
     * Both bounds are in the code, so that bands of different configurations never share a key.
     */
    private static function getBandCode(DurationBand $band): string
    {
        if ($band->lowerBound === null) {
            return '';
        }

        $upperCode = $band->upperBound === null ? '' : base_convert((string) $band->upperBound, 10, 36);

        return base_convert((string) $band->lowerBound, 10, 36) . '-' . $upperCode;
    }

    private function buildBucketKey(CircuitState $state, string $countCode, int $bucket): string
    {
        return \sprintf('%s:%s:%s', $this->getPeriodPrefix($state), $countCode, base_convert((string) $bucket, 10, 36));
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
