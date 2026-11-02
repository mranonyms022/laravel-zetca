{{-- Company fields shared by "Add company" and "Edit company". $company is null when creating. --}}
@php
    $input = 'mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20 disabled:bg-slate-100 disabled:text-slate-500 dark:border-slate-700 dark:bg-slate-950 dark:disabled:bg-slate-800';
    $label = 'block text-xs font-medium text-slate-600 dark:text-slate-300';
    $legend = 'mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500';
    $address = (array) ($company?->address ?? []);
    $value = static fn (string $field, mixed $current = null) => old($field, $current);
@endphp
<div class="grid gap-6 lg:grid-cols-2">
    <fieldset>
        <div class="{{ $legend }}">Identity</div>
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="{{ $label }}">Name<input name="name" required value="{{ $value('name', $company?->name_en) }}" class="{{ $input }}"></label>
            <label class="{{ $label }}">Arabic name<input name="name_ar" dir="rtl" value="{{ $value('name_ar', $company?->name_ar) }}" class="{{ $input }}"></label>
            <label class="{{ $label }} sm:col-span-2">Legal name (printed on invoices)
                <input name="legal_name" @if($legalName !== false) required @endif @disabled($legalName === false) value="{{ $value('legal_name', $legalName ?: null) }}" class="{{ $input }}">
                @if ($legalName === false)<span class="mt-1 block text-xs text-slate-500">This company has several VAT registrations; their legal names are not edited here.</span>@endif
            </label>
            @if ($withVat)
                <label class="{{ $label }}">VAT number (15 digits)<input name="vat_number" required pattern="3[0-9]{13}3" maxlength="15" value="{{ $value('vat_number') }}" class="{{ $input }} font-mono"></label>
            @endif
            <label class="{{ $label }}">CRN<input name="crn" value="{{ $value('crn', $company?->crn) }}" class="{{ $input }} font-mono"></label>
            <label class="{{ $label }} {{ $withVat ? 'sm:col-span-2' : '' }}">Your ID (external_ref)<input name="external_ref" value="{{ $value('external_ref', $company?->external_ref) }}" class="{{ $input }}"></label>
        </div>
    </fieldset>
    <fieldset>
        <div class="{{ $legend }}">National address</div>
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="{{ $label }} sm:col-span-2">Street<input name="street" required value="{{ $value('street', $address['street'] ?? null) }}" class="{{ $input }}"></label>
            <label class="{{ $label }}">Building number (4 digits)<input name="building_number" required pattern="[0-9]{4}" value="{{ $value('building_number', $address['building_number'] ?? null) }}" class="{{ $input }}"></label>
            <label class="{{ $label }}">District<input name="district" required value="{{ $value('district', $address['district'] ?? null) }}" class="{{ $input }}"></label>
            <label class="{{ $label }}">City<input name="city" required value="{{ $value('city', $address['city'] ?? null) }}" class="{{ $input }}"></label>
            <label class="{{ $label }}">Postal code (5 digits)<input name="postal_code" required pattern="[0-9]{5}" value="{{ $value('postal_code', $address['postal_code'] ?? null) }}" class="{{ $input }}"></label>
        </div>
    </fieldset>
</div>
