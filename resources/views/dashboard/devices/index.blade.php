@extends('zatca::dashboard.layout')

@section('title', 'Devices')
@section('section', 'devices')

@php
    $S = \CodingTriplets\Zatca\Core\Domain\Enums\DeviceLifecycleStatus::class;
    $card = 'overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900';
    $input = 'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950';
    $primary = 'inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-700';
    $secondary = 'inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800';
    $th = 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500';
    $td = 'px-4 py-3 align-middle';
    $canManage = $can(\CodingTriplets\Zatca\Laravel\Dashboard\Abilities::DEVICES_ONBOARD);
@endphp

@section('content')
<section class="{{ $card }}">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
        <div>
            <h2 class="text-base font-semibold">Devices</h2>
            <p class="text-xs text-slate-500">EGS units (POS terminals, servers) that sign invoices. {{ number_format($devices->total()) }} found.</p>
        </div>
        @if ($canManage)
            <div class="flex gap-2">
                <a href="{{ $zroute('devices.import') }}" class="{{ $secondary }}">Import from file</a>
                <a href="{{ $zroute('devices.create') }}" class="{{ $primary }}">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                    Register device
                </a>
            </div>
        @endif
    </div>

    <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-slate-200 bg-slate-50 px-5 py-3 dark:border-slate-800 dark:bg-slate-800/30">
        <label class="flex flex-col gap-1 text-xs font-medium text-slate-500">Search
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Name, UUID, serial" class="{{ $input }} w-60">
        </label>
        <label class="flex flex-col gap-1 text-xs font-medium text-slate-500">Status
            <select name="status" class="{{ $input }}">
                <option value="">All</option>
                @foreach ($S::cases() as $s)
                    <option value="{{ $s->value }}" @selected(($filters['status'] ?? '') === $s->value)>{{ str_replace('_', ' ', $s->value) }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex flex-col gap-1 text-xs font-medium text-slate-500">Environment
            <select name="environment" class="{{ $input }}">
                <option value="">All</option>
                @foreach (['sandbox', 'simulation', 'production'] as $env)
                    <option value="{{ $env }}" @selected(($filters['environment'] ?? '') === $env)>{{ $env }}</option>
                @endforeach
            </select>
        </label>
        <button class="{{ $secondary }}">Filter</button>
        @if (array_filter($filters ?? []))
            <a href="{{ $zroute('devices.index') }}" class="py-2 text-sm text-slate-500 hover:underline">Clear</a>
        @endif
    </form>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
            <thead class="bg-slate-50 dark:bg-slate-800/50"><tr>
                <th class="{{ $th }}">Device</th><th class="{{ $th }}">Company / store</th><th class="{{ $th }}">Types</th>
                <th class="{{ $th }}">Environment</th><th class="{{ $th }}">Status</th><th class="{{ $th }}">Certificate expires</th>
                @if ($canManage)<th class="{{ $th }} text-right">Actions</th>@endif
            </tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            @forelse ($devices as $device)
                @php
                    $status = $device->lifecycle_status;
                    $decommissioned = $status === $S::Decommissioned;
                @endphp
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40 {{ $status === $S::Suspended || $decommissioned ? 'opacity-70' : '' }}">
                    <td class="{{ $td }}">
                        <a href="{{ $zroute('devices.show', $device->id) }}" class="font-medium text-emerald-700 hover:underline dark:text-emerald-400">{{ $device->name }}</a>
                        <div class="font-mono text-xs text-slate-500">#{{ $device->id }}</div>
                    </td>
                    <td class="{{ $td }}">{{ $device->company?->name_en }}<div class="text-xs text-slate-500">{{ $device->branch?->name }} {{ $device->branch ? '('.$device->branch->code.')' : '' }}</div></td>
                    <td class="{{ $td }}">{{ ['1000' => 'B2B', '0100' => 'B2C', '1100' => 'B2B + B2C'][$device->invoice_type_flags] ?? $device->invoice_type_flags }}</td>
                    <td class="{{ $td }}">{{ $device->environment->value }}</td>
                    <td class="{{ $td }}">@include('zatca::dashboard.partials.status', ['status' => $status])</td>
                    <td class="{{ $td }} whitespace-nowrap {{ $device->certificate_expiry && $device->certificate_expiry->isBefore(now()->addDays(30)) ? 'font-semibold text-amber-600' : '' }}">{{ $device->certificate_expiry?->format('Y-m-d') ?? '—' }}</td>
                    @if ($canManage)
                        <td class="{{ $td }}">
                            @include('zatca::dashboard.partials.row-actions', [
                                'name' => $device->name,
                                'edit' => $decommissioned ? null : $zroute('devices.edit', $device->id),
                                'enabled' => $status !== $S::Suspended,
                                'toggle' => $decommissioned ? null : ['enable' => $zroute('devices.toggle', [$device->id, 'enable']), 'disable' => $zroute('devices.toggle', [$device->id, 'disable'])],
                                'delete' => $decommissioned ? null : $zroute('devices.destroy', $device->id),
                                'confirm' => "Delete device {$device->name}? A device that has issued invoices or holds a production certificate is decommissioned instead (it can never issue again).",
                            ])
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-12 text-center text-slate-500">No devices.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($devices->hasPages())
        <div class="border-t border-slate-200 px-5 py-3 dark:border-slate-800">{{ $devices->links('zatca::dashboard.partials.pager') }}</div>
    @endif
</section>
@endsection
