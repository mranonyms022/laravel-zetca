@extends('zatca::dashboard.layout')

@section('title', 'Register device')
@section('section', 'devices')

@php
    $input = 'mt-1 w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-950';
    $label = 'block text-sm font-medium';
    $help = 'block text-xs font-normal text-slate-500';
@endphp

@section('content')
<div class="mb-4 text-sm"><a href="{{ $zroute('devices.index') }}" class="text-slate-500 hover:underline">← Devices</a></div>
<h2 class="mb-4 text-xl font-semibold">Register a device</h2>

@if ($companies->isEmpty())
    <p class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-100">
        Create a company and a store first: <a href="{{ $zroute('companies.index') }}" class="font-medium underline">Companies &amp; stores</a>.
    </p>
@else
<form method="POST" action="{{ $zroute('devices.store') }}" class="grid max-w-2xl gap-4 rounded-lg border border-slate-200 bg-white p-5 sm:grid-cols-2 dark:border-slate-800 dark:bg-slate-900">
    @csrf
    <label class="{{ $label }}">Company
        <select name="company_id" required class="{{ $input }}">
            @foreach ($companies as $company)
                <option value="{{ $company->id }}" @selected(old('company_id') == $company->id)>{{ $company->name_en }} (#{{ $company->id }})</option>
            @endforeach
        </select>
    </label>
    <label class="{{ $label }}">Store
        <select name="store_id" required class="{{ $input }}">
            @foreach ($stores as $store)
                <option value="{{ $store->id }}" @selected(old('store_id') == $store->id)>{{ $store->name }} ({{ $store->code }}) · company #{{ $store->company_id }}</option>
            @endforeach
        </select>
        <span class="{{ $help }}">Must belong to the selected company.</span>
    </label>
    <label class="{{ $label }}">Device name
        <input name="name" required maxlength="100" value="{{ old('name') }}" class="{{ $input }}" placeholder="POS-01">
        <span class="{{ $help }}">Unique within the store.</span>
    </label>
    <label class="{{ $label }}">Invoice types
        <select name="invoice_types" required class="{{ $input }}">
            @foreach (['0100' => 'B2C (simplified) — 0100', '1000' => 'B2B (standard) — 1000', '1100' => 'B2B and B2C — 1100'] as $v => $t)
                <option value="{{ $v }}" @selected(old('invoice_types', '0100') === $v)>{{ $t }}</option>
            @endforeach
        </select>
    </label>
    <label class="{{ $label }}">Industry
        <input name="industry" required maxlength="100" value="{{ old('industry') }}" class="{{ $input }}" placeholder="Retail">
    </label>
    <label class="{{ $label }}">Location
        <input name="location" required maxlength="100" value="{{ old('location') }}" class="{{ $input }}" placeholder="Riyadh, Olaya St">
    </label>
    <label class="{{ $label }}">Environment
        <select name="environment" class="{{ $input }}">
            <option value="">Default ({{ config('zatca.environment') }})</option>
            @foreach (['sandbox', 'simulation', 'production'] as $env)
                <option value="{{ $env }}" @selected(old('environment') === $env)>{{ $env }}</option>
            @endforeach
        </select>
    </label>
    <label class="{{ $label }}">Serial (optional)
        <input name="serial" maxlength="100" value="{{ old('serial') }}" class="{{ $input }}">
        <span class="{{ $help }}">Leave empty to generate one.</span>
    </label>
    <div class="sm:col-span-2 flex items-center gap-3">
        <button class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">Register</button>
        <span class="text-xs text-slate-500">No OTP here: you onboard the device on its page afterwards.</span>
    </div>
</form>
@endif
@endsection
