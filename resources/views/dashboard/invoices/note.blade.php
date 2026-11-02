@extends('zatca::dashboard.layout')

@php $title = ($kind === 'credit' ? 'Credit' : 'Debit') . ' note for ' . $invoice->invoice_number; @endphp
@section('title', $title)
@section('section', $invoice->workflow->value)

@php
    $input = 'mt-1 w-full rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-950';
@endphp

@section('content')
<div class="mb-4 text-sm"><a href="{{ $zroute('invoices.show', $invoice->public_id) }}" class="text-slate-500 hover:underline">← {{ $invoice->invoice_number }}</a></div>
<h2 class="mb-1 text-xl font-semibold">{{ $title }}</h2>
<p class="mb-4 max-w-3xl text-sm text-slate-500">
    @if ($kind === 'credit')
        Reduces the invoice (returns, price errors, discounts after the sale). Edit the lines to what is credited.
        At most <strong class="text-slate-700 dark:text-slate-200">{{ $invoice->currency ?? 'SAR' }} {{ $creditable }}</strong> (including VAT) can still be credited.
        To credit everything, use <em>Cancel (credit note)</em> on the invoice instead.
    @else
        Increases the invoice (additional charges, under-billing). Enter the additional lines.
    @endif
    The note is a new {{ strtoupper($invoice->workflow->value) }} document with its own counter and QR; buyer and supply date are copied from the invoice.
</p>

<form method="POST" action="{{ $zroute('invoices.note.store', ['publicId' => $invoice->public_id, 'kind' => $kind]) }}" class="max-w-4xl space-y-4">
    @csrf
    <div class="grid gap-4 sm:grid-cols-2">
        <label class="block text-sm font-medium">Note number
            <input name="invoice_number" required maxlength="191" value="{{ $number }}" class="{{ $input }} font-mono">
        </label>
        <label class="block text-sm font-medium">Reason (required, printed on the note)
            <input name="reason" required maxlength="1000" value="{{ old('reason') }}" class="{{ $input }}" placeholder="{{ $kind === 'credit' ? 'Item returned by the customer' : 'Delivery charge not billed' }}">
        </label>
    </div>
    <label class="block text-sm font-medium">Lines (JSON list, same format as invoice lines)
        <textarea name="lines" required spellcheck="false" class="mt-1 h-80 w-full rounded-md border border-slate-300 bg-white p-3 font-mono text-xs leading-5 dark:border-slate-700 dark:bg-slate-950">{{ $lines }}</textarea>
    </label>
    <button class="rounded-md {{ $kind === 'credit' ? 'bg-red-600 hover:bg-red-700' : 'bg-sky-600 hover:bg-sky-700' }} px-4 py-2 text-sm font-medium text-white">Issue {{ $kind }} note</button>
    <span class="ml-2 text-xs text-slate-500">The note is signed immediately and then sent to ZATCA; it cannot be withdrawn.</span>
</form>
@endsection
