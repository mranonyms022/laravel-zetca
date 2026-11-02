<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers;

use CodingTriplets\Zatca\Core\Domain\Exceptions\ZatcaException;
use CodingTriplets\Zatca\Laravel\Dashboard\Abilities;
use CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers\Concerns\DashboardHelpers;
use CodingTriplets\Zatca\Laravel\Database\Models\Branch;
use CodingTriplets\Zatca\Laravel\Database\Models\Company;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Devices\CompanyService;
use CodingTriplets\Zatca\Laravel\Devices\OrganisationManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Stores (branches): edit, disable / enable, delete. Created from the companies page. */
final class StoreController
{
    use DashboardHelpers;

    public function store(Request $request, CompanyService $companies): RedirectResponse
    {
        $this->authorizeAbility($request, Abilities::COMPANIES_MANAGE);
        $company = Company::query()->find((int) $request->input('company_id'));
        if ($company === null) {
            return back()->withInput()->with('zatca_error', 'Choose the company the store belongs to.');
        }

        try {
            $store = $companies->createStore($company, array_filter($request->only(['code', 'name', 'external_ref']), static fn ($v) => $v !== null && $v !== ''));
        } catch (ZatcaException|\InvalidArgumentException $e) {
            return back()->withInput()->with('zatca_error', $e->getMessage());
        }

        return redirect($this->zroute('companies.index', ['company' => $company->id]) . '#stores')->with('zatca_status', "Store {$store->name} saved.");
    }

    public function edit(Request $request, int $store): View
    {
        $this->authorizeAbility($request, Abilities::COMPANIES_MANAGE);
        $store = Branch::query()->with('company:id,name_en')->findOrFail($store);

        return view('zatca::dashboard.stores.edit', [
            'store' => $store,
            'onboardedDevices' => Device::query()->where('branch_id', $store->id)->whereNotNull('active_certificate_id')->count(),
        ]);
    }

    public function update(Request $request, int $store, OrganisationManager $organisation): RedirectResponse
    {
        $this->authorizeAbility($request, Abilities::COMPANIES_MANAGE);
        $store = Branch::query()->findOrFail($store);

        try {
            $organisation->updateStore($store, $request->only(['code', 'name', 'external_ref']), $this->actorId($request));
        } catch (ZatcaException|\InvalidArgumentException $e) {
            return back()->withInput()->with('zatca_error', $e->getMessage());
        }

        return redirect($this->zroute('companies.index'))->with('zatca_status', "Store {$store->name} updated.");
    }

    public function toggle(Request $request, int $store, string $toggle, OrganisationManager $organisation): RedirectResponse
    {
        $this->authorizeAbility($request, Abilities::COMPANIES_MANAGE);
        $store = Branch::query()->findOrFail($store);
        $organisation->setStoreEnabled($store, $toggle === 'enable', $this->actorId($request));

        return back()->with('zatca_status', $toggle === 'enable'
            ? "Store {$store->name} enabled."
            : "Store {$store->name} disabled: its devices refuse new invoices until you enable it.");
    }

    public function destroy(Request $request, int $store, OrganisationManager $organisation): RedirectResponse
    {
        $this->authorizeAbility($request, Abilities::COMPANIES_MANAGE);
        $store = Branch::query()->findOrFail($store);

        try {
            $organisation->deleteStore($store, $this->actorId($request));
        } catch (ZatcaException $e) {
            return back()->with('zatca_error', "Store {$store->name} was not deleted: " . $e->getMessage());
        }

        return back()->with('zatca_status', "Store {$store->name} deleted.");
    }
}
