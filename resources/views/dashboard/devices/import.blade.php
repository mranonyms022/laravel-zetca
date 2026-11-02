@extends('zatca::dashboard.layout')

@section('title', 'Import devices')
@section('section', 'devices')

@php
    $input = 'mt-1 rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-950';
    $th = 'px-3 py-2 text-left text-xs uppercase tracking-wide text-slate-500';
@endphp

@section('content')
<div class="mb-4 text-sm"><a href="{{ $zroute('devices.index') }}" class="text-slate-500 hover:underline">← Devices</a></div>
<h2 class="mb-1 text-xl font-semibold">Import devices from a file</h2>
<p class="mb-4 max-w-3xl text-sm text-slate-500">
    CSV, XLSX or ODS (up to {{ \CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers\DeviceController::IMPORT_MAX_KB / 1024 }} MB). One row per device; columns
    <code>company_id, store_id, name, invoice_types, industry, location</code>, optional <code>environment, serial, otp</code>.
    Companies and stores must exist. Rows already registered are reported as <em>existing</em>, so the same file can be uploaded again.
    An <code>otp</code> column is only used when "Onboard" is ticked; OTPs are never stored, and the uploaded file is deleted right after reading.
</p>

<form method="POST" action="{{ $zroute('devices.import.run') }}" enctype="multipart/form-data" class="mb-6 flex max-w-3xl flex-wrap items-end gap-4 rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
    @csrf
    <label class="text-sm font-medium">File
        <input type="file" name="file" required accept=".csv,.txt,.xlsx,.ods" class="{{ $input }} block">
    </label>
    <label class="text-sm font-medium">IDs in the file are
        <select name="ids" class="{{ $input }} block">
            <option value="auto">Auto-detect</option>
            <option value="external">My own IDs (external_ref)</option>
            <option value="package">Package IDs</option>
        </select>
    </label>
    <div class="flex flex-col gap-1 text-sm">
        <label class="flex items-center gap-2"><input type="checkbox" name="validate_only" value="1"> Check only (no changes)</label>
        <label class="flex items-center gap-2"><input type="checkbox" name="onboard" value="1"> Onboard rows that have an OTP</label>
    </div>
    <button class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700">Upload</button>
</form>

@if ($report !== null)
    @php $counts = $report->counts(); @endphp
    <div class="mb-3 flex flex-wrap items-center gap-2 text-sm">
        <span class="font-semibold">{{ ($validateOnly ?? false) ? 'Check result' : 'Import result' }}:</span>
        @foreach ($counts as $status => $n)
            <span class="rounded px-2 py-0.5 text-xs font-medium {{ in_array($status, ['failed', 'onboarding_failed'], true) ? 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200' : 'bg-slate-100 dark:bg-slate-800' }}">{{ $status }}: {{ $n }}</span>
        @endforeach
    </div>
    <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 dark:bg-slate-800/60"><tr><th class="{{ $th }}">Row</th><th class="{{ $th }}">Device</th><th class="{{ $th }}">Result</th><th class="{{ $th }}">Problems</th></tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            @foreach ($report->rows() as $row)
                <tr>
                    <td class="px-3 py-2 font-mono">{{ $row['row'] }}</td>
                    <td class="px-3 py-2">
                        @if ($row['device_id'])<a href="{{ $zroute('devices.show', $row['device_id']) }}" class="text-emerald-700 hover:underline dark:text-emerald-400">{{ $row['name'] ?? '#'.$row['device_id'] }}</a>
                        @else {{ $row['name'] ?? '—' }} @endif
                    </td>
                    <td class="px-3 py-2">{{ str_replace('_', ' ', $row['status']) }}</td>
                    <td class="px-3 py-2 text-red-700 dark:text-red-300">{{ implode(' · ', $row['errors']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
