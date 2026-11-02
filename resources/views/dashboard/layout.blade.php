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
<body class="min-h-screen bg-slate-100 text-slate-800 antialiased dark:bg-slate-950 dark:text-slate-200">
@php
    $environment = (string) config('zatca.environment');
    $envClass = match ($environment) {
        'production' => 'bg-red-100 text-red-700 ring-red-600/20 dark:bg-red-900/40 dark:text-red-200',
        'simulation' => 'bg-amber-100 text-amber-800 ring-amber-600/20 dark:bg-amber-900/40 dark:text-amber-200',
        default => 'bg-sky-100 text-sky-800 ring-sky-600/20 dark:bg-sky-900/40 dark:text-sky-200',
    };
    // [route, params, label, ability, section key, icon path(s)]
    $nav = [
        ['home', [], 'Dashboard', \CodingTriplets\Zatca\Laravel\Dashboard\Abilities::DASHBOARD_VIEW, 'home', 'M3 12l9-9 9 9M5 10v10h5v-6h4v6h5V10'],
        ['invoices.index', ['workflow' => 'b2b'], 'B2B · Clearance', \CodingTriplets\Zatca\Laravel\Dashboard\Abilities::B2B_VIEW, 'b2b', 'M9 12h6m-6 4h6M7 3h7l5 5v13H7a2 2 0 01-2-2V5a2 2 0 012-2z'],
        ['invoices.index', ['workflow' => 'b2c'], 'B2C · Reporting', \CodingTriplets\Zatca\Laravel\Dashboard\Abilities::B2C_VIEW, 'b2c', 'M3 3h18v4H3zM5 7v14h14V7M9 11h6'],
        ['companies.index', [], 'Companies & stores', \CodingTriplets\Zatca\Laravel\Dashboard\Abilities::COMPANIES_VIEW, 'companies', 'M4 21V5a2 2 0 012-2h8a2 2 0 012 2v16M16 9h2a2 2 0 012 2v10M2 21h20M8 7h4M8 11h4M8 15h4'],
        ['devices.index', [], 'Devices', \CodingTriplets\Zatca\Laravel\Dashboard\Abilities::DEVICES_VIEW, 'devices', 'M4 5h16v10H4zM8 19h8M12 15v4'],
        ['errors.index', [], 'Errors & warnings', \CodingTriplets\Zatca\Laravel\Dashboard\Abilities::ERRORS_VIEW, 'errors', 'M12 9v4m0 4h.01M10.3 3.9L2 18a2 2 0 001.7 3h16.6a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z'],
        ['reports', [], 'Reports', \CodingTriplets\Zatca\Laravel\Dashboard\Abilities::REPORTS_VIEW, 'reports', 'M4 20V10M10 20V4M16 20v-8M22 20H2'],
        ['queue', [], 'Queue monitor', \CodingTriplets\Zatca\Laravel\Dashboard\Abilities::QUEUE_VIEW, 'queue', 'M4 6h16M4 12h16M4 18h10'],
        ['audit', [], 'Audit history', \CodingTriplets\Zatca\Laravel\Dashboard\Abilities::AUDIT_VIEW, 'audit', 'M12 8v4l3 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
        ['settings', [], 'Settings', \CodingTriplets\Zatca\Laravel\Dashboard\Abilities::SETTINGS_MANAGE, 'settings', 'M12 15a3 3 0 100-6 3 3 0 000 6zM19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-2.9 1.2V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-2.9-1.2l-.1.1a2 2 0 11-2.8-2.8l.1-.1A1.7 1.7 0 003 15H3a2 2 0 110-4h.1a1.7 1.7 0 001.2-2.9l-.1-.1a2 2 0 112.8-2.8l.1.1A1.7 1.7 0 0010 4.1V4a2 2 0 114 0v.1a1.7 1.7 0 002.9 1.2l.1-.1a2 2 0 112.8 2.8l-.1.1A1.7 1.7 0 0021 11h.1a2 2 0 110 4H21a1.7 1.7 0 00-1.6 0z'],
    ];
    $section = trim($__env->yieldContent('section', 'home'));
    $userName = (function () {
        $user = auth()->guard(config('zatca.ui.guard') ?: null)->user();
        return $user ? (string) ($user->name ?? $user->email ?? ('#' . $user->getAuthIdentifier())) : null;
    })();
@endphp
<div class="flex min-h-screen">
    <aside class="hidden w-64 shrink-0 flex-col border-r border-slate-200 bg-white md:flex dark:border-slate-800 dark:bg-slate-900">
        <div class="flex h-16 items-center gap-3 border-b border-slate-200 px-5 dark:border-slate-800">
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-gradient-to-br from-emerald-500 to-emerald-700 text-sm font-bold text-white shadow-sm">Z</span>
            <div class="leading-tight">
                <div class="font-semibold">ZATCA</div>
                <div class="text-xs text-slate-500">E-invoicing console</div>
            </div>
        </div>
        <nav class="flex-1 space-y-0.5 p-3 text-sm">
            @foreach ($nav as [$name, $params, $label, $ability, $key, $icon])
                @if ($can($ability))
                    <a href="{{ $zroute($name, $params) }}"
                       class="group flex items-center gap-3 rounded-lg px-3 py-2 {{ $section === $key ? 'bg-emerald-50 font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800' }}">
                        <svg class="h-5 w-5 shrink-0 {{ $section === $key ? 'text-emerald-600 dark:text-emerald-300' : 'text-slate-400 group-hover:text-slate-500' }}" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                        {{ $label }}
                    </a>
                @endif
            @endforeach
        </nav>
        <div class="border-t border-slate-200 p-4 text-xs text-slate-500 dark:border-slate-800">
            Environment
            <span class="ml-1 rounded-full px-2 py-0.5 font-semibold uppercase tracking-wide ring-1 ring-inset {{ $envClass }}">{{ $environment }}</span>
        </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-10 border-b border-slate-200 bg-white/90 backdrop-blur dark:border-slate-800 dark:bg-slate-900/90">
            <div class="flex h-16 items-center justify-between gap-4 px-4 md:px-8">
                <div class="min-w-0">
                    <div class="text-xs text-slate-500">ZATCA <span class="mx-1">/</span> @yield('title', 'Dashboard')</div>
                    <h1 class="truncate text-lg font-semibold">@yield('title', 'Dashboard')</h1>
                </div>
                <div class="flex items-center gap-3">
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide ring-1 ring-inset md:hidden {{ $envClass }}">{{ $environment }}</span>
                    @if ($userName)
                        <span class="hidden items-center gap-2 text-sm text-slate-600 sm:flex dark:text-slate-300">
                            <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-slate-200 text-xs font-semibold uppercase text-slate-700 dark:bg-slate-700 dark:text-slate-200">{{ mb_substr($userName, 0, 1) }}</span>
                            {{ $userName }}
                        </span>
                    @endif
                </div>
            </div>
            <nav class="flex gap-4 overflow-x-auto whitespace-nowrap border-t border-slate-100 px-4 py-2 text-sm md:hidden dark:border-slate-800">
                @foreach ($nav as [$name, $params, $label, $ability, $key])
                    @if ($can($ability))
                        <a href="{{ $zroute($name, $params) }}" class="{{ $section === $key ? 'font-semibold text-emerald-700 dark:text-emerald-400' : 'text-slate-600 dark:text-slate-300' }}">{{ explode(' ', $label)[0] }}</a>
                    @endif
                @endforeach
            </nav>
        </header>

        <main class="mx-auto w-full max-w-7xl flex-1 p-4 md:p-8">
            @foreach ([['zatca_status', 'status', 'border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-100', 'M5 13l4 4L19 7'],
                       ['zatca_error', 'alert', 'border-red-200 bg-red-50 text-red-900 dark:border-red-800 dark:bg-red-900/30 dark:text-red-100', 'M12 9v4m0 4h.01M12 3a9 9 0 110 18 9 9 0 010-18z']] as [$key, $role, $tone, $icon])
                @php $message = $key === 'zatca_error' && ! session($key) && $errors->any() ? $errors->first() : session($key); @endphp
                @if ($message)
                    <div class="mb-5 flex items-start gap-3 rounded-xl border px-4 py-3 text-sm shadow-sm {{ $tone }}" role="{{ $role }}">
                        <svg class="mt-0.5 h-5 w-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                        <div>{{ $message }}</div>
                    </div>
                @endif
            @endforeach

            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
