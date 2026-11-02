@extends('zatca::dashboard.layout')

@section('title', 'Dashboard')
@section('section', 'home')

@section('content')
@php
    $card = 'rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900';
    $periods = ['1' => 'Today', '7' => '7 days', '30' => '30 days', 'all' => 'All time'];
@endphp

<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <h2 class="text-xl font-semibold">Overview</h2>
    <div class="flex overflow-hidden rounded-md border border-slate-200 text-sm dark:border-slate-700">
        @foreach ($periods as $key => $label)
            <a href="{{ $zroute('home', ['period' => $key]) }}"
               class="px-3 py-1.5 {{ $period === (string) $key ? 'bg-emerald-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300' }}">{{ $label }}</a>
        @endforeach
    </div>
</div>

<div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
    <div class="{{ $card }}">
        <div class="text-xs uppercase tracking-wide text-slate-500">Unresolved errors</div>
        <div class="mt-1 text-2xl font-semibold {{ $stats['unresolved_errors'] > 0 ? 'text-red-600' : '' }}">{{ number_format($stats['unresolved_errors']) }}</div>
    </div>
    <div class="{{ $card }}">
        <div class="text-xs uppercase tracking-wide text-slate-500">Active devices</div>
        <div class="mt-1 text-2xl font-semibold">{{ number_format($stats['devices']['ACTIVE'] ?? 0) }}</div>
        <div class="text-xs text-slate-500">of {{ number_format(array_sum($stats['devices'])) }} registered</div>
    </div>
    <div class="{{ $card }}">
        <div class="text-xs uppercase tracking-wide text-slate-500">Certificates expiring &lt; 30 days</div>
        <div class="mt-1 text-2xl font-semibold {{ $stats['expiring_certificates'] > 0 ? 'text-amber-600' : '' }}">{{ number_format($stats['expiring_certificates']) }}</div>
    </div>
    <div class="{{ $card }}">
        <div class="text-xs uppercase tracking-wide text-slate-500">B2C deadline &lt; 4 h</div>
        <div class="mt-1 text-2xl font-semibold {{ $stats['deadline_at_risk'] > 0 ? 'text-red-600' : '' }}">{{ number_format($stats['deadline_at_risk']) }}</div>
    </div>
</div>

<div class="grid gap-4 lg:grid-cols-2">
    @foreach (['b2b' => 'B2B · Clearance', 'b2c' => 'B2C · Reporting'] as $flow => $title)
        @if ($can(\CodingTriplets\Zatca\Laravel\Dashboard\Abilities::view($flow)))
            @php $s = $stats[$flow]; @endphp
            <section class="{{ $card }}">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="font-semibold">{{ $title }}</h3>
                    <a href="{{ $zroute('invoices.index', ['workflow' => $flow]) }}" class="text-sm text-emerald-700 hover:underline dark:text-emerald-400">Open list →</a>
                </div>
                <div class="text-3xl font-semibold">{{ number_format($s['total']) }} <span class="text-sm font-normal text-slate-500">documents</span></div>
                <dl class="mt-4 grid grid-cols-3 gap-2 text-sm">
                    @foreach (\CodingTriplets\Zatca\Laravel\Dashboard\Queries\InvoiceListQuery::tabs(\CodingTriplets\Zatca\Core\Domain\Enums\Workflow::from($flow)) as $key => $tab)
                        @continue($key === 'all')
                        <a href="{{ $zroute('invoices.index', ['workflow' => $flow, 'tab' => $key]) }}" class="rounded-md bg-slate-50 p-2 hover:bg-slate-100 dark:bg-slate-800 dark:hover:bg-slate-700">
                            <dt class="text-xs text-slate-500">{{ $tab['label'] }}</dt>
                            <dd class="text-lg font-medium {{ in_array($key, ['rejected', 'failed', 'manual'], true) && $s[$key] > 0 ? 'text-red-600' : '' }}">{{ number_format($s[$key]) }}</dd>
                        </a>
                    @endforeach
                </dl>
                <div class="mt-4">
                    @include('zatca::dashboard.partials.daily-chart', [
                        'days' => $daily[$flow],
                        'title' => 'Last 14 days',
                        'labels' => ['accepted' => $flow === 'b2b' ? 'Cleared' : 'Reported', 'pending' => 'Pending', 'problem' => 'Rejected / failed'],
                    ])
                </div>
            </section>
        @endif
    @endforeach
</div>
@endsection
