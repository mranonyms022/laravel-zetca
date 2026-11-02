<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Integration;

use CodingTriplets\Zatca\Core\Contracts\Events\EventDispatcher;
use CodingTriplets\Zatca\Core\Contracts\Locks\AdvisoryLock;
use CodingTriplets\Zatca\Core\Contracts\Persistence\TransactionManager;
use CodingTriplets\Zatca\Core\Contracts\Tenancy\TenantResolver;
use CodingTriplets\Zatca\Core\Domain\Enums\Environment;
use CodingTriplets\Zatca\Core\Domain\Exceptions\SequenceException;
use CodingTriplets\Zatca\Laravel\Database\Models\Company;
use CodingTriplets\Zatca\Laravel\Facades\Zatca;
use Illuminate\Support\Facades\Event;

final class ServiceProviderTest extends IntegrationTestCase
{
    public function test_core_contracts_are_bound(): void
    {
        foreach ([TransactionManager::class, AdvisoryLock::class, EventDispatcher::class, TenantResolver::class] as $contract) {
            self::assertInstanceOf($contract, $this->app->make($contract));
        }
    }

    public function test_facade_exposes_default_environment(): void
    {
        self::assertSame(Environment::Sandbox, Zatca::defaultEnvironment());
    }

    public function test_events_are_dispatched_only_after_commit(): void
    {
        Event::fake();
        $tx = $this->app->make(TransactionManager::class);
        $events = $this->app->make(EventDispatcher::class);

        $tx->run(function () use ($events): void {
            $events->dispatchAfterCommit(new \stdClass());
            Event::assertNotDispatched(\stdClass::class);
        });

        Event::assertDispatched(\stdClass::class);
    }

    public function test_events_are_dropped_when_the_transaction_rolls_back(): void
    {
        Event::fake();
        $tx = $this->app->make(TransactionManager::class);
        $events = $this->app->make(EventDispatcher::class);

        try {
            $tx->run(function () use ($events): void {
                $events->dispatchAfterCommit(new \stdClass());
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        Event::assertNotDispatched(\stdClass::class);
    }

    public function test_retryable_sequence_conflicts_rerun_the_unit_of_work(): void
    {
        $tx = $this->app->make(TransactionManager::class);
        $calls = 0;

        $result = $tx->run(function () use (&$calls): string {
            $calls++;
            if ($calls < 3) {
                throw SequenceException::counterConflict(1, 'sandbox');
            }

            return 'issued';
        }, attempts: 3);

        self::assertSame('issued', $result);
        self::assertSame(3, $calls);
    }

    public function test_non_retryable_sequence_errors_are_not_rerun(): void
    {
        $tx = $this->app->make(TransactionManager::class);
        $calls = 0;

        try {
            $tx->run(function () use (&$calls): void {
                $calls++;
                throw SequenceException::chainIntegrityViolation(1, 'sandbox', 'gap at ICV 7');
            }, attempts: 3);
            self::fail('Expected exception');
        } catch (SequenceException $e) {
            self::assertFalse($e->isRetryable());
        }

        self::assertSame(1, $calls);
    }

    public function test_nested_units_of_work_are_never_retried_on_their_own(): void
    {
        $tx = $this->app->make(TransactionManager::class);
        $calls = 0;

        try {
            $tx->run(function () use ($tx, &$calls): void {
                $tx->run(function () use (&$calls): void {
                    $calls++;
                    throw SequenceException::counterConflict(1, 'sandbox');
                }, attempts: 3);
            });
        } catch (SequenceException) {
        }

        self::assertSame(1, $calls);
    }

    public function test_a_failed_unit_of_work_leaves_no_rows(): void
    {
        $tx = $this->app->make(TransactionManager::class);

        try {
            $tx->run(function (): void {
                Company::create(['name_en' => 'Ghost']);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(0, Company::query()->where('name_en', 'Ghost')->count());
    }
}
