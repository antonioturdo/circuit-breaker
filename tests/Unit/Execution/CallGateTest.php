<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\Exception\CallNotPermittedException;
use Zeusi\CircuitBreaker\Execution\CallbackOutcomeClassifier;
use Zeusi\CircuitBreaker\Execution\CallGate;
use Zeusi\CircuitBreaker\Outcome;
use Zeusi\CircuitBreaker\Tests\Fixtures\RecordingCircuitBreaker;

final class CallGateTest extends TestCase
{
    public function testAdmittedCallRegistersItsOutcomeOnce(): void
    {
        $circuitBreaker = new RecordingCircuitBreaker(permitsCalls: true);
        $attempt = (new CallGate($circuitBreaker, self::createClassifier()))->admit();

        // later, in whatever callback completes the call, with the duration known by the client
        $attempt->registerResult(503, 2.5);
        $attempt->registerException(new \RuntimeException('Connection reset'), 3.0);

        self::assertSame([[Outcome::Failure, 2.5]], $circuitBreaker->getOutcomes());
    }

    public function testUnknownDuration(): void
    {
        $circuitBreaker = new RecordingCircuitBreaker(permitsCalls: true);
        $attempt = (new CallGate($circuitBreaker, self::createClassifier()))->admit();

        $attempt->registerResult(200, null);

        self::assertSame([[Outcome::Success, null]], $circuitBreaker->getOutcomes());
    }

    public function testRejectedCall(): void
    {
        $circuitBreaker = new RecordingCircuitBreaker(permitsCalls: false);

        $this->expectException(CallNotPermittedException::class);

        (new CallGate($circuitBreaker, self::createClassifier()))->admit();
    }

    private static function createClassifier(): CallbackOutcomeClassifier
    {
        return new CallbackOutcomeClassifier(
            static fn(\Throwable $exception): Outcome => Outcome::Failure,
            static fn(mixed $result): Outcome => $result === 503 ? Outcome::Failure : Outcome::Success,
        );
    }
}
