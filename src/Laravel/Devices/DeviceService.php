<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Devices;

use CodingTriplets\Zatca\Core\Domain\Enums\Environment;
use CodingTriplets\Zatca\Core\Domain\Exceptions\ZatcaException;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\DeviceOnboarding;
use CodingTriplets\Zatca\Laravel\Devices\Import\DeviceImportReport;
use CodingTriplets\Zatca\Laravel\Devices\Import\SpreadsheetReader;

/**
 * Devices from code, one or many at a time: `Zatca::devices()`.
 *
 * Row fields (array keys, or CSV/Excel column headers):
 *   company_id   package company ID or the host app's company ID (external_ref)     required
 *   store_id     package store ID, store code or the host app's store ID            required unless VAT group
 *   name         device name / asset number (CSR common name); alias device_name    required
 *   invoice_types 1000 (B2B) | 0100 (B2C) | 1100 (both); alias types                default 1100
 *   industry     business category                                                  required
 *   location     branch address or website                                          required
 *   vat_number   when the company has several VAT registrations                     optional
 *   environment  sandbox | simulation | production                                  default: config
 *   serial       EGS serial "1-..|2-..|3-.."                                        optional
 *   otp          Fatoora portal OTP: onboard immediately (never stored or logged)  optional
 *
 * Registration is idempotent (same company, store, name and environment → existing device).
 * OTPs are only kept in memory for the duration of the call, so onboarding runs in the calling process.
 */
