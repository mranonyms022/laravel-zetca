@extends('zatca::dashboard.layout')

@section('title', 'Edit company')
@section('section', 'companies')

@php
    $card = 'overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900';
    $legalName = $registrations->count() === 1 ? $registrations->first()->legal_name : false;
@endphp

@section('content')
<a href="{{ $zroute('companies.index') }}" class="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 dark:hover:text-slate-200">← Companies &amp; stores</a>

<div class="grid gap-6 lg:grid-cols-3">
    <section class="{{ $card }} lg:col-span-2">
        <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800">
            <h2 class="text-base font-semibold">{{ $company->name_en }}</h2>
            <p class="text-xs text-slate-500">Company #{{ $company->id }}</p>
        </div>
        <form method="POST" action="{{ $zroute('companies.update', $company->id) }}" class="p-5">
            @csrf
            @method('PUT')
            @include('zatca::dashboard.companies.form', ['company' => $company, 'legalName' => $legalName, 'withVat' => false])
            <div class="mt-6 flex justify-end gap-2">
                <a href="{{ $zroute('companies.index') }}" class="rounded-lg border border-slate-300 px-3.5 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Cancel</a>
                <button class="rounded-lg bg-emerald-600 px-3.5 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-700">Save changes</button>
            </div>
        </form>
    </section>

    <aside class="space-y-6">
        <section class="{{ $card }}">
            <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-800"><h3 class="text-sm font-semibold">VAT registration</h3></div>
            <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                @foreach ($registrations as $reg)
                    <div class="px-5 py-3">
                        <dt class="font-mono">{{ $reg->vat_number }}</dt>
                        <dd class="text-xs text-slate-500">{{ $reg->legal_name }}{{ $reg->is_group ? ' · VAT group, TIN '.$reg->group_member_tin : '' }}</dd>
                    </div>
                @endforeach
            </dl>
            <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500 dark:border-slate-800">
                The VAT number is part of every device certificate and cannot be changed. If it is wrong, delete the
                company (possible while it has no devices) and add it again.
            </p>
        </section>
        <section class="{{ $card }} p-5 text-xs text-slate-500">
            Changes apply to invoices issued from now on. Invoices already issued keep the details they were signed with.
        </section>
    </aside>
</div>
@endsection
