<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Console;

use CodingTriplets\Zatca\Laravel\Processing\Outbox\OutboxRelay;
use Illuminate\Console\Command;

final class OutboxPruneCommand extends Command
{
    protected $signature = 'zatca:outbox:prune {--days= : Keep processed messages this many days (default: zatca.retention.outbox_processed_days)}';

    protected $description = 'Delete processed outbox messages (dead letters are kept)';

    public function handle(OutboxRelay $relay): int
    {
        $days = (int) ($this->option('days') ?: config('zatca.retention.outbox_processed_days', 14));
        $this->info('Deleted ' . $relay->prune(max(1, $days)) . " processed outbox messages older than {$days} days.");

        return self::SUCCESS;
    }
}
