<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Console;

use CodingTriplets\Zatca\Laravel\Audit\AuditLogger;
use Illuminate\Console\Command;

final class VerifyAuditCommand extends Command
{
    protected $signature = 'zatca:audit:verify';

    protected $description = 'Verify the hash chain of the audit log (detects modified or deleted entries)';

    public function handle(AuditLogger $audit): int
    {
        $result = $audit->verifyChain();

        if ($result['valid']) {
            $this->info("Audit chain intact ({$result['checked']} entries).");

            return self::SUCCESS;
        }

        $this->error("Audit chain broken at entry #{$result['broken_at']} (after {$result['checked']} valid entries).");

        return self::FAILURE;
    }
}
