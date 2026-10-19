<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit\Execution;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\Exception\CallNotPermittedException;
use Zeusi\CircuitBreaker\Execution\CallbackOutcomeClassifier;
use Zeusi\CircuitBreaker\Execution\CallExecutor;
use Zeusi\CircuitBreaker\Outcome;
use Zeusi\CircuitBreaker\Tests\Fixtures\RecordingCircuitBreaker;

final class CallExecutorTest extends TestCase
{
    public function testPermittedCall(): void
    {
        $circuitBreaker = new RecordingCircuitBreaker(permitsCalls: true);
        $executor = new CallExecutor($circuitBreaker, new CallbackOutcomeClassifier(self::classifyException(...)));

        $charge = new \stdClass();

        self::assertSame($charge, $executor->execute(static function () use ($charge): \stdClass {
            usleep(10_000);

            return $charge;
        }));

        $outcomes = $circuitBreaker->getOutcomes();
        self::assertCount(1, $outcomes);
        self::assertSame(Outcome::Success, $outcomes[0][0]);
        self::assertGreaterThanOrEqual(0.01, $outcomes[0][1]);
    }

    public function testRejectedCallDoesNotRun(): void
    {
        $circuitBreaker = new RecordingCircuitBreaker(permitsCalls: false);
        $executor = new CallExecutor($circuitBreaker, new CallbackOutcomeClassifier(self::classifyException(...)));
        $hasRun = false;

        try {
            $executor->execute(static function () use (&$hasRun): void {
                $hasRun = true;
            });
            self::fail('A call not permitted was run');
        } catch (CallNotPermittedException $exception) {
            self::assertSame('payments', $exception->service);
        }

        self::assertFalse($hasRun);
        self::assertSame([], $circuitBreaker->getOutcomes());
    }

    /**
     * @return iterable<string, array{\Throwable, Outcome|null}>
     */
    public static function exceptionProvider(): iterable
    {
        yield 'the service did not answer' => [new \RuntimeException('Connection refused'), Outcome::Failure];
        yield 'the service answered with an error of the caller' => [new \DomainException('Not found'), Outcome::Success];
        yield 'the call never left' => [new \LogicException('Invalid request'), null];
    }

    #[DataProvider('exceptionProvider')]
    public function testCallThrowing(\Throwable $exception, ?Outcome $expectedOutcome): void
    {
        $circuitBreaker = new RecordingCircuitBreaker(permitsCalls: true);
        $executor = new CallExecutor($circuitBreaker, new CallbackOutcomeClassifier(self::classifyException(...)));

        $thrown = null;

        try {
            $executor->execute(static fn() => throw $exception);
        } catch (\Throwable $thrown) {
        }

        self::assertSame($exception, $thrown);
        self::assertSame($expectedOutcome, $circuitBreaker->getOutcomes()[0][0] ?? null);
    }

    /**
     * @return iterable<string, array{int, Outcome|null}>
     */
    public static function resultProvider(): iterable
    {
        yield 'a response of the service' => [200, Outcome::Success];
        yield 'an error of the service' => [503, Outcome::Failure];
        yield 'a request never sent' => [0, null];
    }

    #[DataProvider('resultProvider')]
    public function testCallReturningAClassifiedResult(int $statusCode, ?Outcome $expectedOutcome): void
    {
        $circuitBreaker = new RecordingCircuitBreaker(permitsCalls: true);
        $executor = new CallExecutor($circuitBreaker, new CallbackOutcomeClassifier(
            self::classifyException(...),
            static fn(mixed $statusCode): ?Outcome => match (true) {
                $statusCode === 0 => null,
                $statusCode >= 500 => Outcome::Failure,
                default => Outcome::Success,
            },
        ));

        // the result is returned whatever its outcome
        self::assertSame($statusCode, $executor->execute(static fn(): int => $statusCode));
        self::assertSame($expectedOutcome, $circuitBreaker->getOutcomes()[0][0] ?? null);
    }

    private static function classifyException(\Throwable $exception): ?Outcome
    {
        return match (true) {
            $exception instanceof \DomainException => Outcome::Success,
            $exception instanceof \LogicException => null,
            default => Outcome::Failure,
        };
    }
}
