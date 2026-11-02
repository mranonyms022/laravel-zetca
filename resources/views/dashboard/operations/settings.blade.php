@extends('zatca::dashboard.layout')

@section('title', 'Settings')
@section('section', 'settings')

@section('content')
<h2 class="mb-1 text-xl font-semibold">Settings</h2>
<p class="mb-4 max-w-3xl text-sm text-slate-500">
    Read-only view of the active configuration. Change values in <code>config/zatca.php</code> or <code>.env</code> and deploy,
    so every change is reviewed and versioned. Keys, credentials and certificates are never shown here.
</p>

<div class="grid gap-4 lg:grid-cols-2">
    @foreach ($sections as $section => $values)
        <div class="rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <h3 class="border-b border-slate-100 px-4 py-2 text-sm font-semibold dark:border-slate-800">{{ $section }}</h3>
            <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                @foreach ($values as $key => $value)
                    <div class="flex justify-between gap-4 px-4 py-1.5"><dt class="font-mono text-xs text-slate-500">zatca.{{ $key }}</dt><dd class="text-right font-mono text-xs break-all">{{ $value }}</dd></div>
                @endforeach
            </dl>
        </div>
    @endforeach
</div>
@endsection
