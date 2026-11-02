@extends('zatca::dashboard.layout')

@section('title', 'Audit history')
@section('section', 'audit')

@php
    $input = 'rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-900';
    $th = 'px-3 py-2 text-left text-xs uppercase tracking-wide text-slate-500';
@endphp

@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h2 class="text-xl font-semibold">Audit history</h2>
    <form method="POST" action="{{ $zroute('audit.verify') }}">
        @csrf
        <button class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-100 dark:border-slate-700 dark:hover:bg-slate-800">Verify tamper chain</button>
    </form>
</div>
<p class="mb-3 text-sm text-slate-500">Append-only. Every entry is hash-chained to the previous one, so edits or deletions in the database are detected by "Verify".</p>

<form method="GET" class="mb-4 flex flex-wrap items-end gap-2">
    <label class="flex flex-col text-xs text-slate-500">Action starts with
        <input name="action" value="{{ $filters['action'] ?? '' }}" placeholder="invoice." class="{{ $input }} w-44 font-mono">
    </label>
    <label class="flex flex-col text-xs text-slate-500">User ID
        <input name="actor" value="{{ $filters['actor'] ?? '' }}" inputmode="numeric" class="{{ $input }} w-24">
    </label>
    <label class="flex flex-col text-xs text-slate-500">Subject
        <select name="subject" class="{{ $input }}">
            <option value="">All</option>
            @foreach (['invoice', 'device', 'error', 'company'] as $s)
                <option value="{{ $s }}" @selected(($filters['subject'] ?? '') === $s)>{{ $s }}</option>
            @endforeach
        </select>
    </label>
    <button class="rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">Filter</button>
</form>

<div class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="min-w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-800/60"><tr>
            <th class="{{ $th }}">When</th><th class="{{ $th }}">Action</th><th class="{{ $th }}">Subject</th><th class="{{ $th }}">By</th><th class="{{ $th }}">Details</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
        @forelse ($logs as $log)
            <tr class="align-top">
                <td class="px-3 py-2 whitespace-nowrap">{{ $log->created_at?->toDateTimeString() }}</td>
                <td class="px-3 py-2 font-mono text-xs">{{ $log->action }}</td>
                <td class="px-3 py-2 whitespace-nowrap">{{ $log->subject_type }} #{{ $log->subject_id }}</td>
                <td class="px-3 py-2 whitespace-nowrap">{{ $log->actor_id !== null ? 'user '.$log->actor_id : $log->actor_type }}<div class="text-xs text-slate-500">{{ $log->ip }}</div></td>
                <td class="px-3 py-2"><code class="block max-w-xl whitespace-pre-wrap break-all text-xs text-slate-600 dark:text-slate-300">{{ json_encode($log->changes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</code></td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-3 py-10 text-center text-slate-500">No entries.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $logs->links('zatca::dashboard.partials.pager') }}</div>
@endsection
