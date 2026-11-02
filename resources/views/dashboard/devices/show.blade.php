@extends('zatca::dashboard.layout')

@section('title', $device->name)
@section('section', 'devices')

@php
    $card = 'rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900';
    $th = 'px-3 py-2 text-left text-xs uppercase tracking-wide text-slate-500';
    $td = 'px-3 py-2 align-top';
    $status = $device->lifecycle_status->value;
    $canOnboard = $can(\CodingTriplets\Zatca\Laravel\Dashboard\Abilities::DEVICES_ONBOARD);
    $canRenew = $can(\CodingTriplets\Zatca\Laravel\Dashboard\Abilities::DEVICES_RENEW);
    $otpInput = 'w-32 rounded-md border border-slate-300 px-2.5 py-1.5 font-mono text-sm tracking-widest dark:border-slate-700 dark:bg-slate-950';
@endphp

@section('content')
<div class="mb-4 text-sm"><a href="{{ $zroute('devices.index') }}" class="text-slate-500 hover:underline">← Devices</a></div>

<div class="mb-5 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h2 class="text-xl font-semibold">{{ $device->name }}</h2>
        <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
            @include('zatca::dashboard.partials.status', ['status' => $device->lifecycle_status])
            <span>{{ $device->environment->value }}</span><span>·</span><span class="font-mono text-xs">{{ $device->device_uuid }}</span>
        </div>
    </div>
</div>

@if ($canOnboard && in_array($status, ['REGISTERED', 'KEY_GENERATED', 'COMPLIANCE_CSID_ISSUED', 'ONBOARDING_FAILED'], true))
    <form method="POST" action="{{ $zroute('devices.onboard', $device->id) }}" autocomplete="off" class="{{ $card }} mb-4 flex flex-wrap items-end gap-3 p-4">
        @csrf
        <label class="text-sm">
            <span class="block font-medium">Onboard with an OTP</span>
            <span class="block text-xs text-slate-500">Generate it in the Fatoora portal for this device ({{ $device->environment->value }}; sandbox: 123345). It is used once and never stored.</span>
            <input type="text" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code" class="{{ $otpInput }} mt-2">
        </label>
        <button class="rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">Onboard</button>
        <span class="text-xs text-slate-500">Takes a few seconds: keys, certificates and compliance checks.</span>
    </form>
@endif

@if ($canOnboard && in_array($status, ['COMPLIANCE_CHECKING', 'COMPLIANCE_CSID_ISSUED'], true))
    <form method="POST" action="{{ $zroute('devices.resume', $device->id) }}" class="{{ $card }} mb-4 flex items-center gap-3 p-4">
        @csrf
        <span class="text-sm">Compliance checks did not pass. Fix the cause (usually the seller address or VAT number), then</span>
        <button class="rounded-md bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-700">Resume onboarding</button>
    </form>
@endif

@if ($canRenew && in_array($status, ['ACTIVE', 'RENEWAL_DUE', 'RENEWAL_FAILED', 'EXPIRED'], true))
    <details class="{{ $card }} mb-4">
        <summary class="cursor-pointer px-4 py-3 text-sm font-medium">Renew the production certificate</summary>
        <form method="POST" action="{{ $zroute('devices.renew', $device->id) }}" autocomplete="off" class="flex flex-wrap items-end gap-3 border-t border-slate-100 p-4 dark:border-slate-800">
            @csrf
            <label class="text-sm"><span class="block text-xs text-slate-500">New OTP from the Fatoora portal</span>
                <input type="text" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code" class="{{ $otpInput }} mt-1">
            </label>
            <button class="rounded-md bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-700">Renew</button>
        </form>
    </details>
@endif

