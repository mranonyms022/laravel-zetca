@extends('zatca::dashboard.layout')

@section('title', 'Companies & stores')
@section('section', 'companies')

@php
    $input = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 dark:border-slate-700 dark:bg-slate-950';
    $label = 'block text-xs font-medium text-slate-600 dark:text-slate-300';
    $card = 'overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900';
    $cardHead = 'flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800';
    $th = 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500';
    $td = 'px-4 py-3 align-middle';
    $primary = 'inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-700';
    $canManage = $can(\CodingTriplets\Zatca\Laravel\Dashboard\Abilities::COMPANIES_MANAGE);
    $badge = static fn (bool $on) => $on
        ? '<span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-900/40 dark:text-emerald-200"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Active</span>'
        : '<span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 ring-1 ring-inset ring-slate-500/20 dark:bg-slate-800 dark:text-slate-300"><span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>Disabled</span>';
@endphp

@section('content')
{{-- summary cards --}}
<div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
    @foreach ([
        ['Companies', $totals['companies'], 'M4 21V5a2 2 0 012-2h8a2 2 0 012 2v16M2 21h20M8 7h4M8 11h4M8 15h4', 'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-300'],
        ['Disabled companies', $totals['disabled_companies'], 'M10 9v6m4-6v6M12 3a9 9 0 110 18 9 9 0 010-18z', 'bg-amber-50 text-amber-600 dark:bg-amber-900/40 dark:text-amber-300'],
        ['Stores', $totals['stores'], 'M3 9l1.5-5h15L21 9M3 9h18v11H3zM9 20v-6h6v6', 'bg-sky-50 text-sky-600 dark:bg-sky-900/40 dark:text-sky-300'],
        ['Devices', $totals['devices'], 'M4 5h16v10H4zM8 19h8M12 15v4', 'bg-violet-50 text-violet-600 dark:bg-violet-900/40 dark:text-violet-300'],
    ] as [$title, $value, $icon, $tone])
        <div class="{{ $card }} flex items-center gap-4 p-5">
            <span class="inline-flex h-11 w-11 items-center justify-center rounded-lg {{ $tone }}">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $icon }}"/></svg>
            </span>
            <div>
                <div class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $title }}</div>
                <div class="text-2xl font-semibold">{{ number_format($value) }}</div>
            </div>
        </div>
    @endforeach
</div>

{{-- companies --}}
<section class="{{ $card }} mb-6">
    <div class="{{ $cardHead }}">
        <div>
            <h2 class="text-base font-semibold">Companies</h2>
            <p class="text-xs text-slate-500">Taxpayers with their VAT registration. Disabling a company stops new invoices on all its devices.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <form method="GET" class="flex items-center gap-2">
                <input type="hidden" name="company" value="{{ $filters['company'] }}">
                <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Name, VAT, CRN, your ID" class="w-56 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950">
            </form>
            @if ($canManage)
                <a href="#add-company" class="{{ $primary }}" onclick="document.getElementById('add-company').open = true">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    Add company
                </a>
            @endif
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
            <thead class="bg-slate-50 dark:bg-slate-800/50"><tr>
                <th class="{{ $th }}">Company</th><th class="{{ $th }}">VAT number</th><th class="{{ $th }}">CRN</th>
                <th class="{{ $th }}">City</th><th class="{{ $th }} text-center">Stores</th><th class="{{ $th }} text-center">Devices</th>
                <th class="{{ $th }}">Status</th>@if ($canManage)<th class="{{ $th }} text-right">Actions</th>@endif
            </tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            @forelse ($companies as $company)
                @php $active = ($company->status ?? 'active') === 'active'; @endphp
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40 {{ $active ? '' : 'opacity-70' }}">
                    <td class="{{ $td }}">
                        <div class="font-medium">{{ $company->name_en }}</div>
                        <div class="text-xs text-slate-500">
                            @if ($company->name_ar)<span dir="rtl">{{ $company->name_ar }}</span> · @endif
                            <span class="font-mono">#{{ $company->id }}</span>@if ($company->external_ref) · your ID {{ $company->external_ref }}@endif
                        </div>
                    </td>
                    <td class="{{ $td }}">
                        @foreach ($registrations[$company->id] ?? [] as $reg)
                            <div class="font-mono text-xs">{{ $reg->vat_number }}</div>
                            <div class="text-xs text-slate-500">{{ $reg->legal_name }}{{ $reg->is_group ? ' · group TIN '.$reg->group_member_tin : '' }}</div>
                        @endforeach
                    </td>
                    <td class="{{ $td }} font-mono text-xs">{{ $company->crn ?: '—' }}</td>
                    <td class="{{ $td }}">{{ $company->address['city'] ?? '—' }}</td>
                    <td class="{{ $td }} text-center"><a href="{{ $zroute('companies.index', ['company' => $company->id]) }}#stores" class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium hover:bg-slate-200 dark:bg-slate-800">{{ (int) ($storeCounts[$company->id] ?? 0) }}</a></td>
                    <td class="{{ $td }} text-center"><span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium dark:bg-slate-800">{{ (int) ($deviceCounts[$company->id] ?? 0) }}</span></td>
                    <td class="{{ $td }}">{!! $badge($active) !!}</td>
                    @if ($canManage)
                        <td class="{{ $td }}">
                            @include('zatca::dashboard.partials.row-actions', [
                                'name' => $company->name_en,
                                'edit' => $zroute('companies.edit', $company->id),
                                'enabled' => $active,
                                'toggle' => ['enable' => $zroute('companies.toggle', [$company->id, 'enable']), 'disable' => $zroute('companies.toggle', [$company->id, 'disable'])],
                                'delete' => $zroute('companies.destroy', $company->id),
                                'confirm' => "Delete company {$company->name_en} and its stores? Only possible while it has no devices or invoices.",
                            ])
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-12 text-center text-slate-500">No companies{{ $filters['q'] !== '' ? ' match “'.$filters['q'].'”' : ' yet' }}.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($companies->hasPages())
        <div class="border-t border-slate-200 px-5 py-3 dark:border-slate-800">{{ $companies->links('zatca::dashboard.partials.pager') }}</div>
    @endif
