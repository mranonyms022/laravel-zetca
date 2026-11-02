<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Events;

use CodingTriplets\Zatca\Core\Contracts\Events\EventDispatcher;
use CodingTriplets\Zatca\Core\Contracts\Persistence\TransactionManager;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * After-commit dispatch that works on Laravel 9-14 (ShouldDispatchAfterCommit only exists from L10.x).
 */
final class LaravelEventDispatcher implements EventDispatcher
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly TransactionManager $transactions,
    ) {
    }

    public function dispatchAfterCommit(object $event): void
    {
        $this->transactions->afterCommit(fn () => $this->events->dispatch($event));
    }
}
