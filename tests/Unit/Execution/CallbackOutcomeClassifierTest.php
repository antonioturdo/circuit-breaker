<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit\Execution;

use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\Execution\CallbackOutcomeClassifier;
use Zeusi\CircuitBreaker\Outcome;

final class CallbackOutcomeClassifierTest extends TestCase
{
    public function testClassifiesWithTheCallbacks(): void
    {
        $classifier = new CallbackOutcomeClassifier(
            static fn(\Throwable $exception): ?Outcome => $exception instanceof \RuntimeException ? Outcome::Failure : null,
            static fn(mixed $result): Outcome => $result === 503 ? Outcome::Failure : Outcome::Success,
        );

        self::assertSame(Outcome::Failure, $classifier->classifyException(new \RuntimeException('Connection refused')));
        self::assertNull($classifier->classifyException(new \LogicException('Invalid request')));
        self::assertSame(Outcome::Failure, $classifier->classifyResult(503));
        self::assertSame(Outcome::Success, $classifier->classifyResult(200));
    }

    public function testEveryResultIsASuccessWithoutAResultCallback(): void
    {
        $classifier = new CallbackOutcomeClassifier(static fn(\Throwable $exception): Outcome => Outcome::Failure);

        self::assertSame(Outcome::Success, $classifier->classifyResult(503));
    }
}
