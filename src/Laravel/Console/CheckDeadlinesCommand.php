<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Console;

use CodingTriplets\Zatca\Laravel\Processing\DeadlineMonitor;
use Illuminate\Console\Command;

final class CheckDeadlinesCommand extends Command
{
    protected $signature = 'zatca:deadlines:check';

    protected $description = 'Alert on B2C invoices close to (or past) the 24-hour reporting deadline';

    public function handle(DeadlineMonitor $monitor): int
    {
        $result = $monitor->check();
        $this->line("At risk: {$result['at_risk']}, breached: {$result['breached']}.");

        return self::SUCCESS;
    }
}
