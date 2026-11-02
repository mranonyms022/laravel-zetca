<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Console;

use CodingTriplets\Zatca\Core\Domain\Exceptions\ZatcaException;
use CodingTriplets\Zatca\Laravel\Devices\DeviceService;
use Illuminate\Console\Command;

final class ImportDevicesCommand extends Command
{
    protected $signature = 'zatca:devices:import
        {file : CSV or Excel file (columns: company_id, store_id, name, invoice_types, industry, location, [vat_number, environment, serial, otp])}
        {--disk= : Read the file from this filesystem disk instead of a local path}
        {--ids=auto : How company_id/store_id are matched: auto (your own IDs first, then package IDs) | external | package}
        {--validate : Only check the rows; register nothing}
        {--no-onboard : Ignore an otp column}
        {--actor= : User ID recorded as the actor}';

    protected $description = 'Register (and with an otp column, onboard) devices from a CSV or Excel file';

    public function handle(DeviceService $devices): int
    {
        $file = (string) $this->argument('file');
        $source = $this->option('disk') ? ['disk' => (string) $this->option('disk'), 'path' => $file] : $file;
        $ids = (string) $this->option('ids');
        if (! in_array($ids, ['auto', 'external', 'package'], true)) {
            $this->error('--ids must be auto, external or package.');

            return self::INVALID;
        }

        try {
            $report = $this->option('validate')
                ? $devices->validateMany($source, ['ids' => $ids])
                : $devices->import($source, [
                    'ids' => $ids,
                    'onboard' => ! $this->option('no-onboard'),
                    'actor_id' => $this->option('actor') !== null ? (int) $this->option('actor') : null,
                ]);
        } catch (ZatcaException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Row', 'Status', 'Device', 'Name', 'Errors'], array_map(static fn (array $r) => [
            $r['row'], $r['status'], $r['device_id'] ?? '', $r['name'] ?? '', implode('; ', $r['errors']),
        ], $report->rows()));
        $this->line(collect($report->counts())->map(static fn ($n, $s) => "{$s}: {$n}")->implode(', '));

        return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
