@extends('zatca::dashboard.layout')

@section('title', 'Warnings')
@section('section', 'errors')

@section('content')
<h2 class="mb-3 text-xl font-semibold">Errors &amp; warnings</h2>
@include('zatca::dashboard.errors.tabs', ['active' => 'errors.warnings'])
<p class="mb-3 text-sm text-slate-500">Warnings never block an invoice: ZATCA accepted it (or the package made a documented assumption, e.g. VAT category "S" inferred from 15%).</p>

<div class="space-y-2">
@forelse ($warnings as $warning)
    <div class="rounded-lg border border-slate-200 bg-white p-3 text-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
            <span class="font-mono text-slate-700 dark:text-slate-300">{{ $warning->code ?? '—' }}</span>
            <span>{{ strtoupper((string) $warning->workflow?->value) }} · {{ $warning->source }} · {{ $warning->created_at?->toDateTimeString() }}</span>
            @if ($warning->invoice)
                <a href="{{ $zroute('invoices.show', $warning->invoice->public_id) }}" class="text-emerald-700 hover:underline dark:text-emerald-400">{{ $warning->invoice->invoice_number }}</a>
            @endif
        </div>
        <p class="mt-1 break-words">{{ $warning->message }}</p>
    </div>
@empty
    <p class="rounded-lg border border-slate-200 bg-white px-4 py-10 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-900">No warnings.</p>
@endforelse
</div>
<div class="mt-4">{{ $warnings->links('zatca::dashboard.partials.pager') }}</div>
@endsection
