<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Devices;

use CodingTriplets\Zatca\Core\Contracts\Persistence\TransactionManager;
use CodingTriplets\Zatca\Core\Domain\Enums\CertificateType;
use CodingTriplets\Zatca\Core\Domain\Enums\DeviceLifecycleStatus as S;
use CodingTriplets\Zatca\Core\Domain\Exceptions\DeviceStateException;
use CodingTriplets\Zatca\Core\Domain\Exceptions\InvalidValue;
use CodingTriplets\Zatca\Laravel\Audit\AuditLogger;
use CodingTriplets\Zatca\Laravel\Database\Models\Branch;
use CodingTriplets\Zatca\Laravel\Database\Models\ChainEntry;
use CodingTriplets\Zatca\Laravel\Database\Models\Company;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceIssuance;
use CodingTriplets\Zatca\Laravel\Database\Models\VatRegistration;

/**
 * Edit / disable / enable / delete for companies, stores and devices (dashboard and `Zatca::organisation()`).
 *
 * Legal records are never erased: a company or store with devices cannot be deleted, and a device that has
 * issued documents or holds a production certificate is decommissioned instead of deleted (6-year retention).
 * A disabled company or store refuses new invoices on all its devices; a disabled device is SUSPENDED.
 */
final class OrganisationManager
{
    public const ACTIVE = 'active';
    public const DISABLED = 'disabled';

