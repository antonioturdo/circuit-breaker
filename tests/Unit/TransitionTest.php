<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\Exception\LogicException;
use Zeusi\CircuitBreaker\Opening\FailureRateRule;
use Zeusi\CircuitBreaker\Opening\OpeningCause;
use Zeusi\CircuitBreaker\State;
use Zeusi\CircuitBreaker\Storage\CircuitState;
use Zeusi\CircuitBreaker\Transition;

final class TransitionTest extends TestCase
{
    public function testOpeningHasItsCause(): void
    {
        $openingCause = new OpeningCause(new FailureRateRule(threshold: 50, minimumRequests: 10), 5, 10);
        $transition = Transition::createOpening(1_791_676_800_000, $openingCause);

        self::assertEquals(new CircuitState(State::Open, 1_791_676_800_000), $transition->targetState);
        self::assertSame($openingCause, $transition->getOpeningCause());
    }

    public function testOtherTransitionsHaveNoCause(): void
    {
        $this->expectException(LogicException::class);

        Transition::createClosing(1_791_676_800_000)->getOpeningCause();
    }
}
