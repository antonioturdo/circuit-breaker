<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\Configuration;
use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;
use Zeusi\CircuitBreaker\HalfOpen\HalfOpenPolicyInterface;
use Zeusi\CircuitBreaker\HalfOpen\RampPolicy;
use Zeusi\CircuitBreaker\Opening\FailureRateRule;

final class ConfigurationTest extends TestCase
{
    /**
     * @return iterable<string, array{float, list<mixed>, float}>
     */
    public static function invalidConfigurationProvider(): iterable
    {
        $openingRules = [new FailureRateRule(threshold: 50, minimumRequests: 10)];

        yield 'time window below 10 seconds' => [9.999, $openingRules, 10];
        yield 'time window above 1 hour' => [3600.001, $openingRules, 10];
        yield 'no opening rule' => [60, [], 10];
        yield 'opening rule of another type' => [60, [...$openingRules, 50], 10];
        yield 'open duration below 1 second' => [60, $openingRules, 0.999];
        yield 'open duration above 1 hour' => [60, $openingRules, 3600.001];
    }

    /**
     * @param list<mixed> $openingRules
     */
    #[DataProvider('invalidConfigurationProvider')]
    public function testInvalidConfiguration(float $timeWindow, array $openingRules, float $openDuration): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Configuration($timeWindow, $openingRules, $openDuration, RampPolicy::createExponential(60, 0.01));
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidHalfOpenMaxDurationProvider(): iterable
    {
        yield 'below 1 second' => [0.999];
        yield 'above 1 hour' => [3600.001];
    }

    #[DataProvider('invalidHalfOpenMaxDurationProvider')]
    public function testInvalidHalfOpenMaxDuration(float $maxDuration): void
    {
        // a policy of the application, which may not validate its duration
        $halfOpenPolicy = self::createStub(HalfOpenPolicyInterface::class);
        $halfOpenPolicy->method('getMaxDuration')->willReturn($maxDuration);

        $this->expectException(InvalidArgumentException::class);

        new Configuration(60, [new FailureRateRule(threshold: 50, minimumRequests: 10)], 10, $halfOpenPolicy);
    }
}
