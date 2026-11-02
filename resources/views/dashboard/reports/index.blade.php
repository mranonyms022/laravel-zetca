@extends('zatca::dashboard.layout')

@section('title', 'Reports')
@section('section', 'reports')

@php
    $input = 'rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900';
    $card = 'rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900';
    $th = 'px-3 py-2 text-left text-xs uppercase tracking-wide text-slate-500';
    $thr = 'px-3 py-2 text-right text-xs uppercase tracking-wide text-slate-500';
    $query = array_filter(['from' => $report->from->toDateString(), 'to' => $report->to->toDateString()] + $filters, static fn ($v) => $v !== null && $v !== '');
    $typeNames = ['388' => 'Invoices', '383' => 'Debit notes', '381' => 'Credit notes'];
    $money = static fn (string $v) => number_format((float) $v, 2);
    $errorMax = max(1, ...array_values($errorsByCategory ?: [0]));
@endphp

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h2 class="text-xl font-semibold">Reports</h2>
    <div class="flex flex-wrap gap-2 text-sm">
        <a href="{{ $zroute('reports.vat', $query) }}" class="rounded-md border border-slate-300 px-3 py-1.5 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">Download VAT summary (CSV)</a>
        @if ($canExport)
            <a href="{{ $zroute('reports.invoices', $query) }}" class="rounded-md border border-slate-300 px-3 py-1.5 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">Download all documents (CSV)</a>
        @endif
    </div>
</div>

<form method="GET" class="mb-5 flex flex-wrap items-end gap-2">
    <label class="flex flex-col text-xs text-slate-500">From<input type="date" name="from" value="{{ $report->from->toDateString() }}" class="{{ $input }}"></label>
    <label class="flex flex-col text-xs text-slate-500">To<input type="date" name="to" value="{{ $report->to->toDateString() }}" class="{{ $input }}"></label>
    <label class="flex flex-col text-xs text-slate-500">Workflow
        <select name="workflow" class="{{ $input }}"><option value="">B2B and B2C</option><option value="b2b" @selected($report->workflow() === 'b2b')>B2B</option><option value="b2c" @selected($report->workflow() === 'b2c')>B2C</option></select>
    </label>
    <label class="flex flex-col text-xs text-slate-500">Company
        <select name="company" class="{{ $input }}"><option value="">All</option>@foreach ($companies as $c)<option value="{{ $c->id }}" @selected(($filters['company'] ?? '') == $c->id)>{{ $c->name_en }}</option>@endforeach</select>
    </label>
    <label class="flex flex-col text-xs text-slate-500">Device
        <select name="device" class="{{ $input }}"><option value="">All</option>@foreach ($deviceOptions as $d)<option value="{{ $d->id }}" @selected(($filters['device'] ?? '') == $d->id)>{{ $d->name }}</option>@endforeach</select>
    </label>
    <button class="rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">Show</button>
    <span class="text-xs text-slate-500">By invoice date · at most {{ \CodingTriplets\Zatca\Laravel\Dashboard\Queries\ReportQuery::MAX_DAYS }} days</span>
</form>

<div class="mb-6 grid gap-4 {{ count($daily) > 1 ? 'xl:grid-cols-2' : '' }}">
    @foreach ($daily as $workflow => $days)
        @include('zatca::dashboard.partials.daily-chart', [
            'days' => $days,
            'title' => $workflow === 'b2b' ? 'B2B · documents per day' : 'B2C · documents per day',
            'labels' => ['accepted' => $workflow === 'b2b' ? 'Cleared' : 'Reported', 'pending' => 'Pending', 'problem' => 'Rejected / failed'],
        ])
    @endforeach
</div>

<h3 class="mb-1 text-sm font-semibold">VAT summary</h3>
<p class="mb-2 text-xs text-slate-500">Only documents accepted by ZATCA (reported / cleared, with or without warnings). Net = invoices + debit notes − credit notes. A working figure for your VAT return — reconcile it with your accounting before filing.</p>
<div class="mb-6 grid gap-4 {{ count($vat) > 1 ? 'xl:grid-cols-2' : '' }}">
    @foreach ($vat as $workflow => $data)
        <div class="{{ $card }} overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/60"><tr><th class="{{ $th }}">{{ strtoupper($workflow) }}</th><th class="{{ $thr }}">Count</th><th class="{{ $thr }}">Excl. VAT</th><th class="{{ $thr }}">VAT</th><th class="{{ $thr }}">Incl. VAT</th></tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($data['rows'] as $type => $row)
                        <tr><td class="px-3 py-2">{{ $typeNames[$type] }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $row['count'] }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $money($row['excl']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $money($row['vat']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $money($row['incl']) }}</td></tr>
                    @endforeach
                    <tr class="font-semibold"><td class="px-3 py-2">Net</td><td></td><td class="px-3 py-2 text-right tabular-nums">{{ $money($data['net']['excl']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $money($data['net']['vat']) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $money($data['net']['incl']) }}</td></tr>
                </tbody>
            </table>
        </div>
    @endforeach
</div>

<div class="grid gap-4 xl:grid-cols-2">
    <div>
        <h3 class="mb-2 text-sm font-semibold">Devices (top 20 by volume)</h3>
        <div class="{{ $card }} overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/60"><tr><th class="{{ $th }}">Device</th><th class="{{ $thr }}">Documents</th><th class="{{ $thr }}">Accepted</th><th class="{{ $thr }}">Rejected / failed</th><th class="{{ $thr }}">Accepted incl. VAT</th></tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($devices as $d)
                    <tr><td class="px-3 py-2"><a href="{{ $zroute('devices.show', $d['device_id']) }}" class="text-emerald-700 hover:underline dark:text-emerald-400">{{ $d['device'] }}</a></td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $d['total'] }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $d['accepted'] }}</td>
                        <td class="px-3 py-2 text-right tabular-nums {{ $d['problem'] > 0 ? 'font-semibold text-red-600' : '' }}">{{ $d['problem'] }}</td><td class="px-3 py-2 text-right tabular-nums">{{ $money($d['incl']) }}</td></tr>
                @empty
                    <tr><td colspan="5" class="px-3 py-6 text-center text-slate-500">No documents in this period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div>
        <h3 class="mb-2 text-sm font-semibold">Errors by category</h3>
        <div class="{{ $card }} space-y-2 p-4 text-sm">
            @forelse ($errorsByCategory as $category => $count)
                <div title="{{ $category }}: {{ $count }}">
                    <div class="flex justify-between text-xs"><span>{{ str_replace('_', ' ', $category) }}</span><span class="tabular-nums text-slate-600 dark:text-slate-300">{{ $count }}</span></div>
                    <div class="mt-1 h-2 rounded-sm bg-slate-100 dark:bg-slate-800"><div class="h-2 rounded-sm bg-sky-600 dark:bg-sky-500" style="width: {{ max(1, round($count / $errorMax * 100)) }}%"></div></div>
                </div>
            @empty
                <p class="text-slate-500">No errors in this period.</p>
            @endforelse
            @if ($errorsByCategory !== [])
                <a href="{{ $zroute('errors.groups') }}" class="inline-block pt-1 text-xs text-emerald-700 hover:underline dark:text-emerald-400">Error groups →</a>
            @endif
        </div>
    </div>
</div>
@endsection
