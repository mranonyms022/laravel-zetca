@extends('zatca::dashboard.layout')

@section('title', 'Devices')
@section('section', 'devices')

@php
    $input = 'rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900';
    $btn = 'rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700';
    $th = 'px-3 py-2 text-left text-xs uppercase tracking-wide text-slate-500';
@endphp

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h2 class="text-xl font-semibold">Devices</h2>
    @if ($can(\CodingTriplets\Zatca\Laravel\Dashboard\Abilities::DEVICES_ONBOARD))
        <div class="flex gap-2">
            <a href="{{ $zroute('devices.create') }}" class="{{ $btn }}">Register device</a>
            <a href="{{ $zroute('devices.import') }}" class="rounded-md border border-emerald-600 px-3 py-1.5 text-sm font-medium text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-900/30">Import from file</a>
        </div>
    @endif
</div>

<form method="GET" class="mb-4 flex flex-wrap items-end gap-2">
    <label class="flex flex-col text-xs text-slate-500">Search
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name, UUID, serial" class="{{ $input }} w-60">
    </label>
    <label class="flex flex-col text-xs text-slate-500">Status
        <select name="status" class="{{ $input }}">
            <option value="">All</option>
            @foreach (\CodingTriplets\Zatca\Core\Domain\Enums\DeviceLifecycleStatus::cases() as $s)
                <option value="{{ $s->value }}" @selected(($filters['status'] ?? '') === $s->value)>{{ str_replace('_', ' ', $s->value) }}</option>
            @endforeach
        </select>
    </label>
    <label class="flex flex-col text-xs text-slate-500">Environment
        <select name="environment" class="{{ $input }}">
            <option value="">All</option>
            @foreach (['sandbox', 'simulation', 'production'] as $env)
                <option value="{{ $env }}" @selected(($filters['environment'] ?? '') === $env)>{{ $env }}</option>
            @endforeach
        </select>
    </label>
    <button class="{{ $btn }}">Filter</button>
</form>

<div class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="min-w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-800/60"><tr>
            <th class="{{ $th }}">Device</th><th class="{{ $th }}">Company / store</th><th class="{{ $th }}">Types</th>
            <th class="{{ $th }}">Environment</th><th class="{{ $th }}">Status</th><th class="{{ $th }}">Certificate expires</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
        @forelse ($devices as $device)
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                <td class="px-3 py-2"><a href="{{ $zroute('devices.show', $device->id) }}" class="font-medium text-emerald-700 hover:underline dark:text-emerald-400">{{ $device->name }}</a>
                    <div class="font-mono text-xs text-slate-500">#{{ $device->id }}</div></td>
                <td class="px-3 py-2">{{ $device->company?->name_en }}<div class="text-xs text-slate-500">{{ $device->branch?->name }} {{ $device->branch ? '('.$device->branch->code.')' : '' }}</div></td>
                <td class="px-3 py-2">{{ ['1000' => 'B2B', '0100' => 'B2C', '1100' => 'B2B + B2C'][$device->invoice_type_flags] ?? $device->invoice_type_flags }}</td>
                <td class="px-3 py-2">{{ $device->environment->value }}</td>
                <td class="px-3 py-2">@include('zatca::dashboard.partials.status', ['status' => $device->lifecycle_status])</td>
                <td class="px-3 py-2 whitespace-nowrap {{ $device->certificate_expiry && $device->certificate_expiry->isBefore(now()->addDays(30)) ? 'font-semibold text-amber-600' : '' }}">{{ $device->certificate_expiry?->format('Y-m-d') ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-3 py-10 text-center text-slate-500">No devices.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $devices->links('zatca::dashboard.partials.pager') }}</div>
@endsection
