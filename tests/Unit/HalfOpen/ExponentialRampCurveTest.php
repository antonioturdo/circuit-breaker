<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit\HalfOpen;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;
use Zeusi\CircuitBreaker\HalfOpen\ExponentialRampCurve;

final class ExponentialRampCurveTest extends TestCase
{
    /**
     * @return iterable<string, array{float, float, float}>
     */
    public static function admissionRatioProvider(): iterable
    {
        yield 'start' => [0.01, 0.0, 0.01];
        yield 'half' => [0.01, 0.5, 0.1];
        yield 'end' => [0.01, 1.0, 1.0];
        yield 'half with custom initial ratio' => [0.25, 0.5, 0.5];
        yield 'initial ratio of 1' => [1.0, 0.0, 1.0];
    }

    #[DataProvider('admissionRatioProvider')]
    public function testAdmissionRatio(float $initialRatio, float $progress, float $expected): void
    {
        $curve = new ExponentialRampCurve($initialRatio);

        self::assertEqualsWithDelta($expected, $curve->computeAdmissionRatio($progress), 1e-9);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidInitialRatioProvider(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-0.1];
        yield 'above 1' => [1.1];
    }

    #[DataProvider('invalidInitialRatioProvider')]
    public function testInvalidInitialRatio(float $initialRatio): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExponentialRampCurve($initialRatio);
    }
}
