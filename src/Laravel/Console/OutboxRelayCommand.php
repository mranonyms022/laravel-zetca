<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Console;

use CodingTriplets\Zatca\Laravel\Processing\Outbox\OutboxRelay;
use Illuminate\Console\Command;

final class OutboxRelayCommand extends Command
{
    protected $signature = 'zatca:outbox:relay
        {--daemon : Keep running (relay every --sleep seconds)}
        {--sleep=1 : Seconds between passes in daemon mode}
        {--limit=500 : Messages per pass}
        {--max-time=0 : Stop the daemon after this many seconds (0 = never)}';

    protected $description = 'Dispatch due outbox messages (submissions, retries) to the ZATCA queues';

    public function handle(OutboxRelay $relay): int
    {
        $started = time();
        do {
            $result = $relay->relay(max(1, (int) $this->option('limit')));
            if (! $this->option('daemon') || $this->output->isVerbose()) {
                $this->line("Dispatched {$result['dispatched']}, skipped {$result['skipped']}.");
            }
            if (! $this->option('daemon')) {
                break;
            }
            if ((int) $this->option('max-time') > 0 && time() - $started >= (int) $this->option('max-time')) {
                break;
            }
            if ($result['dispatched'] === 0) {
                sleep(max(1, (int) $this->option('sleep')));
            }
        } while (true);

        return self::SUCCESS;
    }
}