<div class="mb-6 grid gap-4 lg:grid-cols-2">
    <dl class="{{ $card }} divide-y divide-slate-100 text-sm dark:divide-slate-800">
        @foreach ([
            'Company' => $device->company?->name_en,
            'VAT number' => $device->vatRegistration?->vat_number,
            'Legal name' => $device->vatRegistration?->legal_name,
            'Store' => $device->branch ? $device->branch->name.' ('.$device->branch->code.')' : null,
            'Invoice types' => ['1000' => 'B2B (standard)', '0100' => 'B2C (simplified)', '1100' => 'B2B and B2C'][$device->invoice_type_flags] ?? $device->invoice_type_flags,
            'Industry' => $device->industry,
            'Location' => $device->location,
            'Serial number' => $device->serial_number,
            'Certificate expires' => $device->certificate_expiry?->toDateTimeString(),
            'Submissions paused until' => $device->submissions_paused_until?->toDateTimeString(),
        ] as $label => $value)
            @continue($value === null || $value === '')
            <div class="flex justify-between gap-4 px-4 py-2"><dt class="text-slate-500">{{ $label }}</dt><dd class="text-right font-medium break-all">{{ $value }}</dd></div>
        @endforeach
    </dl>
    <div class="{{ $card }} p-4 text-sm">
        <h3 class="mb-2 font-semibold">Invoice counter</h3>
        @forelse ($counters as $counter)
            <div class="flex justify-between"><span class="text-slate-500">{{ $counter->environment instanceof \BackedEnum ? $counter->environment->value : $counter->environment }}</span><span class="font-mono">last ICV {{ $counter->last_icv }}</span></div>
        @empty
            <p class="text-slate-500">No invoices issued yet.</p>
        @endforelse
    </div>
</div>

<h3 class="mb-2 text-sm font-semibold">Certificates</h3>
<div class="{{ $card }} mb-6 overflow-x-auto">
    <table class="min-w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-800/60"><tr><th class="{{ $th }}">Type</th><th class="{{ $th }}">Status</th><th class="{{ $th }}">Serial</th><th class="{{ $th }}">Valid from</th><th class="{{ $th }}">Valid until</th><th class="{{ $th }}">Fingerprint</th></tr></thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
        @forelse ($certificates as $c)
            <tr>
                <td class="{{ $td }}">{{ $c->type instanceof \BackedEnum ? $c->type->value : $c->type }} · {{ $c->environment instanceof \BackedEnum ? $c->environment->value : $c->environment }}</td>
                <td class="{{ $td }}">{{ $c->status instanceof \BackedEnum ? $c->status->value : $c->status }}</td>
                <td class="{{ $td }} font-mono text-xs">{{ $c->serial }}</td>
                <td class="{{ $td }}">{{ $c->valid_from?->format('Y-m-d') }}</td>
                <td class="{{ $td }}">{{ $c->valid_until?->format('Y-m-d') }}</td>
                <td class="{{ $td }} break-all font-mono text-xs">{{ \Illuminate\Support\Str::limit((string) $c->fingerprint, 24) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-3 py-6 text-center text-slate-500">No certificates yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="grid gap-4 lg:grid-cols-2">
    <div>
        <h3 class="mb-2 text-sm font-semibold">Onboarding attempts</h3>
        <ol class="{{ $card }} divide-y divide-slate-100 text-sm dark:divide-slate-800">
            @forelse ($onboardings as $o)
                <li class="px-4 py-2"><span class="text-slate-500">{{ $o->created_at?->toDateTimeString() }}</span> · <span class="font-medium">{{ $o->status }}</span>
                    @if ($o->current_step) <span class="text-slate-500">at {{ $o->current_step }}</span>@endif
                    @if ($o->error)<div class="text-xs text-red-600">{{ $o->error }}</div>@endif</li>
            @empty
                <li class="px-4 py-4 text-slate-500">None.</li>
            @endforelse
        </ol>
    </div>
    <div>
        <h3 class="mb-2 text-sm font-semibold">Renewals</h3>
        <ol class="{{ $card }} divide-y divide-slate-100 text-sm dark:divide-slate-800">
            @forelse ($renewals as $r)
                <li class="px-4 py-2"><span class="text-slate-500">{{ $r->created_at?->toDateTimeString() }}</span> · <span class="font-medium">{{ $r->status }}</span>
                    @if ($r->error)<div class="text-xs text-red-600">{{ $r->error }}</div>@endif</li>
            @empty
                <li class="px-4 py-4 text-slate-500">None.</li>
            @endforelse
        </ol>
    </div>
</div>
@endsection