</section>

{{-- stores --}}
<section id="stores" class="{{ $card }} mb-6">
    <div class="{{ $cardHead }}">
        <div>
            <h2 class="text-base font-semibold">Stores</h2>
            <p class="text-xs text-slate-500">Branches of a company. The store name is the organisation unit in each device certificate.</p>
        </div>
        <form method="GET" class="flex items-center gap-2">
            <input type="hidden" name="q" value="{{ $filters['q'] }}">
            <select name="company" onchange="this.form.submit()" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950">
                <option value="">All companies</option>
                @foreach ($allCompanies as $c)
                    <option value="{{ $c->id }}" @selected((int) $filters['company'] === (int) $c->id)>{{ $c->name_en }}</option>
                @endforeach
            </select>
            <noscript><button class="rounded-lg border border-slate-300 px-3 py-2 text-sm">Filter</button></noscript>
        </form>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
            <thead class="bg-slate-50 dark:bg-slate-800/50"><tr>
                <th class="{{ $th }}">Store</th><th class="{{ $th }}">Code</th><th class="{{ $th }}">Company</th>
                <th class="{{ $th }}">Your ID</th><th class="{{ $th }} text-center">Devices</th><th class="{{ $th }}">Status</th>
                @if ($canManage)<th class="{{ $th }} text-right">Actions</th>@endif
            </tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            @forelse ($stores as $store)
                @php $active = ($store->status ?? 'active') === 'active'; @endphp
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40 {{ $active ? '' : 'opacity-70' }}">
                    <td class="{{ $td }}"><div class="font-medium">{{ $store->name }}</div><div class="font-mono text-xs text-slate-500">#{{ $store->id }}</div></td>
                    <td class="{{ $td }} font-mono text-xs">{{ $store->code }}</td>
                    <td class="{{ $td }}">{{ $store->company?->name_en ?? '—' }}</td>
                    <td class="{{ $td }} text-xs">{{ $store->external_ref ?: '—' }}</td>
                    <td class="{{ $td }} text-center"><span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium dark:bg-slate-800">{{ (int) ($storeDeviceCounts[$store->id] ?? 0) }}</span></td>
                    <td class="{{ $td }}">{!! $badge($active) !!}</td>
                    @if ($canManage)
                        <td class="{{ $td }}">
                            @include('zatca::dashboard.partials.row-actions', [
                                'name' => $store->name,
                                'edit' => $zroute('stores.edit', $store->id),
                                'enabled' => $active,
                                'toggle' => ['enable' => $zroute('stores.toggle', [$store->id, 'enable']), 'disable' => $zroute('stores.toggle', [$store->id, 'disable'])],
                                'delete' => $zroute('stores.destroy', $store->id),
                                'confirm' => "Delete store {$store->name}? Only possible while it has no devices or invoices.",
                            ])
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-12 text-center text-slate-500">No stores yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($stores->hasPages())
        <div class="border-t border-slate-200 px-5 py-3 dark:border-slate-800">{{ $stores->links('zatca::dashboard.partials.pager') }}</div>
    @endif

    @if ($canManage && $allCompanies->isNotEmpty())
        <form method="POST" action="{{ $zroute('stores.store') }}"
              class="grid gap-3 border-t border-slate-200 bg-slate-50 px-5 py-4 sm:grid-cols-5 dark:border-slate-800 dark:bg-slate-800/30">
            @csrf
            <div class="sm:col-span-5 text-sm font-medium">Add a store</div>
            <label class="{{ $label }}">Company
                <select name="company_id" class="{{ $input }}">
                    @foreach ($allCompanies as $c)
                        <option value="{{ $c->id }}" @selected((int) (old('company_id') ?? $filters['company']) === (int) $c->id)>{{ $c->name_en }}</option>
                    @endforeach
                </select>
            </label>
            <label class="{{ $label }}">Code<input name="code" required maxlength="50" class="{{ $input }} font-mono"></label>
            <label class="{{ $label }}">Name<input name="name" required maxlength="100" class="{{ $input }}"></label>
            <label class="{{ $label }}">Your ID (optional)<input name="external_ref" maxlength="191" class="{{ $input }}"></label>
            <div class="flex items-end"><button class="{{ $primary }} w-full justify-center">Add store</button></div>
        </form>
    @endif
</section>

{{-- add company --}}
@if ($canManage)
    <details id="add-company" class="{{ $card }}" @if($companies->isEmpty() && $filters['q'] === '' || old('vat_number')) open @endif>
        <summary class="flex cursor-pointer items-center justify-between px-5 py-4">
            <span>
                <span class="text-base font-semibold">Add a company (taxpayer)</span>
                <span class="block text-xs text-slate-500">Name, VAT registration and national address as registered with ZATCA.</span>
            </span>
            <span class="text-sm text-emerald-700 dark:text-emerald-400">Show form</span>
        </summary>
        <form method="POST" action="{{ $zroute('companies.store') }}" class="border-t border-slate-200 p-5 dark:border-slate-800">
            @csrf
            @include('zatca::dashboard.companies.form', ['company' => null, 'legalName' => old('legal_name'), 'withVat' => true])
            <div class="mt-5 flex justify-end"><button class="{{ $primary }}">Save company</button></div>
        </form>
    </details>
@endif
@endsection
