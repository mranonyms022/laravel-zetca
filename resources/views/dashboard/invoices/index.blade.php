@extends('zatca::dashboard.layout')

@php
    $flow = $workflow->value;
    $title = $flow === 'b2b' ? 'B2B · Standard invoices (clearance)' : 'B2C · Simplified invoices (reporting)';
    $input = 'rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900';
@endphp

@section('title', $title)
@section('section', $flow)

@section('content')
<h2 class="mb-4 text-xl font-semibold">{{ $title }}</h2>

<div class="mb-4 flex flex-wrap gap-1 border-b border-slate-200 dark:border-slate-800">
    @foreach ($tabs as $key => $t)
        <a href="{{ $zroute('invoices.index', array_filter(['workflow' => $flow, 'tab' => $key === 'all' ? null : $key] + \Illuminate\Support\Arr::except($filters, ['tab', 'page']))) }}"
           class="-mb-px border-b-2 px-3 py-2 text-sm {{ $tab === $key ? 'border-emerald-600 font-medium text-emerald-700 dark:text-emerald-400' : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200' }}">{{ $t['label'] }}</a>
    @endforeach
</div>

<form method="GET" action="{{ $zroute('invoices.index', ['workflow' => $flow]) }}" class="mb-4 flex flex-wrap items-end gap-2">
    @if ($tab !== 'all')<input type="hidden" name="tab" value="{{ $tab }}">@endif
    <label class="flex flex-col text-xs text-slate-500">Search
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Number, UUID, invoice ID, buyer VAT" class="{{ $input }} w-72">
    </label>
    <label class="flex flex-col text-xs text-slate-500">Device
        <select name="device" class="{{ $input }}">
            <option value="">All</option>
            @foreach ($devices as $d)
                <option value="{{ $d->id }}" @selected(($filters['device'] ?? '') === (string) $d->id)>{{ $d->name }}</option>
            @endforeach
        </select>
    </label>
    <label class="flex flex-col text-xs text-slate-500">Type
        <select name="type" class="{{ $input }}">
            <option value="">All</option>
            @foreach (['388' => 'Invoice', '381' => 'Credit note', '383' => 'Debit note'] as $code => $label)
                <option value="{{ $code }}" @selected(($filters['type'] ?? '') === $code)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="flex flex-col text-xs text-slate-500">From
        <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="{{ $input }}">
    </label>
    <label class="flex flex-col text-xs text-slate-500">To
        <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="{{ $input }}">
    </label>
    <button class="rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">Filter</button>
    <a href="{{ $zroute('invoices.index', ['workflow' => $flow]) }}" class="px-2 py-1.5 text-sm text-slate-500 hover:underline">Reset</a>
</form>

<div class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/60">
        <tr>
            <th class="px-3 py-2">Number</th>
            <th class="px-3 py-2">Type</th>
            <th class="px-3 py-2">Date</th>
            <th class="px-3 py-2">Device</th>
            @if ($flow === 'b2b')<th class="px-3 py-2">Buyer VAT</th>@endif
            <th class="px-3 py-2 text-right">Total (SAR)</th>
            <th class="px-3 py-2 text-right">ICV</th>
            <th class="px-3 py-2">{{ $flow === 'b2b' ? 'Clearance' : 'Reporting' }}</th>
            <th class="px-3 py-2">Processing</th>
            @if ($flow === 'b2c')<th class="px-3 py-2">Deadline</th>@endif
            <th class="px-3 py-2 text-right">Errors</th>
        </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
        @forelse ($invoices as $invoice)
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40">
                <td class="px-3 py-2 font-medium">
                    <a href="{{ $zroute('invoices.show', $invoice->public_id) }}" class="text-emerald-700 hover:underline dark:text-emerald-400">{{ $invoice->invoice_number }}</a>
                </td>
                <td class="px-3 py-2">{{ ['388' => 'Invoice', '381' => 'Credit', '383' => 'Debit'][$invoice->document_type->value] }}</td>
                <td class="px-3 py-2 whitespace-nowrap">{{ $invoice->invoice_date?->format('Y-m-d') }}</td>
                <td class="px-3 py-2">{{ $invoice->device?->name }}</td>
                @if ($flow === 'b2b')<td class="px-3 py-2 font-mono text-xs">{{ $invoice->buyer_vat_number }}</td>@endif
                <td class="px-3 py-2 text-right tabular-nums">{{ $invoice->total_incl_vat !== null ? number_format((float) $invoice->total_incl_vat, 2) : '—' }}</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ $invoice->currentIssuance?->icv ?? '—' }}</td>
                <td class="px-3 py-2">@include('zatca::dashboard.partials.status', ['status' => $flow === 'b2b' ? $invoice->clearance_status : $invoice->reporting_status])</td>
                <td class="px-3 py-2">@include('zatca::dashboard.partials.status', ['status' => $invoice->processing_status])</td>
                @if ($flow === 'b2c')
                    <td class="px-3 py-2 whitespace-nowrap text-xs text-slate-500">{{ $invoice->reporting_deadline_at && ! in_array($invoice->processing_status->value, ['REPORTED', 'COMPLETED', 'CANCELLED', 'ABANDONED'], true) ? $invoice->reporting_deadline_at->diffForHumans() : '' }}</td>
                @endif
                <td class="px-3 py-2 text-right {{ $invoice->unresolved_error_count > 0 ? 'font-semibold text-red-600' : 'text-slate-400' }}">{{ $invoice->unresolved_error_count }}</td>
            </tr>
        @empty
            <tr><td colspan="11" class="px-3 py-10 text-center text-slate-500">No invoices match.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4 flex justify-between text-sm">
    @if ($invoices->previousPageUrl())
        <a href="{{ $invoices->previousPageUrl() }}" class="rounded-md border border-slate-300 px-3 py-1.5 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">← Newer</a>
    @else <span></span> @endif
    @if ($invoices->hasMorePages())
        <a href="{{ $invoices->nextPageUrl() }}" class="rounded-md border border-slate-300 px-3 py-1.5 hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">Older →</a>
    @endif
</div>
@endsection
