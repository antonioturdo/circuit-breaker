<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit\Execution;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\Exception\CallNotPermittedException;
use Zeusi\CircuitBreaker\Execution\CallAttempt;
use Zeusi\CircuitBreaker\Outcome;
use Zeusi\CircuitBreaker\Tests\Fixtures\RecordingCircuitBreaker;

final class CallAttemptTest extends TestCase
{
    /**
     * @return iterable<string, array{list<array{Outcome|null, float|null}>, list<array{Outcome, float|null}>}>
     */
    public static function registrationProvider(): iterable
    {
        yield 'a failure reported twice' => [[[Outcome::Failure, 2.5], [Outcome::Success, 3.0]], [[Outcome::Failure, 2.5]]];
        yield 'an unknown duration' => [[[Outcome::Success, null]], [[Outcome::Success, null]]];
        yield 'nothing about the service, then a failure' => [[[null, 1.0], [Outcome::Failure, 2.0]], []];
    }

    /**
     * @param list<array{Outcome|null, float|null}> $registrations
     * @param list<array{Outcome, float|null}>      $expectedOutcomes
     */
    #[DataProvider('registrationProvider')]
    public function testAdmittedCallRegistersItsFirstOutcomeOnly(array $registrations, array $expectedOutcomes): void
    {
        $circuitBreaker = new RecordingCircuitBreaker(permitsCalls: true);
        $attempt = CallAttempt::admit($circuitBreaker);

        // later, in whatever callbacks complete the call, with the duration known by the client
        foreach ($registrations as [$outcome, $duration]) {
            $attempt->register($outcome, $duration);
        }

        self::assertSame($expectedOutcomes, $circuitBreaker->getOutcomes());
    }

    public function testRejectedCall(): void
    {
        $circuitBreaker = new RecordingCircuitBreaker(permitsCalls: false);

        $this->expectException(CallNotPermittedException::class);

        CallAttempt::admit($circuitBreaker);
    }
}
