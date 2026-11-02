@extends('zatca::dashboard.layout')

@section('title', 'Companies & stores')
@section('section', 'companies')

@php
    $input = 'mt-1 w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-950';
    $label = 'block text-xs font-medium text-slate-600 dark:text-slate-300';
    $canManage = $can(\CodingTriplets\Zatca\Laravel\Dashboard\Abilities::COMPANIES_MANAGE);
@endphp

@section('content')
<h2 class="mb-4 text-xl font-semibold">Companies &amp; stores</h2>

@if ($canManage)
    <details class="mb-6 rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900" @if($companies->isEmpty()) open @endif>
        <summary class="cursor-pointer px-4 py-3 text-sm font-medium">Add a company (taxpayer)</summary>
        <form method="POST" action="{{ $zroute('companies.store') }}" class="grid gap-3 border-t border-slate-100 p-4 sm:grid-cols-3 dark:border-slate-800">
            @csrf
            <label class="{{ $label }}">Name<input name="name" required value="{{ old('name') }}" class="{{ $input }}"></label>
            <label class="{{ $label }}">Legal name (printed on invoices)<input name="legal_name" required value="{{ old('legal_name') }}" class="{{ $input }}"></label>
            <label class="{{ $label }}">Arabic name<input name="name_ar" dir="rtl" value="{{ old('name_ar') }}" class="{{ $input }}"></label>
            <label class="{{ $label }}">VAT number (15 digits)<input name="vat_number" required pattern="3[0-9]{13}3" maxlength="15" value="{{ old('vat_number') }}" class="{{ $input }} font-mono"></label>
            <label class="{{ $label }}">CRN<input name="crn" value="{{ old('crn') }}" class="{{ $input }} font-mono"></label>
            <label class="{{ $label }}">Your ID (external_ref)<input name="external_ref" value="{{ old('external_ref') }}" class="{{ $input }}"></label>
            <label class="{{ $label }}">Street<input name="street" required value="{{ old('street') }}" class="{{ $input }}"></label>
            <label class="{{ $label }}">Building number (4 digits)<input name="building_number" required pattern="[0-9]{4}" value="{{ old('building_number') }}" class="{{ $input }}"></label>
            <label class="{{ $label }}">District<input name="district" required value="{{ old('district') }}" class="{{ $input }}"></label>
            <label class="{{ $label }}">City<input name="city" required value="{{ old('city') }}" class="{{ $input }}"></label>
            <label class="{{ $label }}">Postal code (5 digits)<input name="postal_code" required pattern="[0-9]{5}" value="{{ old('postal_code') }}" class="{{ $input }}"></label>
            <div class="flex items-end"><button class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">Save company</button></div>
        </form>
    </details>
@endif

<div class="space-y-4">
@forelse ($companies as $company)
    <div class="rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
            <div>
                <div class="font-semibold">{{ $company->name_en }} <span class="font-mono text-xs text-slate-500">#{{ $company->id }}</span></div>
                <div class="text-xs text-slate-500">
                    @foreach ($registrations[$company->id] ?? [] as $reg)
                        VAT {{ $reg->vat_number }}{{ $reg->is_group ? ' (group, TIN '.$reg->group_member_tin.')' : '' }} · {{ $reg->legal_name }}@if(! $loop->last); @endif
                    @endforeach
                    @if ($company->crn) · CRN {{ $company->crn }} @endif
                    @if ($company->external_ref) · your ID {{ $company->external_ref }} @endif
                </div>
            </div>
            <a href="{{ $zroute('devices.index') }}" class="text-sm text-slate-500 hover:underline">{{ (int) ($deviceCounts[$company->id] ?? 0) }} device(s)</a>
        </div>
        <div class="px-4 py-3 text-sm">
            <div class="mb-2 text-xs uppercase tracking-wide text-slate-500">Stores</div>
            <ul class="flex flex-wrap gap-2">
                @forelse ($stores[$company->id] ?? [] as $store)
                    <li class="rounded-md bg-slate-100 px-2 py-1 dark:bg-slate-800">{{ $store->name }} <span class="font-mono text-xs text-slate-500">{{ $store->code }} · #{{ $store->id }}</span></li>
                @empty
                    <li class="text-slate-500">No stores yet.</li>
                @endforelse
            </ul>
            @if ($canManage)
                <form method="POST" action="{{ $zroute('companies.stores.store', $company->id) }}" class="mt-3 flex flex-wrap items-end gap-2">
                    @csrf
                    <label class="{{ $label }}">Code<input name="code" required maxlength="50" class="{{ $input }} w-28"></label>
                    <label class="{{ $label }}">Name<input name="name" required maxlength="100" class="{{ $input }} w-48"></label>
                    <label class="{{ $label }}">Your ID<input name="external_ref" maxlength="191" class="{{ $input }} w-32"></label>
                    <button class="rounded-md border border-emerald-600 px-3 py-1.5 text-sm font-medium text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-900/30">Add store</button>
                </form>
            @endif
        </div>
    </div>
@empty
    <p class="rounded-lg border border-slate-200 bg-white px-4 py-10 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-900">No companies yet.</p>
@endforelse
</div>
<div class="mt-4">{{ $companies->links('zatca::dashboard.partials.pager') }}</div>
@endsection
