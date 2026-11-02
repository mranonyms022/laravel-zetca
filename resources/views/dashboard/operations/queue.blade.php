@extends('zatca::dashboard.layout')

@section('title', 'Queue monitor')
@section('section', 'queue')

@php
    $card = 'rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900';
    $o = $stats['outbox'];
    $over = $o['pending'] >= $stats['high_water_mark'];
@endphp

@section('content')
<h2 class="mb-1 text-xl font-semibold">Queue monitor</h2>
<p class="mb-4 text-sm text-slate-500">Snapshot at {{ now()->toDateTimeString() }}. Reload to refresh. Workers and scheduler setup: see the "Queues and workers" docs page.</p>

@if ($over)
    <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-900/30 dark:text-red-100">Backlog above the high-water mark ({{ $stats['high_water_mark'] }}). Add workers or check that they are running.</div>
@endif

<h3 class="mb-2 text-sm font-semibold">Outbox</h3>
<div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
    @foreach ([
        'Pending' => $o['pending'],
        'Due now' => $o['due'],
        'Being processed' => $o['claimed'],
        'Dead-lettered' => $o['dead'],
        'Oldest pending' => $o['oldest_pending']?->diffForHumans() ?? '—',
    ] as $label => $value)
        <div class="{{ $card }}">
            <div class="text-xs uppercase tracking-wide text-slate-500">{{ $label }}</div>
            <div class="mt-1 text-2xl font-semibold {{ $label === 'Dead-lettered' && $value > 0 ? 'text-red-600' : '' }}">{{ $value }}</div>
        </div>
    @endforeach
</div>
@if ($o['by_queue'] !== [])
    <div class="{{ $card }} mb-6 text-sm">
        <div class="mb-2 text-xs uppercase tracking-wide text-slate-500">Pending by queue</div>
        @foreach ($o['by_queue'] as $queue => $n)
            <div class="flex justify-between"><span class="font-mono">{{ $queue }}</span><span>{{ $n }}</span></div>
        @endforeach
    </div>
@endif

<div class="grid gap-4 lg:grid-cols-2">
    @foreach (['b2b' => 'B2B clearance submissions', 'b2c' => 'B2C reporting submissions'] as $key => $title)
        <div class="{{ $card }} text-sm">
            <h3 class="mb-2 font-semibold">{{ $title }}</h3>
            @forelse ($stats[$key]['by_status'] as $status => $n)
                <div class="flex justify-between py-0.5"><span>@include('zatca::dashboard.partials.status', ['status' => $status])</span><span class="font-mono">{{ $n }}</span></div>
            @empty
                <p class="text-slate-500">Nothing submitted yet.</p>
            @endforelse
            <div class="mt-3 border-t border-slate-100 pt-2 text-xs text-slate-500 dark:border-slate-800">
                Oldest open: {{ $stats[$key]['oldest_open']?->diffForHumans() ?? '—' }}
                @if ($key === 'b2c')
                    · Past the 24-hour deadline: <span class="{{ $stats['b2c']['deadline_breached'] > 0 ? 'font-semibold text-red-600' : '' }}">{{ $stats['b2c']['deadline_breached'] }}</span>
                @endif
            </div>
        </div>
    @endforeach
</div>
@endsection
