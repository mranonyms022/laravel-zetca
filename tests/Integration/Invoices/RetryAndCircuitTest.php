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

    public function test_half_open_lets_exactly_one_probe_through_and_a_failed_probe_doubles_the_open_period(): void
    {
        $cache = new Repository(new ArrayStore());
        $circuit = new CircuitBreaker($cache, threshold: 1, openSeconds: 30, maxOpenSeconds: 100);

        $circuit->failure('env:production');
        self::assertGreaterThan(25, $circuit->retryAfter('env:production'));

        // Failures of requests that were already in flight do not escalate.
        $circuit->failure('env:production');
        self::assertLessThanOrEqual(30, $circuit->retryAfter('env:production'));

        $cache->put('zatca:circuit:env:production:open_until', time() - 1, 3600);   // open period over
        self::assertSame(0, $circuit->retryAfter('env:production'), 'the probe');
        self::assertGreaterThan(0, $circuit->retryAfter('env:production'), 'everyone else waits for the probe');

        $circuit->failure('env:production');   // probe failed
        $wait = $circuit->retryAfter('env:production');
        self::assertGreaterThan(55, $wait);
        self::assertLessThanOrEqual(60, $wait);

        $cache->put('zatca:circuit:env:production:open_until', time() - 1, 3600);
        $circuit->retryAfter('env:production');
        $circuit->failure('env:production');
        self::assertLessThanOrEqual(100, $circuit->retryAfter('env:production'), 'capped at max_open_seconds');

        $cache->put('zatca:circuit:env:production:open_until', time() - 1, 3600);
        self::assertSame(0, $circuit->retryAfter('env:production'));
        $circuit->success('env:production');   // probe succeeded
        self::assertSame(0, $circuit->retryAfter('env:production'));
        self::assertSame(0, $circuit->retryAfter('env:production'), 'closed: no probe limit any more');
    }

    public function test_429_opens_at_once_for_at_least_retry_after(): void
    {
        $circuit = new CircuitBreaker(new Repository(new ArrayStore()), threshold: 5, openSeconds: 30);

        $circuit->throttled('env:production', 300);
        self::assertGreaterThan(295, $circuit->retryAfter('env:production'));

        $circuit->throttled('env:simulation');
        self::assertGreaterThan(25, $circuit->retryAfter('env:simulation'));
    }

    public function test_outage_retries_ignore_max_attempts_and_stop_at_the_b2c_deadline(): void
    {
        config(['zatca.retry.b2c' => ['max_attempts' => 2, 'backoff_seconds' => [30, 600, 3600]], 'zatca.retry.outage.max_delay_seconds' => 900]);
        $policy = new RetryPolicy();
        $now = new \DateTimeImmutable('2026-11-02 10:00:00');
        $deadline = $now->modify('+24 hours');

        self::assertNull($policy->delay(Workflow::B2C, 2, $deadline, $now), 'unknown outcomes: limited');

        $delay = $policy->outageDelay(Workflow::B2C, 50, $deadline, $now, $now->modify('+20 hours'));
        self::assertNotNull($delay, 'outage: 50 tries, still retried');
        self::assertLessThanOrEqual(900, $delay, 'capped so the backlog goes out soon after ZATCA is back');

        self::assertLessThanOrEqual(59, $policy->outageDelay(Workflow::B2C, 50, $deadline, $now, $deadline->modify('-60 seconds')), 'never past the deadline');
        self::assertNull($policy->outageDelay(Workflow::B2C, 50, $deadline, $now, $deadline));
    }

    public function test_b2b_outage_window_counts_from_the_first_attempt(): void
    {
        config(['zatca.retry.outage.b2b_window_hours' => 24]);
        $policy = new RetryPolicy();
        $first = new \DateTimeImmutable('2026-11-02 10:00:00');

        self::assertNotNull($policy->outageDelay(Workflow::B2B, 30, null, $first, $first->modify('+23 hours')));
        self::assertNull($policy->outageDelay(Workflow::B2B, 30, null, $first, $first->modify('+24 hours')));
    }
}
