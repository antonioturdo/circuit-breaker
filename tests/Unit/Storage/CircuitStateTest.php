<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\Exception\StorageException;
use Zeusi\CircuitBreaker\State;
use Zeusi\CircuitBreaker\Storage\CircuitState;

final class CircuitStateTest extends TestCase
{
    /**
     * @return iterable<string, array{CircuitState}>
     */
    public static function stateProvider(): iterable
    {
        yield 'initial' => [new CircuitState(State::Closed, 0)];
        yield 'closed' => [new CircuitState(State::Closed, 1_791_676_800_000)];
        yield 'open' => [new CircuitState(State::Open, 1_791_676_800_123)];
        yield 'half-open' => [new CircuitState(State::HalfOpen, 1_791_676_810_123)];
        yield 'largest time' => [new CircuitState(State::HalfOpen, (1 << 42) - 1)];
    }

    #[DataProvider('stateProvider')]
    public function testEncoding(CircuitState $state): void
    {
        $encoded = $state->encode();

        self::assertGreaterThanOrEqual(0, $encoded);
        // the decoded state remembers the value it comes from, for the next compare-and-set
        self::assertEquals(new CircuitState($state->state, $state->since, $encoded), CircuitState::decode($encoded));
    }

    public function testMissingValueIsTheInitialState(): void
    {
        self::assertEquals(new CircuitState(State::Closed, 0), CircuitState::decode(null));
        self::assertEquals(new CircuitState(State::Closed, 0, 0), CircuitState::decode(0));
    }

    /**
     * @return iterable<string, array{CircuitState}>
     */
    public static function overflowProvider(): iterable
    {
        yield 'time beyond 42 bits' => [new CircuitState(State::Open, 1 << 42)];
        yield 'negative time' => [new CircuitState(State::Open, -1)];
    }

    #[DataProvider('overflowProvider')]
    public function testOverflow(CircuitState $state): void
    {
        $this->expectException(StorageException::class);

        $state->encode();
    }
}
