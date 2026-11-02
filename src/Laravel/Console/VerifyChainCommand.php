<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Console;

use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceCounter;
use CodingTriplets\Zatca\Laravel\Processing\ChainVerifier;
use Illuminate\Console\Command;

final class VerifyChainCommand extends Command
{
    protected $signature = 'zatca:chain:verify
        {--device= : Only this device ID}
        {--deep : Also re-hash every stored signed XML (slow)}';

    protected $description = 'Verify the ICV/PIH hash chain of every device (contiguity, linkage, counter head)';

    public function handle(ChainVerifier $verifier): int
    {
        $query = InvoiceCounter::query()->orderBy('device_id');
        if ($this->option('device')) {
            $query->where('device_id', (int) $this->option('device'));
        }

        $failed = 0;
        foreach ($query->get(['device_id', 'environment']) as $counter) {
            $environment = $counter->environment instanceof \BackedEnum ? (string) $counter->environment->value : (string) $counter->environment;
            $result = $verifier->verify((int) $counter->device_id, $environment, (bool) $this->option('deep'));
            if ($result['problems'] === []) {
                $this->line("Device #{$result['device_id']} ({$environment}): OK, {$result['entries']} documents.");

                continue;
            }
            $failed++;
            $this->error("Device #{$result['device_id']} ({$environment}): " . count($result['problems']) . ' problem(s)');
            foreach (array_slice($result['problems'], 0, 20) as $problem) {
                $this->line("  - {$problem}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