final class DeviceService
{
    public function __construct(
        private readonly DeviceRegistrar $registrar,
        private readonly CompanyService $companies,
        private readonly DeviceOnboardingService $onboarding,
        private readonly SpreadsheetReader $reader,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param array{ids?: 'auto'|'external'|'package'} $options
     */
    public function register(array $row, ?int $actorId = null, array $options = []): Device
    {
        return $this->registerRow($this->normalise($row), $options['ids'] ?? 'auto', $actorId)[0];
    }

    /**
     * Registers many devices; one bad row never stops the others. Rows with an `otp` are onboarded too,
     * unless $options['onboard'] is false.
     *
     * @param iterable<array<string, mixed>> $rows
     * @param array{ids?: 'auto'|'external'|'package', onboard?: bool, actor_id?: ?int} $options
     */
    public function registerMany(iterable $rows, array $options = []): DeviceImportReport
    {
        $report = new DeviceImportReport();
        $ids = $options['ids'] ?? 'auto';
        $actorId = $options['actor_id'] ?? null;
        $onboard = $options['onboard'] ?? true;
        $seen = [];

        foreach ($rows as $n => $raw) {
            $rowNo = is_int($n) ? $n : count($report->rows()) + 1;
            $row = $this->normalise((array) $raw);
            $otp = $row['otp'];
            unset($row['otp']);

            try {
                $key = strtolower(implode('|', [$row['company_id'], $row['store_id'], $row['name'], $row['environment']]));
                if (isset($seen[$key])) {
                    throw new \InvalidArgumentException("duplicate of row {$seen[$key]} in the same file");
                }
                $seen[$key] = $rowNo;

                [$device, $created] = $this->registerRow($row, $ids, $actorId);
                $report->add($rowNo, $created ? 'created' : 'existing', (int) $device->id, (string) $device->name);
            } catch (ZatcaException|\InvalidArgumentException|\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                $report->add($rowNo, 'failed', null, $row['name'] !== '' ? $row['name'] : null, [$e->getMessage()]);
                continue;
            }

            if ($onboard && $otp !== '') {
                $report->update($rowNo, ...$this->onboardOne($device, $otp, $actorId));
            }
            $otp = '';
        }

        return $report;
    }

    /**
     * Registers (and, with an `otp` column, onboards) the devices listed in a CSV/Excel file.
     *
     * @param string|array{disk: string, path: string} $file local path or a filesystem disk + path
     * @param array{ids?: 'auto'|'external'|'package', onboard?: bool, actor_id?: ?int} $options
     */
    public function import(string|array $file, array $options = []): DeviceImportReport
    {
        return $this->registerMany($this->reader->rows($file), $options);
    }

    /**
     * Checks rows without writing anything: required fields, company/store lookup, invoice types.
     *
     * @param iterable<array<string, mixed>>|string|array{disk: string, path: string} $rows rows, or a file
     * @param array{ids?: 'auto'|'external'|'package'} $options
     */
    public function validateMany(iterable|string $rows, array $options = []): DeviceImportReport
    {
        $rows = is_string($rows) || (is_array($rows) && isset($rows['disk'], $rows['path'])) ? $this->reader->rows($rows) : $rows;
        $report = new DeviceImportReport();
        foreach ($rows as $n => $raw) {
            $row = $this->normalise((array) $raw);
            $rowNo = is_int($n) ? $n : count($report->rows()) + 1;
            try {
                $this->resolve($row, $options['ids'] ?? 'auto');
                \CodingTriplets\Zatca\Core\Domain\ValueObjects\InvoiceTypeFlags::fromString($row['invoice_types']);
                foreach (['name', 'industry', 'location'] as $field) {
                    if ($row[$field] === '') {
                        throw new \InvalidArgumentException("{$field} is required");
                    }
                }
                $report->add($rowNo, 'valid', null, $row['name']);
            } catch (ZatcaException|\InvalidArgumentException|\ValueError $e) {
                $report->add($rowNo, 'failed', null, $row['name'] !== '' ? $row['name'] : null, [$e->getMessage()]);
            }
        }

        return $report;
    }

    /** Onboards one device with an OTP from the Fatoora portal. */
    public function onboard(int|string|Device $device, #[\SensitiveParameter] string $otp, ?int $actorId = null): DeviceOnboarding
    {
        return $this->onboarding->onboard($this->device($device), $otp, $actorId);
    }

    /**
     * Onboards many devices, one after another, in this process (OTPs are never queued or stored).
     *
     * @param iterable<array{device: int|string|Device, otp: string}> $items
     */
    public function onboardMany(iterable $items, ?int $actorId = null): DeviceImportReport
    {
        $report = new DeviceImportReport();
        $n = 0;
        foreach ($items as $item) {
            $n++;
            try {
                $device = $this->device($item['device'] ?? '');
            } catch (\Throwable $e) {
                $report->add($n, 'failed', null, null, ['device not found: ' . (is_scalar($item['device'] ?? null) ? (string) $item['device'] : '?')]);
                continue;
            }
            $report->add($n, 'existing', (int) $device->id, (string) $device->name);
            $report->update($n, ...$this->onboardOne($device, (string) ($item['otp'] ?? ''), $actorId));
        }

        return $report;
    }

    /** Continues an onboarding that stopped at the compliance checks (no OTP needed). */
    public function resume(int|string|Device $device, ?int $actorId = null): DeviceOnboarding
    {
        return $this->onboarding->resume($this->device($device), $actorId);
    }

    // ------------------------------------------------------------------ internals

    /**
     * @return array{0: string, 1: list<string>} status, errors
     */
    private function onboardOne(Device $device, #[\SensitiveParameter] string $otp, ?int $actorId): array
    {
        if (! preg_match('/^\d{6}$/', $otp)) {
            return ['onboarding_failed', ['OTP must be 6 digits']];
        }
        try {
            $result = $this->onboarding->onboard($device, $otp, $actorId);
        } catch (ZatcaException $e) {
            return ['onboarding_failed', [$e->getMessage()]];
        }

        return match ((string) $result->status) {
            'COMPLETED' => ['onboarded', []],
            'AWAITING_COMPLIANCE_CHECKS' => ['awaiting_compliance_checks', [(string) ($result->error ?? 'compliance checks failed; fix the cause and resume')]],
            default => ['onboarding_failed', [(string) ($result->error ?? 'onboarding failed')]],
        };
    }

    /**
     * @param array<string, string> $row normalised
     * @return array{0: Device, 1: bool} device, created
     */
    private function registerRow(array $row, string $ids, ?int $actorId): array
    {
        [$registration, $branch] = $this->resolve($row, $ids);
        $environment = Environment::from($row['environment']);

        $existing = Device::query()
            ->where('vat_registration_id', $registration->id)
            ->where('branch_id', $branch?->id)
            ->where('name', $row['name'])
            ->where('environment', $environment)
            ->first();
        if ($existing !== null) {
            return [$existing, false];
        }

        return [$this->registrar->registerDevice([
            'vat_registration_id' => (int) $registration->id,
            'branch_id' => $branch?->id,
            'name' => $row['name'],
            'invoice_type_flags' => $row['invoice_types'],
            'environment' => $environment->value,
            'industry' => $row['industry'],
            'location' => $row['location'],
            'serial_number' => $row['serial'] !== '' ? $row['serial'] : null,
        ], $actorId), true];
    }

    /**
     * @param array<string, string> $row
     * @return array{0: \CodingTriplets\Zatca\Laravel\Database\Models\VatRegistration, 1: ?\CodingTriplets\Zatca\Laravel\Database\Models\Branch}
     */
    private function resolve(array $row, string $ids): array
    {
        if ($row['company_id'] === '') {
            throw new \InvalidArgumentException('company_id is required');
        }
        $company = $this->companies->resolveCompany($row['company_id'], $ids);
        $registration = $this->companies->registrationFor($company, $row['vat_number'] !== '' ? $row['vat_number'] : null);
        $branch = $row['store_id'] !== '' ? $this->companies->resolveStore($company, $row['store_id'], $ids) : null;
        if ($branch === null && ! $registration->is_group) {
            throw new \InvalidArgumentException('store_id is required (the store name goes into the device certificate)');
        }

        return [$registration, $branch];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function normalise(array $row): array
    {
        $get = static function (array $keys) use ($row): string {
            foreach ($keys as $key) {
                if (isset($row[$key]) && is_scalar($row[$key]) && trim((string) $row[$key]) !== '') {
                    return trim((string) $row[$key]);
                }
            }

            return '';
        };
        $types = $get(['invoice_types', 'types', 'invoice_type_flags']);
        // Excel turns "0100" into 100: restore the 4-digit flags.
        if ($types !== '' && ctype_digit($types) && strlen($types) < 4) {
            $types = str_pad($types, 4, '0', STR_PAD_LEFT);
        }

        return [
            'company_id' => $get(['company_id', 'company', 'company_ref']),
            'store_id' => $get(['store_id', 'store', 'branch_id', 'branch', 'store_ref', 'store_code']),
            'name' => $get(['name', 'device_name', 'device']),
            'invoice_types' => $types !== '' ? $types : '1100',
            'industry' => $get(['industry', 'business_category']),
            'location' => $get(['location', 'address']),
            'vat_number' => $get(['vat_number', 'vat']),
            'environment' => strtolower($get(['environment', 'env'])) ?: (string) config('zatca.environment', 'sandbox'),
            'serial' => $get(['serial', 'serial_number', 'egs_serial']),
            'otp' => $get(['otp']),
        ];
    }

    private function device(int|string|Device $device): Device
    {
        if ($device instanceof Device) {
            return $device;
        }
        $ref = (string) $device;

        return ctype_digit($ref)
            ? Device::query()->findOrFail((int) $ref)
            : Device::query()->where('device_uuid', $ref)->orWhere('name', $ref)->firstOrFail();
    }
}
