@extends('zatca::dashboard.layout')

@section('title', 'Error groups')
@section('section', 'errors')

@php
    $th = 'px-3 py-2 text-left text-xs uppercase tracking-wide text-slate-500';
    $canResolve = $can(\CodingTriplets\Zatca\Laravel\Dashboard\Abilities::ERRORS_RESOLVE);
@endphp

@section('content')
<h2 class="mb-3 text-xl font-semibold">Errors &amp; warnings</h2>
@include('zatca::dashboard.errors.tabs', ['active' => 'errors.groups'])
<p class="mb-3 text-sm text-slate-500">Identical errors are grouped (same workflow, category, code and message pattern), so one cause shows up once.</p>

<div class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <table class="min-w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-800/60"><tr>
            <th class="{{ $th }}">Code</th><th class="{{ $th }}">Message</th><th class="{{ $th }}">Open</th><th class="{{ $th }}">Total</th><th class="{{ $th }}">Last seen</th><th class="{{ $th }}"></th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
        @forelse ($groups as $group)
            @php $openCount = (int) ($open[$group->id] ?? 0); @endphp
            <tr class="align-top">
                <td class="px-3 py-2"><span class="font-mono text-xs">{{ $group->code ?? '—' }}</span>
                    <div class="text-xs text-slate-500">{{ strtoupper((string) $group->workflow) }} · {{ str_replace('_', ' ', (string) $group->category) }}</div></td>
                <td class="px-3 py-2 break-words">{{ $group->normalized_message }}</td>
                <td class="px-3 py-2 font-semibold {{ $openCount > 0 ? 'text-red-600' : 'text-slate-400' }}">{{ $openCount }}</td>
                <td class="px-3 py-2">{{ $group->occurrences }}</td>
                <td class="px-3 py-2 whitespace-nowrap">{{ $group->last_seen_at?->diffForHumans() }}</td>
                <td class="px-3 py-2 whitespace-nowrap">
                    <a href="{{ $zroute('errors.index', ['group' => $group->id, 'state' => 'all']) }}" class="text-emerald-700 hover:underline dark:text-emerald-400">View</a>
                    @if ($canResolve && $openCount > 0)
                        <details class="mt-1">
                            <summary class="cursor-pointer text-xs text-slate-500">Resolve all {{ $openCount }}</summary>
                            <form method="POST" action="{{ $zroute('errors.groups.resolve', $group->id) }}" class="mt-1 flex gap-1">
                                @csrf
                                <input name="note" required maxlength="1000" placeholder="Note (required)" class="w-40 rounded-md border border-slate-300 px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-950">
                                <button class="rounded-md bg-slate-700 px-2 py-1 text-xs text-white">OK</button>
                            </form>
                        </details>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="px-3 py-10 text-center text-slate-500">No errors recorded.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $groups->links('zatca::dashboard.partials.pager') }}</div>
@endsection
