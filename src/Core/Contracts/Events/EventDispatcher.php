<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Contracts\Events;

interface EventDispatcher
{
    /** Dispatches after the surrounding transaction commits (or immediately if none). */
    public function dispatchAfterCommit(object $event): void;
}
