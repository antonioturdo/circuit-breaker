<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit\HalfOpen;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;
use Zeusi\CircuitBreaker\HalfOpen\ExponentialRampCurve;
use Zeusi\CircuitBreaker\HalfOpen\PermissionRequest;
use Zeusi\CircuitBreaker\HalfOpen\RampCurveInterface;
use Zeusi\CircuitBreaker\HalfOpen\RampPolicy;

final class RampPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{float, float, int, bool}>
     */
    public static function grantsPermissionProvider(): iterable
    {
        yield 'curve letting nothing through' => [60, 0.0, 30_000, false];
        yield 'curve letting everything through' => [60, 1.0, 30_000, true];
        yield 'last millisecond of a fractional ramp' => [1.5, 0.0, 1_499, false];
        yield 'end of the ramp, whatever the curve' => [1.5, 0.0, 1_500, true];
        yield 'after the end of the ramp' => [60, 0.0, 90_000, true];
    }

    #[DataProvider('grantsPermissionProvider')]
    public function testGrantsPermission(float $duration, float $ratio, int $elapsed, bool $grantsPermission): void
    {
        $curve = self::createStub(RampCurveInterface::class);
        $curve->method('computeAdmissionRatio')->willReturn($ratio);
        $policy = new RampPolicy($duration, $curve);

        self::assertSame($grantsPermission, $policy->grantsPermission(new PermissionRequest($elapsed, self::failCountingCall(...))));
    }

    /**
     * @return iterable<string, array{RampPolicy, int, int}>
     */
    public static function curveRatioProvider(): iterable
    {
        yield 'exponential from 25%, at the start' => [RampPolicy::createExponential(60, 0.25), 0, 2_500];
        yield 'exponential from 1%, half way' => [RampPolicy::createExponential(60, 0.01), 30_000, 1_000];
        yield 'linear from 0%, half way' => [RampPolicy::createLinear(60, 0.0), 30_000, 5_000];
        yield 'square from 0%, half way' => [RampPolicy::createPower(60, 0.0, 2.0), 30_000, 2_500];
    }

    #[DataProvider('curveRatioProvider')]
    public function testGrantsPermissionToTheCurveRatio(RampPolicy $policy, int $elapsed, int $expectedGranted): void
    {
        $granted = 0;

        for ($call = 1; $call <= 10_000; $call++) {
            if ($policy->grantsPermission(new PermissionRequest($elapsed, self::failCountingCall(...)))) {
                $granted++;
            }
        }

        // about 5 standard deviations at most: a random failure is practically impossible
        self::assertEqualsWithDelta($expectedGranted, $granted, 250);
    }

    public function testInvalidDuration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RampPolicy(0.999, new ExponentialRampCurve(0.01));
    }

    /**
     * Fails the test: the ramp decides on the elapsed time, without counting the call in the storage.
     */
    private static function failCountingCall(): int
    {
        self::fail('The ramp must not count the call');
    }
}
