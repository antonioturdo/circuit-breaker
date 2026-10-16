<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit\HalfOpen;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;
use Zeusi\CircuitBreaker\HalfOpen\PowerRampCurve;

final class PowerRampCurveTest extends TestCase
{
    /**
     * @return iterable<string, array{float, float, float, float}>
     */
    public static function admissionRatioProvider(): iterable
    {
        yield 'linear, start' => [0.01, 1.0, 0.0, 0.01];
        yield 'linear, half' => [0.01, 1.0, 0.5, 0.505];
        yield 'linear, end' => [0.01, 1.0, 1.0, 1.0];
        yield 'linear from zero, quarter' => [0.0, 1.0, 0.25, 0.25];
        yield 'square from zero, start' => [0.0, 2.0, 0.0, 0.0];
        yield 'square from zero, half' => [0.0, 2.0, 0.5, 0.25];
        yield 'square, end' => [0.01, 2.0, 1.0, 1.0];
        yield 'square root from zero, quarter' => [0.0, 0.5, 0.25, 0.5];
        yield 'initial ratio of 1' => [1.0, 2.0, 0.0, 1.0];
    }

    #[DataProvider('admissionRatioProvider')]
    public function testAdmissionRatio(float $initialRatio, float $exponent, float $progress, float $expected): void
    {
        $curve = new PowerRampCurve($initialRatio, $exponent);

        self::assertEqualsWithDelta($expected, $curve->computeAdmissionRatio($progress), 1e-9);
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function invalidParameterProvider(): iterable
    {
        yield 'negative initial ratio' => [-0.1, 1.0];
        yield 'initial ratio above 1' => [1.1, 1.0];
        yield 'exponent of 0' => [0.01, 0.0];
        yield 'negative exponent' => [0.01, -1.0];
    }

    #[DataProvider('invalidParameterProvider')]
    public function testInvalidParameter(float $initialRatio, float $exponent): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PowerRampCurve($initialRatio, $exponent);
    }
}
