<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers;

use CodingTriplets\Zatca\Core\Domain\Exceptions\ZatcaException;
use CodingTriplets\Zatca\Laravel\Dashboard\Abilities;
use CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers\Concerns\DashboardHelpers;
use CodingTriplets\Zatca\Laravel\Database\Models\Branch;
use CodingTriplets\Zatca\Laravel\Database\Models\Company;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\VatRegistration;
use CodingTriplets\Zatca\Laravel\Devices\CompanyService;
use CodingTriplets\Zatca\Laravel\Devices\OrganisationManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Companies (taxpayers), their VAT registrations and stores. */
final class CompanyController
{
    use DashboardHelpers;

    public function index(Request $request): View
    {
        $this->authorizeAbility($request, Abilities::COMPANIES_VIEW);
        $q = trim((string) $request->query('q', ''));
        $like = str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $companies = Company::query()
            ->when($q !== '', static fn ($query) => $query->where(static fn ($w) => $w->where('name_en', 'like', $like)
                ->orWhere('crn', $q)->orWhere('external_ref', $q)
                ->orWhereIn('id', VatRegistration::query()->where('vat_number', $q)->select('company_id'))))
            ->orderBy('name_en')->paginate(15, ['*'], 'companies_page')->withQueryString();
        $ids = $companies->pluck('id');

        $storeCompany = (int) $request->query('company', 0);
        $stores = Branch::query()->with('company:id,name_en')
            ->when($storeCompany > 0, static fn ($query) => $query->where('company_id', $storeCompany))
            ->orderBy('company_id')->orderBy('code')
            ->paginate(25, ['*'], 'stores_page')->withQueryString();

        return view('zatca::dashboard.companies.index', [
            'companies' => $companies,
            'registrations' => VatRegistration::query()->whereIn('company_id', $ids)->get(['id', 'company_id', 'vat_number', 'legal_name', 'is_group', 'group_member_tin'])->groupBy('company_id'),
            'storeCounts' => Branch::query()->whereIn('company_id', $ids)->selectRaw('company_id, COUNT(*) as aggregate')->groupBy('company_id')->pluck('aggregate', 'company_id'),
            'deviceCounts' => Device::query()->whereIn('company_id', $ids)->selectRaw('company_id, COUNT(*) as aggregate')->groupBy('company_id')->pluck('aggregate', 'company_id'),
            'stores' => $stores,
            'storeDeviceCounts' => Device::query()->whereIn('branch_id', $stores->pluck('id'))->selectRaw('branch_id, COUNT(*) as aggregate')->groupBy('branch_id')->pluck('aggregate', 'branch_id'),
            'allCompanies' => Company::query()->orderBy('name_en')->get(['id', 'name_en']),
            'filters' => ['q' => $q, 'company' => $storeCompany ?: null],
            'totals' => [
                'companies' => Company::query()->count(),
                'disabled_companies' => Company::query()->where('status', OrganisationManager::DISABLED)->count(),
                'stores' => Branch::query()->count(),
                'devices' => Device::query()->count(),
            ],
        ]);
    }

    public function store(Request $request, CompanyService $companies): RedirectResponse
    {
        $this->authorizeAbility($request, Abilities::COMPANIES_MANAGE);
        $data = $request->only(['name', 'name_ar', 'legal_name', 'vat_number', 'crn', 'external_ref']);
        $address = array_filter($request->only(['street', 'building_number', 'district', 'city', 'postal_code']), static fn ($v) => $v !== null && $v !== '');
        if ($address !== []) {
            $data['address'] = $address;
        }

        try {
            $company = $companies->create(array_filter($data, static fn ($v) => $v !== null && $v !== ''), $this->actorId($request));
        } catch (ZatcaException|\InvalidArgumentException $e) {
            return back()->withInput()->with('zatca_error', $e->getMessage());
        }

        return redirect($this->zroute('companies.index'))->with('zatca_status', "Company {$company->name_en} saved.");
    }

    public function storeStore(Request $request, int $company, CompanyService $companies): RedirectResponse
    {
        $this->authorizeAbility($request, Abilities::COMPANIES_MANAGE);
        $company = Company::query()->findOrFail($company);

        try {
            $store = $companies->createStore($company, array_filter($request->only(['code', 'name', 'external_ref']), static fn ($v) => $v !== null && $v !== ''));
        } catch (ZatcaException|\InvalidArgumentException $e) {
            return back()->withInput()->with('zatca_error', $e->getMessage());
        }

        return redirect($this->zroute('companies.index'))->with('zatca_status', "Store {$store->name} saved.");
    }

    public function edit(Request $request, int $company): View
    {
        $this->authorizeAbility($request, Abilities::COMPANIES_MANAGE);
        $company = Company::query()->findOrFail($company);

        return view('zatca::dashboard.companies.edit', [
            'company' => $company,
            'registrations' => VatRegistration::query()->where('company_id', $company->id)->get(),
        ]);
    }

    public function update(Request $request, int $company, OrganisationManager $organisation): RedirectResponse
    {
        $this->authorizeAbility($request, Abilities::COMPANIES_MANAGE);
        $company = Company::query()->findOrFail($company);

        try {
            $organisation->updateCompany($company, $request->only(['name', 'name_ar', 'legal_name', 'crn', 'external_ref']) + [
                'address' => $request->only(['street', 'building_number', 'district', 'city', 'postal_code']),
            ], $this->actorId($request));
        } catch (ZatcaException|\InvalidArgumentException $e) {
            return back()->withInput()->with('zatca_error', $e->getMessage());
        }

        return redirect($this->zroute('companies.index'))->with('zatca_status', "Company {$company->name_en} updated.");
    }

    public function toggle(Request $request, int $company, string $toggle, OrganisationManager $organisation): RedirectResponse
    {
        $this->authorizeAbility($request, Abilities::COMPANIES_MANAGE);
        $company = Company::query()->findOrFail($company);
        $organisation->setCompanyEnabled($company, $toggle === 'enable', $this->actorId($request));

        return back()->with('zatca_status', $toggle === 'enable'
            ? "Company {$company->name_en} enabled."
            : "Company {$company->name_en} disabled: its devices refuse new invoices until you enable it.");
    }

    public function destroy(Request $request, int $company, OrganisationManager $organisation): RedirectResponse
    {
        $this->authorizeAbility($request, Abilities::COMPANIES_MANAGE);
        $company = Company::query()->findOrFail($company);

        try {
            $organisation->deleteCompany($company, $this->actorId($request));
        } catch (ZatcaException $e) {
            return back()->with('zatca_error', "Company {$company->name_en} was not deleted: " . $e->getMessage());
        }

        return redirect($this->zroute('companies.index'))->with('zatca_status', "Company {$company->name_en} deleted.");
    }
}
