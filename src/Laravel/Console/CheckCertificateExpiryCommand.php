<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Console;

use CodingTriplets\Zatca\Laravel\Devices\CertificateExpiryMonitor;
use Illuminate\Console\Command;

final class CheckCertificateExpiryCommand extends Command
{
    protected $signature = 'zatca:devices:check-expiry';

    protected $description = 'Mark devices whose certificates are expiring or expired, and raise warnings';

    public function handle(CertificateExpiryMonitor $monitor): int
    {
        $stats = $monitor->check();
        $this->info(sprintf(
            'Checked %d device(s): %d now due for renewal, %d expired, %d warning(s) raised.',
            $stats['checked'], $stats['renewal_due'], $stats['expired'], $stats['warnings'],
        ));

        return self::SUCCESS;
    }
}
