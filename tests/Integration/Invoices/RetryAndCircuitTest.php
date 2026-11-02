<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Integration\Invoices;

use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Laravel\Processing\Submission\CircuitBreaker;
use CodingTriplets\Zatca\Laravel\Processing\Submission\RetryPolicy;
use CodingTriplets\Zatca\Tests\Integration\IntegrationTestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

final class RetryAndCircuitTest extends IntegrationTestCase
{
    public function test_backoff_follows_the_schedule_with_jitter_and_stops_when_exhausted(): void
    {
        config(['zatca.retry.b2b' => ['max_attempts' => 3, 'backoff_seconds' => [100, 1000]]]);
        $policy = new RetryPolicy();

        for ($i = 0; $i < 50; $i++) {
            $first = $policy->delay(Workflow::B2B, 1);
            self::assertGreaterThanOrEqual(50, $first);
            self::assertLessThanOrEqual(100, $first);
            $second = $policy->delay(Workflow::B2B, 2);
            self::assertGreaterThanOrEqual(500, $second);
            self::assertLessThanOrEqual(1000, $second);
        }
        self::assertNull($policy->delay(Workflow::B2B, 3));
    }

    public function test_b2c_retries_are_compressed_to_finish_before_the_deadline_margin(): void
    {
        config(['zatca.retry.b2c' => ['max_attempts' => 5, 'backoff_seconds' => [36000]], 'zatca.retry.b2c_deadline_margin_hours' => 4]);
        $now = new \DateTimeImmutable('2026-11-02 10:00:00');
        $deadline = $now->modify('+6 hours');   // 2 h of room before the margin, 4 attempts left

        $delay = (new RetryPolicy())->delay(Workflow::B2C, 1, $deadline, $now);

        self::assertLessThanOrEqual(1800, $delay);
        self::assertGreaterThan(0, $delay);
    }

    public function test_circuit_opens_after_consecutive_failures_and_closes_on_success(): void
    {
        $circuit = new CircuitBreaker(new Repository(new ArrayStore()), threshold: 3, openSeconds: 60);

        $circuit->failure('env:sandbox');
        $circuit->failure('env:sandbox');
        self::assertSame(0, $circuit->retryAfter('env:sandbox'));
        $circuit->failure('env:sandbox');
        self::assertGreaterThan(50, $circuit->retryAfter('env:sandbox'));
        self::assertSame(0, $circuit->retryAfter('env:production'), 'circuits are per environment');

        $circuit->success('env:sandbox');
        self::assertSame(0, $circuit->retryAfter('env:sandbox'));
    }
}
