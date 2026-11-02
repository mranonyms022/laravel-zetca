<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Devices;

use CodingTriplets\Zatca\Core\Domain\Exceptions\InvalidValue;
use CodingTriplets\Zatca\Laravel\Database\Models\Branch;
use CodingTriplets\Zatca\Laravel\Database\Models\Company;
use CodingTriplets\Zatca\Laravel\Database\Models\VatRegistration;

/**
 * Companies (taxpayers) and stores (branches) from code: `Zatca::companies()`.
 *
 * Both accept the host application's own ID as `external_ref`, so device files can reference either the
 * package ID or the host ID (see resolveCompany() / resolveStore()). All creates are idempotent.
 */
final class CompanyService
{
    public function __construct(private readonly DeviceRegistrar $registrar)
    {
    }

    /**
     * @param array{name?: string, name_en?: string, name_ar?: ?string, legal_name: string, vat_number: string,
     *              group_member_tin?: ?string, crn?: ?string, address?: ?array<string, mixed>,
     *              seller_id_scheme?: ?string, seller_id_value?: ?string, external_ref?: int|string|null,
     *              stores?: list<array<string, mixed>>} $data
     */
    public function create(array $data, ?int $actorId = null): Company
    {
        $ref = $this->ref($data['external_ref'] ?? null);
        if ($ref !== null && ($existing = $this->byRef($ref)) !== null) {
            $this->createStores($existing, (array) ($data['stores'] ?? []));

            return $existing->refresh();
        }

        $vat = trim((string) ($data['vat_number'] ?? ''));
        $tin = trim((string) ($data['group_member_tin'] ?? ''));
        $registration = VatRegistration::query()->where('vat_number', $vat)->where('group_member_tin', $tin)->first();
        if ($registration === null) {
            $name = trim((string) ($data['name_en'] ?? $data['name'] ?? ''));
            if ($name === '') {
                throw InvalidValue::for('name', 'is required');
            }
            if (trim((string) ($data['legal_name'] ?? '')) === '') {
                throw InvalidValue::for('legal_name', 'is required (taxpayer legal name, printed on every invoice)');
            }
            $registration = $this->registrar->registerTaxpayer([
                'name_en' => $name,
                'name_ar' => $data['name_ar'] ?? null,
                'legal_name' => (string) $data['legal_name'],
                'vat_number' => $vat,
                'group_member_tin' => $tin !== '' ? $tin : null,
                'crn' => $data['crn'] ?? null,
                'address' => isset($data['address']) ? ((array) $data['address']) + ['country' => 'SA'] : null,
                'seller_id_scheme' => $data['seller_id_scheme'] ?? null,
                'seller_id_value' => $data['seller_id_value'] ?? null,
            ], $actorId);
        }

        /** @var Company $company */
        $company = Company::query()->withTrashed()->findOrFail($registration->company_id);
        if ($company->trashed()) {
            // deleted from the dashboard and created again: bring it back (the VAT registration is unique)
            $company->restore();
            $company->forceFill(['status' => 'active'])->save();
            $registration->forceFill(['status' => 'active'])->save();
        }
        if ($ref !== null && $company->external_ref === null) {
            $company->forceFill(['external_ref' => $ref])->save();
        }
        $this->createStores($company, (array) ($data['stores'] ?? []));

        return $company->refresh();
    }

    /**
     * @param int|string|Company $company package ID, external_ref or model
     * @param array{code: string, name: string, address?: ?array<string, mixed>, external_ref?: int|string|null} $data
     */
    public function createStore(int|string|Company $company, array $data): Branch
    {
        $company = $company instanceof Company ? $company : $this->resolveCompany($company);
        $code = trim((string) ($data['code'] ?? $data['external_ref'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($code === '') {
            throw InvalidValue::for('code', 'is required (unique store code within the company)');
        }
        if ($name === '') {
            throw InvalidValue::for('name', 'is required (printed in the device certificate as organisation unit)');
        }
        $ref = $this->ref($data['external_ref'] ?? null);

        $branch = Branch::query()->where('company_id', $company->id)->where('code', $code)->first()
            ?? ($ref !== null ? Branch::query()->where('company_id', $company->id)->where('external_ref', $ref)->first() : null);
        if ($branch === null && ($deleted = Branch::query()->onlyTrashed()->where('company_id', $company->id)->where('code', $code)->first()) !== null) {
            // deleted from the dashboard and created again: the code is unique per company, so restore it
            $deleted->restore();
            $deleted->forceFill(['name' => $name, 'status' => 'active'])->save();
            $branch = $deleted;
        }
        $branch ??= $this->registrar->registerBranch((int) $company->id, $code, $name, isset($data['address']) ? (array) $data['address'] : null);

        if ($ref !== null && $branch->external_ref === null) {
            $branch->forceFill(['external_ref' => $ref])->save();
        }

        return $branch;
    }

    /**
     * Finds a company by external_ref (host application ID) first, then by package ID.
     *
     * @param 'auto'|'external'|'package' $mode
     */
    public function resolveCompany(int|string $id, string $mode = 'auto'): Company
    {
        $ref = (string) $id;
        $company = $mode !== 'package' ? $this->byRef($ref) : null;
        if ($company === null && $mode !== 'external' && ctype_digit($ref)) {
            $company = Company::query()->find((int) $ref);
        }
        if ($company === null) {
            throw InvalidValue::for('company_id', "company [{$ref}] was not found (create it first with Zatca::companies()->create())");
        }

        return $company;
    }

    /** @param 'auto'|'external'|'package' $mode */
    public function resolveStore(Company $company, int|string $id, string $mode = 'auto'): Branch
    {
        $ref = (string) $id;
        $query = static fn () => Branch::query()->where('company_id', $company->id);
        $branch = $mode !== 'package' ? ($query()->where('external_ref', $ref)->first() ?? $query()->where('code', $ref)->first()) : null;
        if ($branch === null && $mode !== 'external' && ctype_digit($ref)) {
            $branch = $query()->whereKey((int) $ref)->first();
        }
        if ($branch === null) {
            throw InvalidValue::for('store_id', "store [{$ref}] was not found in company #{$company->id} (create it first with Zatca::companies()->createStore())");
        }

        return $branch;
    }

    /** The company's VAT registration: the only one, or the one with $vatNumber. */
    public function registrationFor(Company $company, ?string $vatNumber = null): VatRegistration
    {
        $query = VatRegistration::query()->where('company_id', $company->id);
        if ($vatNumber !== null && trim($vatNumber) !== '') {
            $query->where('vat_number', trim($vatNumber));
        }
        $found = $query->limit(2)->get();
        if ($found->count() !== 1) {
            throw InvalidValue::for('vat_number', $found->isEmpty()
                ? "company #{$company->id} has no matching VAT registration"
                : "company #{$company->id} has several VAT registrations; give vat_number");
        }

        return $found->first();
    }

    /** @param list<array<string, mixed>> $stores */
    private function createStores(Company $company, array $stores): void
    {
        foreach ($stores as $store) {
            $this->createStore($company, (array) $store);
        }
    }

    private function byRef(string $ref): ?Company
    {
        return Company::query()->where('external_ref', $ref)->first();
    }

    private function ref(mixed $value): ?string
    {
        $ref = trim((string) ($value ?? ''));

        return $ref !== '' ? mb_substr($ref, 0, 191) : null;
    }
}
