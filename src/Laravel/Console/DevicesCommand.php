<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Console;

use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use Illuminate\Console\Command;

final class DevicesCommand extends Command
{
    protected $signature = 'zatca:devices {--status= : Filter by lifecycle status}';

    protected $description = 'List devices with their lifecycle status and certificate expiry';

    public function handle(): int
    {
        $query = Device::query()->with('vatRegistration')->orderBy('id');
        if ($this->option('status')) {
            $query->where('lifecycle_status', strtoupper((string) $this->option('status')));
        }

        $rows = $query->limit(1000)->get()->map(static fn (Device $d) => [
            $d->id,
            $d->name,
            $d->vatRegistration?->vat_number,
            $d->environment->value,
            $d->invoice_type_flags,
            $d->lifecycle_status->value,
            $d->certificate_expiry?->toDateString() ?? '-',
        ])->all();

        $this->table(['ID', 'Name', 'VAT', 'Environment', 'Types', 'Status', 'Cert expiry'], $rows);

        return self::SUCCESS;
    }
}