    /** Statuses in which the CSR fields (name, store, industry, location) can still be changed. */
    public const EDITABLE_DEVICE_STATUSES = [S::Registered, S::OnboardingFailed];

    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly AuditLogger $audit,
    ) {
    }

    // ------------------------------------------------------------------ companies

    /**
     * @param array{name?: ?string, name_ar?: ?string, legal_name?: ?string, crn?: ?string, external_ref?: ?string,
     *              address?: ?array<string, mixed>} $data
     */
    public function updateCompany(Company $company, array $data, ?int $actorId = null): Company
    {
        $name = trim((string) ($data['name'] ?? $company->name_en));
        if ($name === '') {
            throw InvalidValue::for('name', 'is required');
        }
        $ref = $this->clean($data['external_ref'] ?? null);
        if ($ref !== null && Company::query()->where('external_ref', $ref)->whereKeyNot($company->id)->exists()) {
            throw InvalidValue::for('external_ref', "is already used by another company ({$ref})");
        }
        $registrations = VatRegistration::query()->where('company_id', $company->id)->get();
        $legalName = $this->clean($data['legal_name'] ?? null);
        if ($legalName === null && $registrations->count() === 1) {
            throw InvalidValue::for('legal_name', 'is required (taxpayer legal name, printed on every invoice)');
        }

        $address = $this->address($data['address'] ?? null);
        $before = $company->only(['name_en', 'name_ar', 'crn', 'external_ref']);

        $this->transactions->run(function () use ($company, $data, $name, $ref, $address, $registrations, $legalName): void {
            $company->update([
                'name_en' => $name,
                'name_ar' => $this->clean($data['name_ar'] ?? null),
                'crn' => $this->clean($data['crn'] ?? null),
                'external_ref' => $ref,
                'address' => $address !== null ? array_merge((array) $company->address, $address) : $company->address,
            ]);
            foreach ($registrations as $registration) {
                $changes = $address !== null ? ['address' => array_merge((array) $registration->address, $address)] : [];
                if ($registrations->count() === 1 && $legalName !== null) {
                    $changes['legal_name'] = $legalName;
                }
                if ($changes !== []) {
                    $registration->update($changes);
                }
            }
        });

        $this->audit->record('company.updated', 'company', (int) $company->id, ['before' => $before, 'after' => $company->only(array_keys($before))], $actorId);

        return $company->refresh();
    }

    public function setCompanyEnabled(Company $company, bool $enabled, ?int $actorId = null): Company
    {
        $company->update(['status' => $enabled ? self::ACTIVE : self::DISABLED]);
        $this->audit->record($enabled ? 'company.enabled' : 'company.disabled', 'company', (int) $company->id, [], $actorId);

        return $company;
    }

    /** Soft-deletes the company and its stores. Refused while any device (even decommissioned) belongs to it. */
    public function deleteCompany(Company $company, ?int $actorId = null): void
    {
        if (Device::query()->where('company_id', $company->id)->exists()) {
            throw InvalidValue::for('company', 'still has devices: delete or decommission them first (decommissioned devices keep the company for the 6-year retention)');
        }
        if (Invoice::query()->where('company_id', $company->id)->exists()) {
            throw InvalidValue::for('company', 'has invoices and must be kept for the retention period; disable it instead');
        }

        $this->transactions->run(static function () use ($company): void {
            Branch::query()->where('company_id', $company->id)->get()->each->delete();
            VatRegistration::query()->where('company_id', $company->id)->update(['status' => 'deleted']);
            $company->delete();
        });
        $this->audit->record('company.deleted', 'company', (int) $company->id, ['name' => $company->name_en], $actorId);
    }

    // ------------------------------------------------------------------ stores

    /** @param array{code?: ?string, name?: ?string, external_ref?: ?string} $data */
    public function updateStore(Branch $store, array $data, ?int $actorId = null): Branch
    {
        $code = trim((string) ($data['code'] ?? $store->code));
        $name = trim((string) ($data['name'] ?? $store->name));
        if ($code === '') {
            throw InvalidValue::for('code', 'is required (unique store code within the company)');
        }
        if ($name === '') {
            throw InvalidValue::for('name', 'is required');
        }
        $siblings = static fn () => Branch::query()->withTrashed()->where('company_id', $store->company_id)->whereKeyNot($store->id);
        if ($siblings()->where('code', $code)->exists()) {
            throw InvalidValue::for('code', "is already used by another store of this company ({$code})");
        }
        $ref = $this->clean($data['external_ref'] ?? null);
        if ($ref !== null && $siblings()->where('external_ref', $ref)->exists()) {
            throw InvalidValue::for('external_ref', "is already used by another store of this company ({$ref})");
        }

        $before = $store->only(['code', 'name', 'external_ref']);
        $store->update(['code' => $code, 'name' => $name, 'external_ref' => $ref]);
        $this->audit->record('store.updated', 'branch', (int) $store->id, ['before' => $before, 'after' => $store->only(array_keys($before))], $actorId);

        return $store;
    }

    public function setStoreEnabled(Branch $store, bool $enabled, ?int $actorId = null): Branch
    {
        $store->update(['status' => $enabled ? self::ACTIVE : self::DISABLED]);
        $this->audit->record($enabled ? 'store.enabled' : 'store.disabled', 'branch', (int) $store->id, [], $actorId);

        return $store;
    }

    public function deleteStore(Branch $store, ?int $actorId = null): void
    {
        if (Device::query()->where('branch_id', $store->id)->exists()) {
            throw InvalidValue::for('store', 'still has devices: move, delete or decommission them first');
        }
        if (Invoice::query()->where('branch_id', $store->id)->exists()) {
            throw InvalidValue::for('store', 'has invoices and must be kept for the retention period; disable it instead');
        }

        $store->delete();
        $this->audit->record('store.deleted', 'branch', (int) $store->id, ['code' => $store->code, 'name' => $store->name], $actorId);
    }

    // ------------------------------------------------------------------ devices

    public function deviceIsEditable(Device $device): bool
    {
        return in_array($device->lifecycle_status, self::EDITABLE_DEVICE_STATUSES, true);
    }

    /**
     * Name, store, industry and location go into the CSR, so they can only change before onboarding.
     *
     * @param array{name?: ?string, store_id?: int|string|null, industry?: ?string, location?: ?string} $data
     */
    public function updateDevice(Device $device, array $data, ?int $actorId = null): Device
    {
        if (! $this->deviceIsEditable($device)) {
            throw DeviceStateException::notAllowed((int) $device->id, 'be edited', $device->lifecycle_status->value,
                'Name, store, industry and location are in the certificate; register a new device instead.');
        }

        $changes = [];
        foreach (['name', 'industry', 'location'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = trim((string) $data[$field]);
                if ($value === '') {
                    throw InvalidValue::for($field, 'is required for the CSR');
                }
                $changes[$field] = $value;
            }
        }
        if (array_key_exists('store_id', $data)) {
            $storeId = $this->clean($data['store_id']);
            $registration = VatRegistration::query()->findOrFail($device->vat_registration_id);
            if ($storeId === null && ! $registration->is_group) {
                throw InvalidValue::for('store_id', 'is required: the store name is the CSR organization unit');
            }
            if ($storeId !== null) {
                $store = Branch::query()->where('company_id', $device->company_id)->whereKey((int) $storeId)->first();
                if ($store === null) {
                    throw InvalidValue::for('store_id', 'is not a store of this device\'s company');
                }
            }
            $changes['branch_id'] = $storeId !== null ? (int) $storeId : null;
        }

        $before = $device->only(array_keys($changes));
        $device->update($changes);
        $this->audit->record('device.updated', 'device', (int) $device->id, ['before' => $before, 'after' => $changes], $actorId);

        return $device;
    }

    /** Suspends the device: it refuses new invoices until enabled again. */
    public function disableDevice(Device $device, ?int $actorId = null): Device
    {
        if (in_array($device->lifecycle_status, [S::Decommissioned, S::Suspended], true)) {
            throw DeviceStateException::notAllowed((int) $device->id, 'be disabled', $device->lifecycle_status->value);
        }
        $previous = $device->lifecycle_status->value;
        $device->update(['lifecycle_status' => S::Suspended]);
        $this->audit->record('device.disabled', 'device', (int) $device->id, ['previous_status' => $previous], $actorId);

        return $device;
    }

    /** Back to ACTIVE (or EXPIRED) when it has a production certificate, otherwise to REGISTERED for onboarding. */
    public function enableDevice(Device $device, ?int $actorId = null): Device
    {
        if ($device->lifecycle_status !== S::Suspended) {
            throw DeviceStateException::notAllowed((int) $device->id, 'be enabled', $device->lifecycle_status->value);
        }
        $status = match (true) {
            $device->active_certificate_id === null => S::Registered,
            $device->certificate_expiry !== null && $device->certificate_expiry->isPast() => S::Expired,
            default => S::Active,
        };
        $device->update(['lifecycle_status' => $status]);
        $this->audit->record('device.enabled', 'device', (int) $device->id, ['status' => $status->value], $actorId);

        return $device;
    }

    /**
     * Deletes a device that never issued anything and never received a production certificate. Any other device
     * is decommissioned (kept, permanently unable to issue) because its documents and certificates are records.
     *
     * @return 'deleted'|'decommissioned'
     */
    public function deleteDevice(Device $device, ?int $actorId = null): string
    {
        $hasRecords = Invoice::query()->where('device_id', $device->id)->exists()
            || InvoiceIssuance::query()->where('device_id', $device->id)->exists()
            || ChainEntry::query()->where('device_id', $device->id)->exists()
            || $device->certificates()->where('type', CertificateType::Production)->exists();

        if ($hasRecords) {
            if ($device->lifecycle_status !== S::Decommissioned) {
                $previous = $device->lifecycle_status->value;
                $device->update(['lifecycle_status' => S::Decommissioned, 'decommissioned_at' => now()]);
                $this->audit->record('device.decommissioned', 'device', (int) $device->id, ['previous_status' => $previous], $actorId);
            }

            return 'decommissioned';
        }

        $this->transactions->run(static function () use ($device): void {
            $device->renewals()->delete();
            $device->onboardings()->delete();
            $device->update(['active_certificate_id' => null]);
            $device->certificates()->delete();
            $device->keys()->delete();
            $device->counters()->delete();
            $device->delete();
        });
        $this->audit->record('device.deleted', 'device', (int) $device->id, ['name' => $device->name, 'uuid' => $device->device_uuid], $actorId);

        return 'deleted';
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, string>|null */
    private function address(mixed $address): ?array
    {
        if (! is_array($address)) {
            return null;
        }
        $clean = array_filter(array_map(static fn ($v) => trim((string) $v), $address), static fn ($v) => $v !== '');

        return $clean === [] ? null : $clean + ['country' => 'SA'];
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? mb_substr($value, 0, 191) : null;
    }
}
