@extends('zatca::dashboard.layout')

@section('title', 'Errors')
@section('section', 'errors')

@php
    $input = 'rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900';
    $canResolve = $can(\CodingTriplets\Zatca\Laravel\Dashboard\Abilities::ERRORS_RESOLVE);
@endphp

@section('content')
<h2 class="mb-3 text-xl font-semibold">Errors &amp; warnings</h2>
@include('zatca::dashboard.errors.tabs', ['active' => 'errors.index'])

<form method="GET" class="mb-4 flex flex-wrap items-end gap-2">
    <label class="flex flex-col text-xs text-slate-500">State
        <select name="state" class="{{ $input }}">
            @foreach (['open' => 'Open', 'resolved' => 'Resolved', 'all' => 'All'] as $v => $t)
                <option value="{{ $v }}" @selected($state === $v)>{{ $t }}</option>
            @endforeach
        </select>
    </label>
    <label class="flex flex-col text-xs text-slate-500">Category
        <select name="category" class="{{ $input }}">
            <option value="">All</option>
            @foreach (\CodingTriplets\Zatca\Core\Domain\Enums\ErrorCategory::cases() as $c)
                <option value="{{ $c->value }}" @selected(($filters['category'] ?? '') === $c->value)>{{ str_replace('_', ' ', $c->value) }}</option>
            @endforeach
        </select>
    </label>
    <label class="flex flex-col text-xs text-slate-500">Severity
        <select name="severity" class="{{ $input }}">
            <option value="">All</option>
            @foreach (\CodingTriplets\Zatca\Core\Domain\Enums\Severity::cases() as $s)
                <option value="{{ $s->value }}" @selected(($filters['severity'] ?? '') === $s->value)>{{ $s->value }}</option>
            @endforeach
        </select>
    </label>
    <label class="flex flex-col text-xs text-slate-500">Workflow
        <select name="workflow" class="{{ $input }}">
            <option value="">All</option><option value="b2b" @selected(($filters['workflow'] ?? '') === 'b2b')>B2B</option><option value="b2c" @selected(($filters['workflow'] ?? '') === 'b2c')>B2C</option>
        </select>
    </label>
    <label class="flex flex-col text-xs text-slate-500">Code
        <input name="code" value="{{ $filters['code'] ?? '' }}" class="{{ $input }} w-32 font-mono">
    </label>
    @if (! empty($filters['group']))<input type="hidden" name="group" value="{{ $filters['group'] }}">@endif
    <button class="rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">Filter</button>
</form>

<div class="space-y-2">
@forelse ($errors_ as $error)
    <div class="rounded-lg border border-slate-200 bg-white p-3 text-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-center gap-2">
            @include('zatca::dashboard.partials.status', ['status' => $error->severity])
            <span class="font-mono text-xs">{{ $error->code ?? '—' }}</span>
            <span class="text-xs text-slate-500">{{ str_replace('_', ' ', $error->category instanceof \BackedEnum ? $error->category->value : (string) $error->category) }} · {{ strtoupper((string) $error->workflow?->value) }} · {{ $error->created_at?->toDateTimeString() }} · #{{ $error->id }}</span>
            @if ($error->invoice)
                <a href="{{ $zroute('invoices.show', $error->invoice->public_id) }}" class="text-xs text-emerald-700 hover:underline dark:text-emerald-400">{{ $error->invoice->invoice_number }}</a>
            @endif
            @if ($error->is_resolved)<span class="text-xs font-medium text-emerald-700 dark:text-emerald-400">resolved</span>@endif
        </div>
        <p class="mt-1 break-words">{{ $error->message }}</p>
        @if ($error->is_resolved && $error->resolution_note)
            <p class="mt-1 text-xs text-slate-500">Note: {{ $error->resolution_note }}</p>
        @elseif (! $error->is_resolved && $canResolve)
            <details class="mt-2">
                <summary class="cursor-pointer text-xs text-slate-500">Mark as resolved</summary>
                <form method="POST" action="{{ $zroute('errors.resolve', $error->id) }}" class="mt-2 flex flex-wrap gap-2">
                    @csrf
                    <input name="note" required maxlength="1000" placeholder="What was done (required)" class="{{ $input }} w-80">
                    <button class="rounded-md bg-slate-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-800">Resolve</button>
                </form>
                <p class="mt-1 text-xs text-slate-500">Only acknowledges the error. It does not retry or change the invoice.</p>
            </details>
        @endif
    </div>
@empty
    <p class="rounded-lg border border-slate-200 bg-white px-4 py-10 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-900">No errors.</p>
@endforelse
</div>
<div class="mt-4">{{ $errors_->links('zatca::dashboard.partials.pager') }}</div>
@endsection
