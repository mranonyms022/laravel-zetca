@extends('zatca::dashboard.layout')

@section('title', 'Edit device')
@section('section', 'devices')

@php
    $card = 'overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900';
    $input = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 disabled:bg-slate-100 disabled:text-slate-500 dark:border-slate-700 dark:bg-slate-950 dark:disabled:bg-slate-800';
    $label = 'block text-xs font-medium text-slate-600 dark:text-slate-300';
@endphp

@section('content')
<a href="{{ $zroute('devices.index') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:hover:text-slate-200">← Devices</a>

<section class="{{ $card }} max-w-2xl">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
        <div>
            <h2 class="text-base font-semibold">{{ $device->name }}</h2>
            <p class="text-xs text-slate-500">Device #{{ $device->id }} · {{ $device->company?->name_en }} · <span class="font-mono">{{ $device->device_uuid }}</span></p>
        </div>
        @include('zatca::dashboard.partials.status', ['status' => $device->lifecycle_status])
    </div>
    <form method="POST" action="{{ $zroute('devices.update', $device->id) }}" class="space-y-4 p-5">
        @csrf
        @method('PUT')
        @unless ($editable)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-100">
                Name, store, industry and location are written into the device certificate when it is onboarded, so they
                can only be changed while the device is REGISTERED or ONBOARDING FAILED. To change them now, register a new
                device and disable or delete this one.
            </div>
        @endunless
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="{{ $label }}">Device name<input name="name" required maxlength="100" value="{{ old('name', $device->name) }}" @disabled(! $editable) class="{{ $input }}"></label>
            <label class="{{ $label }}">Store
                <select name="store_id" @disabled(! $editable) class="{{ $input }}">
                    <option value="">— none (VAT group only) —</option>
                    @foreach ($stores as $store)
                        <option value="{{ $store->id }}" @selected((int) old('store_id', $device->branch_id) === (int) $store->id)>{{ $store->name }} ({{ $store->code }})</option>
                    @endforeach
                </select>
            </label>
            <label class="{{ $label }}">Industry<input name="industry" required maxlength="100" value="{{ old('industry', $device->industry) }}" @disabled(! $editable) class="{{ $input }}"></label>
            <label class="{{ $label }}">Location<input name="location" required maxlength="100" value="{{ old('location', $device->location) }}" @disabled(! $editable) class="{{ $input }}"></label>
        </div>
        <dl class="grid gap-3 rounded-lg bg-slate-50 p-4 text-xs sm:grid-cols-3 dark:bg-slate-800/40">
            <div><dt class="text-slate-500">Invoice types</dt><dd class="font-medium">{{ ['1000' => 'B2B', '0100' => 'B2C', '1100' => 'B2B + B2C'][$device->invoice_type_flags] ?? $device->invoice_type_flags }}</dd></div>
            <div><dt class="text-slate-500">Environment</dt><dd class="font-medium">{{ $device->environment->value }}</dd></div>
            <div><dt class="text-slate-500">Serial</dt><dd class="break-all font-mono">{{ $device->serial_number }}</dd></div>
        </dl>
        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ $zroute('devices.index') }}" class="rounded-lg border border-slate-300 px-3.5 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Cancel</a>
            @if ($editable)
                <button class="rounded-lg bg-emerald-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-700">Save changes</button>
            @endif
        </div>
    </form>
</section>
@endsection
