@extends('zatca::dashboard.layout')

@section('title', 'Correct ' . $invoice->invoice_number)
@section('section', $invoice->workflow->value)

@php
    $card = 'rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900';
    $area = 'h-[32rem] w-full rounded-md border border-slate-300 bg-white p-3 font-mono text-xs leading-5 dark:border-slate-700 dark:bg-slate-950';
    $check = session('zatca_check');
    $signed = $invoice->current_issuance_id !== null;
@endphp

@section('content')
<div class="mb-4 text-sm"><a href="{{ $zroute('invoices.show', $invoice->public_id) }}" class="text-slate-500 hover:underline">← {{ $invoice->invoice_number }}</a></div>

<h2 class="mb-1 text-xl font-semibold">Correct {{ $invoice->invoice_number }}</h2>
<div class="mb-4 flex flex-wrap items-center gap-2 text-sm text-slate-500">
    <span class="rounded bg-slate-800 px-1.5 py-0.5 text-xs font-semibold uppercase text-white">{{ $invoice->workflow->value }}</span>
    @include('zatca::dashboard.partials.status', ['status' => $invoice->processing_status])
    <span>current version v{{ $current->version }}</span>
</div>

<div class="{{ $card }} mb-4 p-4 text-sm">
    <p class="font-medium">What happens when you save</p>
    <ul class="mt-1 list-disc space-y-0.5 pl-5 text-slate-600 dark:text-slate-300">
        <li>A new JSON version is stored with your reason. Earlier versions, including the original, are never changed.</li>
        @if ($signed)
            <li>The rejected document stays in the device's chain. The correction is signed as a <strong>new document</strong>: new UUID, the next counter (ICV) and the current previous-hash.</li>
        @else
            <li>The invoice was never signed: the corrected JSON is validated, signed and sent as usual.</li>
        @endif
        @if ($fourEyes)
            <li>Four-eyes is on: another user must approve the correction before it is signed.</li>
        @endif
        <li>The JSON is checked first. If it is invalid, nothing is saved.</li>
    </ul>
</div>

@if ($zatcaErrors->isNotEmpty())
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm dark:border-red-800 dark:bg-red-900/30">
        <p class="mb-1 font-medium text-red-900 dark:text-red-100">Open errors on this invoice</p>
        <ul class="space-y-0.5 text-red-800 dark:text-red-200">
            @foreach ($zatcaErrors as $e)
                <li><span class="font-mono text-xs">{{ $e->code ?? '—' }}</span> {{ $e->message }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if (is_array($check))
    <div class="mb-4 rounded-lg border p-4 text-sm {{ $check['valid'] ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-900/30' : 'border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-900/30' }}" role="status">
        <p class="font-medium">{{ $check['valid'] ? '✓ The corrected JSON is valid. Nothing was saved yet.' : '✗ The corrected JSON is not valid. Nothing was saved.' }}</p>
        @foreach (['errors' => 'Error', 'warnings' => 'Warning'] as $key => $label)
            @if ($check[$key] !== [])
                <ul class="mt-2 space-y-0.5">
                    @foreach ($check[$key] as $item)
                        <li><span class="text-xs font-semibold">{{ $label }}</span> <span class="font-mono text-xs">{{ $item['field'] ?? '' }}</span> {{ $item['message'] ?? '' }} <span class="font-mono text-xs text-slate-500">{{ $item['code'] ?? '' }}</span></li>
                    @endforeach
                </ul>
            @endif
        @endforeach
    </div>
@endif

<form method="POST" action="{{ $zroute('invoices.correct.update', $invoice->public_id) }}">
    @csrf
    <div class="grid gap-4 lg:grid-cols-2">
        <div>
            <div class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-500">Current JSON (v{{ $current->version }}, read-only)</div>
            <textarea readonly class="{{ $area }} bg-slate-50 text-slate-500 dark:bg-slate-900" aria-label="Current JSON">{{ json_encode($current->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</textarea>
        </div>
        <div>
            <label for="payload" class="mb-1 block text-xs font-medium uppercase tracking-wide text-slate-500">Corrected JSON (the full invoice)</label>
            <textarea id="payload" name="payload" required spellcheck="false" class="{{ $area }}">{{ $draft }}</textarea>
        </div>
    </div>

    <label class="mt-4 block text-sm font-medium">Reason (required, recorded in the history and audit log)
        <input name="reason" maxlength="1000" value="{{ old('reason') }}" class="mt-1 w-full rounded-md border border-slate-300 px-2.5 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-950" placeholder="e.g. Buyer VAT number corrected after customer confirmation">
    </label>

    <div class="mt-4 flex flex-wrap gap-2">
        <button name="intent" value="validate" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">Check only</button>
        <button name="intent" value="save" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">Save correction{{ $fourEyes ? ' (for approval)' : ' and send' }}</button>
    </div>
</form>

<h3 class="mb-2 mt-8 text-sm font-semibold">Versions</h3>
<ol class="{{ $card }} divide-y divide-slate-100 text-sm dark:divide-slate-800">
    @foreach ($versions as $v)
        <li class="px-4 py-2">v{{ $v->version }} · {{ $v->kind->value }} · {{ $v->created_at?->toDateTimeString() }}@if ($v->created_by) · user #{{ $v->created_by }}@endif
            @if ($v->reason)<span class="text-slate-500"> — “{{ $v->reason }}”</span>@endif</li>
    @endforeach
</ol>
@endsection
