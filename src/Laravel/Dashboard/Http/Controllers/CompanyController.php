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
        $companies = Company::query()->orderBy('name_en')->paginate(25);
        $ids = $companies->pluck('id');

        return view('zatca::dashboard.companies.index', [
            'companies' => $companies,
            'registrations' => VatRegistration::query()->whereIn('company_id', $ids)->get(['id', 'company_id', 'vat_number', 'legal_name', 'is_group', 'group_member_tin'])->groupBy('company_id'),
            'stores' => Branch::query()->whereIn('company_id', $ids)->orderBy('code')->get(['id', 'company_id', 'code', 'name', 'external_ref'])->groupBy('company_id'),
            'deviceCounts' => Device::query()->whereIn('company_id', $ids)->selectRaw('company_id, COUNT(*) as aggregate')->groupBy('company_id')->pluck('aggregate', 'company_id'),
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
}
