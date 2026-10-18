<?php

declare(strict_types=1);

namespace Zeusi\CircuitBreaker\Tests\Unit\HalfOpen;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zeusi\CircuitBreaker\Exception\InvalidArgumentException;
use Zeusi\CircuitBreaker\HalfOpen\HalfOpenProgress;
use Zeusi\CircuitBreaker\HalfOpen\PermissionRequest;
use Zeusi\CircuitBreaker\HalfOpen\PermittedCallsPolicy;

final class PermittedCallsPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{int, PermissionRequest, HalfOpenProgress, bool, bool}>
     */
    public static function policyProvider(): iterable
    {
        yield 'single trial, first call' => [1, new PermissionRequest(500, static fn(): int => 1), new HalfOpenProgress(0), true, false];
        yield 'single trial, second call' => [1, new PermissionRequest(500, static fn(): int => 2), new HalfOpenProgress(0), false, false];
        yield 'single trial reported back' => [1, new PermissionRequest(500, static fn(): int => 2), new HalfOpenProgress(1), false, true];
        yield 'single trial lost' => [1, new PermissionRequest(60_000, static fn(): int => 5), new HalfOpenProgress(0), false, false];
        yield 'last permitted call' => [3, new PermissionRequest(2_000, static fn(): int => 3), new HalfOpenProgress(2), true, false];
        yield 'beyond the permitted calls' => [3, new PermissionRequest(2_000, static fn(): int => 4), new HalfOpenProgress(2), false, false];
        yield 'all permitted calls reported back' => [3, new PermissionRequest(2_000, static fn(): int => 4), new HalfOpenProgress(3), false, true];
    }

    #[DataProvider('policyProvider')]
    public function testPolicy(int $permittedCalls, PermissionRequest $request, HalfOpenProgress $progress, bool $grantsPermission, bool $allowsEarlyClosing): void
    {
        $policy = new PermittedCallsPolicy(10, $permittedCalls);

        self::assertSame($grantsPermission, $policy->grantsPermission($request));
        self::assertSame($allowsEarlyClosing, $policy->allowsEarlyClosing($progress));
    }

    /**
     * @return iterable<string, array{float, int}>
     */
    public static function invalidPolicyProvider(): iterable
    {
        yield 'maximum duration below 1 second' => [0.999, 1];
        yield 'no permitted call' => [10, 0];
    }

    #[DataProvider('invalidPolicyProvider')]
    public function testInvalidPolicy(float $maxDuration, int $permittedCalls): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PermittedCallsPolicy($maxDuration, $permittedCalls);
    }
}
