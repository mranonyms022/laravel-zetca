<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ config('zatca.ui.theme') === 'dark' ? 'dark' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Dashboard') · ZATCA</title>
    <link rel="stylesheet" href="{{ $zroute('asset', 'zatca.css') }}?v={{ \CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers\AssetController::version('zatca.css') }}">
    @if (config('zatca.ui.theme') === 'auto')
        <script>if (window.matchMedia('(prefers-color-scheme: dark)').matches) document.documentElement.classList.add('dark');</script>
    @endif
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased dark:bg-slate-950 dark:text-slate-200">
@php
    $environment = (string) config('zatca.environment');
    $envClass = match ($environment) {
        'production' => 'bg-red-600 text-white',
        'simulation' => 'bg-amber-500 text-white',
        default => 'bg-sky-600 text-white',
    };
    $nav = [
        ['home', [], 'Dashboard', \CodingTriplets\Zatca\Laravel\Dashboard\Abilities::DASHBOARD_VIEW, 'home'],
        ['invoices.index', ['workflow' => 'b2b'], 'B2B · Clearance', \CodingTriplets\Zatca\Laravel\Dashboard\Abilities::B2B_VIEW, 'b2b'],
        ['invoices.index', ['workflow' => 'b2c'], 'B2C · Reporting', \CodingTriplets\Zatca\Laravel\Dashboard\Abilities::B2C_VIEW, 'b2c'],
        ['devices.index', [], 'Devices', \CodingTriplets\Zatca\Laravel\Dashboard\Abilities::DEVICES_VIEW, 'devices'],
        ['companies.index', [], 'Companies & stores', \CodingTriplets\Zatca\Laravel\Dashboard\Abilities::COMPANIES_VIEW, 'companies'],
        ['errors.index', [], 'Errors & warnings', \CodingTriplets\Zatca\Laravel\Dashboard\Abilities::ERRORS_VIEW, 'errors'],
        ['queue', [], 'Queue monitor', \CodingTriplets\Zatca\Laravel\Dashboard\Abilities::QUEUE_VIEW, 'queue'],
        ['audit', [], 'Audit history', \CodingTriplets\Zatca\Laravel\Dashboard\Abilities::AUDIT_VIEW, 'audit'],
        ['settings', [], 'Settings', \CodingTriplets\Zatca\Laravel\Dashboard\Abilities::SETTINGS_MANAGE, 'settings'],
    ];
    $section = trim($__env->yieldContent('section', 'home'));
@endphp
<div class="flex min-h-screen">
    <aside class="hidden w-60 shrink-0 border-r border-slate-200 bg-white md:block dark:border-slate-800 dark:bg-slate-900">
        <div class="flex h-14 items-center gap-2 border-b border-slate-200 px-5 dark:border-slate-800">
            <span class="inline-flex h-7 w-7 items-center justify-center rounded-md bg-emerald-600 text-xs font-bold text-white">Z</span>
            <span class="font-semibold">ZATCA</span>
        </div>
        <nav class="space-y-1 p-3 text-sm">
            @foreach ($nav as [$name, $params, $label, $ability, $key])
                @if ($can($ability))
                    <a href="{{ $zroute($name, $params) }}"
                       class="block rounded-md px-3 py-2 {{ $section === $key ? 'bg-emerald-50 font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' }}">{{ $label }}</a>
                @endif
            @endforeach
        </nav>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        <header class="flex h-14 items-center justify-between border-b border-slate-200 bg-white px-4 md:px-6 dark:border-slate-800 dark:bg-slate-900">
            <nav class="flex gap-3 overflow-x-auto whitespace-nowrap text-sm md:hidden">
                @foreach ($nav as [$name, $params, $label, $ability, $key])
                    @if ($can($ability))
                        <a href="{{ $zroute($name, $params) }}" class="{{ $section === $key ? 'font-semibold text-emerald-700' : 'text-slate-600' }}">{{ explode(' ', $label)[0] }}</a>
                    @endif
                @endforeach
            </nav>
            <h1 class="hidden text-sm font-medium text-slate-500 md:block">@yield('title', 'Dashboard')</h1>
            <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide {{ $envClass }}">{{ $environment }}</span>
        </header>

        <main class="mx-auto w-full max-w-7xl flex-1 p-4 md:p-6">
            @if (session('zatca_status'))
                <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-100" role="status">{{ session('zatca_status') }}</div>
            @endif
            @if (session('zatca_error'))
                <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-900/30 dark:text-red-100" role="alert">{{ session('zatca_error') }}</div>
            @endif
            @if ($errors->any())
                <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-900/30 dark:text-red-100" role="alert">{{ $errors->first() }}</div>
            @endif

            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
