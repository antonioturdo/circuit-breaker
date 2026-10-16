<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\Configuration;
use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;
use Zeusi\CircuitBreaker\HalfOpen\HalfOpenPolicyInterface;
use Zeusi\CircuitBreaker\HalfOpen\RampPolicy;

final class ConfigurationTest extends TestCase
{
    /**
     * @return iterable<string, array{float, int, int, float}>
     */
    public static function invalidConfigurationProvider(): iterable
    {
        yield 'time window below 10 seconds' => [9.999, 50, 10, 10];
        yield 'time window above 1 hour' => [3600.001, 50, 10, 10];
        yield 'failure rate threshold of 0' => [60, 0, 10, 10];
        yield 'failure rate threshold above 100' => [60, 101, 10, 10];
        yield 'minimum requests of 0' => [60, 50, 0, 10];
        yield 'open duration below 1 second' => [60, 50, 10, 0.999];
        yield 'open duration above 30 days' => [60, 50, 10, 2_592_000.001];
    }

    #[DataProvider('invalidConfigurationProvider')]
    public function testInvalidConfiguration(float $timeWindow, int $failureRateThreshold, int $minimumRequests, float $openDuration): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Configuration($timeWindow, $failureRateThreshold, $minimumRequests, $openDuration, RampPolicy::createExponential(60, 0.01));
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidHalfOpenMaxDurationProvider(): iterable
    {
        yield 'below 1 second' => [0.999];
        yield 'above 30 days' => [2_592_000.001];
    }

    #[DataProvider('invalidHalfOpenMaxDurationProvider')]
    public function testInvalidHalfOpenMaxDuration(float $maxDuration): void
    {
        // a policy of the application, which may not validate its duration
        $halfOpenPolicy = self::createStub(HalfOpenPolicyInterface::class);
        $halfOpenPolicy->method('getMaxDuration')->willReturn($maxDuration);

        $this->expectException(InvalidArgumentException::class);

        new Configuration(60, 50, 10, 10, $halfOpenPolicy);
    }
}
