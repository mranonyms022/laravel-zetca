<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Devices;

use CodingTriplets\Zatca\Core\Contracts\Persistence\TransactionManager;
use CodingTriplets\Zatca\Core\Domain\Enums\DeviceLifecycleStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\Environment;
use CodingTriplets\Zatca\Core\Domain\Exceptions\InvalidValue;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\EgsSerialNumber;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\InvoiceTypeFlags;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\Tin;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\VatNumber;
use CodingTriplets\Zatca\Laravel\Audit\AuditLogger;
use CodingTriplets\Zatca\Laravel\Database\Models\Branch;
use CodingTriplets\Zatca\Laravel\Database\Models\Company;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\VatRegistration;
use Illuminate\Support\Str;

/** Registers taxpayers, branches and EGS devices (before onboarding). */
final class DeviceRegistrar
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array{name_en: string, name_ar?: ?string, legal_name: string, vat_number: string,
     *              group_member_tin?: ?string, crn?: ?string, address?: ?array<string, mixed>,
     *              seller_id_scheme?: ?string, seller_id_value?: ?string} $data
     */
    public function registerTaxpayer(array $data, ?int $actorId = null): VatRegistration
    {
        $vat = VatNumber::fromString($data['vat_number']);
        $tin = trim((string) ($data['group_member_tin'] ?? ''));

        if ($vat->isGroup() && $tin === '') {
            throw InvalidValue::for('group_member_tin', 'is required for a VAT group number (11th digit = 1)');
        }
        if (! $vat->isGroup() && $tin !== '') {
            throw InvalidValue::for('group_member_tin', 'is only allowed for VAT group numbers (11th digit = 1)');
        }
        if ($tin !== '') {
            Tin::fromString($tin, 'group_member_tin');
        }

        $registration = $this->transactions->run(function () use ($data, $vat, $tin): VatRegistration {
            $company = Company::create([
                'name_en' => $data['name_en'],
                'name_ar' => $data['name_ar'] ?? null,
                'crn' => $data['crn'] ?? null,
                'address' => $data['address'] ?? null,
            ]);

            return VatRegistration::create([
                'company_id' => $company->id,
                'vat_number' => $vat->value,
                'is_group' => $vat->isGroup(),
                'group_member_tin' => $tin,
                'legal_name' => $data['legal_name'],
                'address' => $data['address'] ?? null,
                'seller_id_scheme' => $data['seller_id_scheme'] ?? (isset($data['crn']) ? 'CRN' : null),
                'seller_id_value' => $data['seller_id_value'] ?? ($data['crn'] ?? null),
            ]);
        });

        $this->audit->record('taxpayer.registered', 'vat_registration', (int) $registration->id, [
            'vat_number' => $vat->value, 'is_group' => $vat->isGroup(),
        ], $actorId);

        return $registration;
    }

    public function registerBranch(int $companyId, string $code, string $name, ?array $addressOverride = null): Branch
    {
        return Branch::create(['company_id' => $companyId, 'code' => $code, 'name' => $name, 'address_override' => $addressOverride]);
    }

    /**
     * @param array{vat_registration_id: int, branch_id?: ?int, name: string, invoice_type_flags: string,
     *              environment?: string, industry: string, location: string, serial_number?: ?string} $data
     */
    public function registerDevice(array $data, ?int $actorId = null): Device
    {
        $registration = VatRegistration::query()->findOrFail($data['vat_registration_id']);
        $flags = InvoiceTypeFlags::fromString($data['invoice_type_flags']);
        $environment = Environment::from($data['environment'] ?? (string) config('zatca.environment'));

        if (trim($data['name']) === '') {
            throw InvalidValue::for('name', 'is required (CSR common name: device name or asset number)');
        }
        foreach (['industry', 'location'] as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                throw InvalidValue::for($field, 'is required for the CSR');
            }
        }

        $branchId = $data['branch_id'] ?? null;
        if (! $registration->is_group && $branchId === null) {
            throw InvalidValue::for('branch_id', 'is required: the branch name is the CSR organization unit');
        }
        if ($branchId !== null) {
            $branch = Branch::query()->findOrFail($branchId);
            if ((int) $branch->company_id !== (int) $registration->company_id) {
                throw InvalidValue::for('branch_id', 'belongs to a different company');
            }
        }

        $uuid = (string) Str::uuid();
        $serial = isset($data['serial_number']) && $data['serial_number'] !== null && $data['serial_number'] !== ''
            ? EgsSerialNumber::fromString($data['serial_number'])
            : EgsSerialNumber::compose(
                (string) config('zatca.csr.solution_provider'),
                (string) config('zatca.csr.solution_model'),
                $uuid,
            );

        $device = Device::create([
            'company_id' => $registration->company_id,
            'vat_registration_id' => $registration->id,
            'branch_id' => $branchId,
            'device_uuid' => $uuid,
            'name' => trim($data['name']),
            'serial_number' => $serial->value,
            'invoice_type_flags' => $flags->value,
            'environment' => $environment,
            'industry' => trim($data['industry']),
            'location' => trim($data['location']),
            'lifecycle_status' => DeviceLifecycleStatus::Registered,
        ]);

        $this->audit->record('device.registered', 'device', (int) $device->id, [
            'name' => $device->name, 'environment' => $environment, 'invoice_type_flags' => $flags->value,
        ], $actorId);

        return $device;
    }
}
