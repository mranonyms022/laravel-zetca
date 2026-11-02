@extends('zatca::dashboard.layout')

@section('title', 'Edit store')
@section('section', 'companies')

@php
    $card = 'overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900';
    $input = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 dark:border-slate-700 dark:bg-slate-950';
    $label = 'block text-xs font-medium text-slate-600 dark:text-slate-300';
@endphp

@section('content')
<a href="{{ $zroute('companies.index', ['company' => $store->company_id]) }}#stores" class="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:hover:text-slate-200">← Companies &amp; stores</a>

<section class="{{ $card }} max-w-2xl">
    <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
        <h2 class="text-base font-semibold">{{ $store->name }}</h2>
        <p class="text-xs text-slate-500">Store #{{ $store->id }} · {{ $store->company?->name_en }}</p>
    </div>
    <form method="POST" action="{{ $zroute('stores.update', $store->id) }}" class="space-y-4 p-5">
        @csrf
        @method('PUT')
        @if ($onboardedDevices > 0)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-100">
                {{ $onboardedDevices }} onboarded device(s) carry the current store name in their certificate. A new name is used
                for devices onboarded from now on; existing certificates keep the old one until they are renewed.
            </div>
        @endif
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="{{ $label }}">Code<input name="code" required maxlength="50" value="{{ old('code', $store->code) }}" class="{{ $input }} font-mono"></label>
            <label class="{{ $label }}">Name<input name="name" required maxlength="100" value="{{ old('name', $store->name) }}" class="{{ $input }}"></label>
            <label class="{{ $label }} sm:col-span-2">Your ID (external_ref)<input name="external_ref" maxlength="191" value="{{ old('external_ref', $store->external_ref) }}" class="{{ $input }}"></label>
        </div>
        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ $zroute('companies.index', ['company' => $store->company_id]) }}#stores" class="rounded-lg border border-slate-300 px-3.5 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Cancel</a>
            <button class="rounded-lg bg-emerald-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-700">Save changes</button>
        </div>
    </form>
</section>
@endsection
