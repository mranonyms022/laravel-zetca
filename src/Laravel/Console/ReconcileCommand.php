<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Console;

use CodingTriplets\Zatca\Laravel\Processing\Reconciler;
use Illuminate\Console\Command;

final class ReconcileCommand extends Command
{
    protected $signature = 'zatca:reconcile {--older-than=300 : Only work idle for at least this many seconds}';

    protected $description = 'Re-queue submissions and B2B generations that have no pending outbox message';

    public function handle(Reconciler $reconciler): int
    {
        $result = $reconciler->reconcile(max(30, (int) $this->option('older-than')));
        $this->line("Re-queued submissions: {$result['submissions']}, generations: {$result['generation']}.");

        return self::SUCCESS;
    }
}
