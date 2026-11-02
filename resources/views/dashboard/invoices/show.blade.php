@extends('zatca::dashboard.layout')

@php
    $flow = $invoice->workflow->value;
    $typeLabel = ['388' => 'Invoice', '381' => 'Credit note', '383' => 'Debit note'][$invoice->document_type->value];
    $card = 'rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900';
    $th = 'px-3 py-2 text-left text-xs uppercase tracking-wide text-slate-500';
    $td = 'px-3 py-2 align-top';
    $tabLabels = ['summary' => 'Summary', 'lines' => 'Lines', 'versions' => 'JSON versions', 'issuances' => 'Issuances',
        'attempts' => 'Submissions', 'errors' => 'Errors & warnings', 'history' => 'History', 'related' => 'Notes'];
    $actionInfo = [
        'retry' => ['Retry submission', 'Sends the identical signed document again.', false, 'bg-sky-600 hover:bg-sky-700'],
        'reissue' => ['Reissue unchanged', 'New document (new UUID and counter) from the current JSON. Use when the rejection was caused by the package or a certificate.', true, 'bg-sky-600 hover:bg-sky-700'],
        'approve' => ['Approve correction', 'Four-eyes approval; you must not be the user who made the correction.', false, 'bg-emerald-600 hover:bg-emerald-700'],
        'cancel' => ['Cancel (credit note)', 'Issues a credit note for the full amount. The invoice stays valid but is fully credited.', true, 'bg-red-600 hover:bg-red-700'],
        'discard' => ['Discard', 'Closes this never-signed invoice (status CANCELLED). Nothing is sent to ZATCA.', true, 'bg-red-600 hover:bg-red-700'],
        'abandon' => ['Abandon ⚠', 'Closes this rejected invoice (status ABANDONED). The rejected document stays in the history. Confirm with your tax adviser.', true, 'bg-red-600 hover:bg-red-700'],
    ];
    $json = static fn ($v) => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
@endphp

@section('title', $invoice->invoice_number)
@section('section', $flow)

@section('content')
<div class="mb-4 text-sm">
    <a href="{{ $zroute('invoices.index', ['workflow' => $flow]) }}" class="text-slate-500 hover:underline">← {{ strtoupper($flow) }} invoices</a>
</div>

<div class="mb-5 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h2 class="text-xl font-semibold">{{ $invoice->invoice_number }}</h2>
        <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
            <span class="rounded bg-slate-800 px-1.5 py-0.5 text-xs font-semibold uppercase text-white">{{ $flow }}</span>
            <span>{{ $typeLabel }}</span>
            <span>·</span>
            <span class="font-mono text-xs">{{ $invoice->public_id }}</span>
        </div>
        <div class="mt-2 flex flex-wrap gap-2">
            @include('zatca::dashboard.partials.status', ['status' => $invoice->processing_status])
            @include('zatca::dashboard.partials.status', ['status' => $flow === 'b2b' ? $invoice->clearance_status : $invoice->reporting_status])
        </div>
    </div>

    @php
        $editorLinks = [];
        if ($can(\CodingTriplets\Zatca\Laravel\Dashboard\Abilities::INVOICES_CORRECT, $invoice)) {
            if (\CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers\InvoiceEditorController::canCorrect($invoice)) {
                $editorLinks[] = [$zroute('invoices.correct', $invoice->public_id), 'Correct JSON'];
            }
            if (\CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers\InvoiceEditorController::canIssueNotes($invoice)) {
                $editorLinks[] = [$zroute('invoices.note', ['publicId' => $invoice->public_id, 'kind' => 'credit']), 'Credit note'];
                $editorLinks[] = [$zroute('invoices.note', ['publicId' => $invoice->public_id, 'kind' => 'debit']), 'Debit note'];
            }
        }
    @endphp
    @if ($actions !== [] || $editorLinks !== [])
        <div class="flex flex-wrap gap-2">
            @foreach ($editorLinks as [$href, $label])
                <a href="{{ $href }}" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800">{{ $label }}</a>
            @endforeach
            @foreach ($actions as $action)
                @php [$label, $help, $needsReason, $color] = $actionInfo[$action]; @endphp
                <details class="relative">
                    <summary class="cursor-pointer list-none rounded-md px-3 py-1.5 text-sm font-medium text-white {{ $color }}">{{ $label }}</summary>
                    <form method="POST" action="{{ $zroute('invoices.action', ['publicId' => $invoice->public_id, 'action' => $action]) }}"
                          class="absolute right-0 z-10 mt-2 w-80 space-y-3 rounded-lg border border-slate-200 bg-white p-4 shadow-lg dark:border-slate-700 dark:bg-slate-900">
                        @csrf
                        <p class="text-sm text-slate-600 dark:text-slate-300">{{ $help }}</p>
                        @if ($needsReason)
                            <label class="block text-xs text-slate-500">Reason (required, recorded in the history)
                                <textarea name="reason" required maxlength="1000" rows="3" class="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm dark:border-slate-700 dark:bg-slate-950"></textarea>
                            </label>
                        @endif
                        <button class="w-full rounded-md px-3 py-1.5 text-sm font-medium text-white {{ $color }}">Confirm: {{ $label }}</button>
                    </form>
                </details>
            @endforeach
        </div>
    @endif
</div>

<div class="mb-4 flex flex-wrap gap-1 border-b border-slate-200 dark:border-slate-800">
    @foreach ($tabLabels as $key => $label)
        <a href="{{ $zroute('invoices.show', ['publicId' => $invoice->public_id, 'tab' => $key === 'summary' ? null : $key]) }}"
           class="-mb-px border-b-2 px-3 py-2 text-sm {{ $tab === $key ? 'border-emerald-600 font-medium text-emerald-700 dark:text-emerald-400' : 'border-transparent text-slate-500 hover:text-slate-800 dark:hover:text-slate-200' }}">
            {{ $label }}
            @if ($key === 'errors' && $invoice->unresolved_error_count > 0)<span class="ml-1 rounded-full bg-red-600 px-1.5 text-xs text-white">{{ $invoice->unresolved_error_count }}</span>@endif
        </a>
    @endforeach
</div>

@if ($tab === 'summary')
    <div class="grid gap-4 lg:grid-cols-2">
        <dl class="{{ $card }} divide-y divide-slate-100 text-sm dark:divide-slate-800">
            @foreach ([
                'Invoice date' => $invoice->invoice_date?->format('Y-m-d'),
                'Device' => $invoice->device?->name,
                'Environment' => $invoice->environment->value,
                'External ID' => $invoice->external_invoice_id,
                'Buyer VAT' => $invoice->buyer_vat_number,
                'Current UUID' => $invoice->current_uuid,
                'Issuances' => $invoice->issuance_count,
                'Submission attempts' => $invoice->attempts,
                'Last error' => $invoice->last_error_code,
                'Reporting deadline' => $flow === 'b2c' ? $invoice->reporting_deadline_at?->toDateTimeString() : null,
                'Next retry' => $invoice->next_retry_at?->toDateTimeString(),
                'Reported at' => $invoice->reported_at?->toDateTimeString(),
                'Cleared at' => $invoice->cleared_at?->toDateTimeString(),
                'Closed at' => $invoice->closed_at?->toDateTimeString(),
            ] as $label => $value)
                @continue($value === null || $value === '')
                <div class="flex justify-between gap-4 px-4 py-2"><dt class="text-slate-500">{{ $label }}</dt><dd class="text-right font-medium break-all">{{ $value }}</dd></div>
            @endforeach
        </dl>
        <div class="space-y-4">
            <dl class="{{ $card }} divide-y divide-slate-100 text-sm dark:divide-slate-800">
                @foreach (['Total excl. VAT' => $invoice->total_excl_vat, 'VAT' => $invoice->vat_total, 'Total incl. VAT' => $invoice->total_incl_vat, 'Credited' => $invoice->credited_amount, 'Debited' => $invoice->debited_amount] as $label => $value)
                    @continue($value === null || (in_array($label, ['Credited', 'Debited'], true) && (float) $value === 0.0))
                    <div class="flex justify-between px-4 py-2"><dt class="text-slate-500">{{ $label }}</dt><dd class="font-medium tabular-nums">{{ number_format((float) $value, 2) }} SAR</dd></div>
                @endforeach
            </dl>
            @if ($invoice->billingReference || $invoice->cancellationNote)
                <div class="{{ $card }} space-y-1 p-4 text-sm">
                    @if ($invoice->billingReference)
                        <div>Note for invoice <a class="text-emerald-700 hover:underline dark:text-emerald-400" href="{{ $zroute('invoices.show', $invoice->billingReference->public_id) }}">{{ $invoice->billingReference->invoice_number }}</a></div>
                    @endif
                    @if ($invoice->cancellationNote)
                        <div>Cancelled by credit note <a class="text-emerald-700 hover:underline dark:text-emerald-400" href="{{ $zroute('invoices.show', $invoice->cancellationNote->public_id) }}">{{ $invoice->cancellationNote->invoice_number }}</a></div>
                    @endif
                </div>
            @endif
            @if ($invoice->source_type)
                <div class="{{ $card }} p-4 text-sm"><span class="text-slate-500">Linked record:</span> <span class="font-mono text-xs">{{ $invoice->source_type }} #{{ $invoice->source_id }}</span></div>
            @endif
        </div>
    </div>

@elseif ($tab === 'lines')
    @if ($lines->isEmpty())
        <p class="text-sm text-slate-500">No stored lines (the invoice was not signed yet, or <code>zatca.database.store_lines</code> is off).</p>
    @else
        <div class="{{ $card }} overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/60"><tr>
                    <th class="{{ $th }}">#</th><th class="{{ $th }}">Item</th><th class="{{ $th }} text-right">Qty</th><th class="{{ $th }} text-right">Unit price</th>
                    <th class="{{ $th }} text-right">Unit disc.</th><th class="{{ $th }} text-right">Line disc.</th><th class="{{ $th }} text-right">Net</th>
                    <th class="{{ $th }}">VAT</th><th class="{{ $th }} text-right">VAT amt</th><th class="{{ $th }} text-right">Total</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($lines as $line)
                    <tr>
                        <td class="{{ $td }}">{{ $line->line_no }}</td>
                        <td class="{{ $td }}">{{ $line->name }}@if ($line->item_code)<div class="font-mono text-xs text-slate-500">{{ $line->item_code }}</div>@endif</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ rtrim(rtrim((string) $line->quantity, '0'), '.') }} {{ $line->unit_code }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ number_format((float) $line->unit_price, 2) }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ (float) $line->unit_discount ? number_format((float) $line->unit_discount, 2) : '' }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ (float) $line->line_discount ? number_format((float) $line->line_discount, 2) : '' }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ number_format((float) $line->net_amount, 2) }}</td>
                        <td class="{{ $td }}">{{ $line->vat_category }} {{ rtrim(rtrim((string) $line->vat_rate, '0'), '.') }}%</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ number_format((float) $line->vat_amount, 2) }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ number_format((float) $line->amount_incl_vat, 2) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <h3 class="mb-2 mt-5 text-sm font-semibold">VAT breakdown</h3>
        <div class="{{ $card }} overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/60"><tr><th class="{{ $th }}">Category</th><th class="{{ $th }}">Rate</th><th class="{{ $th }}">Exemption</th><th class="{{ $th }} text-right">Taxable</th><th class="{{ $th }} text-right">VAT</th></tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($vat as $row)
                    <tr><td class="{{ $td }}">{{ $row->vat_category }}</td><td class="{{ $td }}">{{ rtrim(rtrim((string) $row->vat_rate, '0'), '.') }}%</td><td class="{{ $td }}">{{ $row->exemption_reason_code }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ number_format((float) $row->taxable_amount, 2) }}</td><td class="{{ $td }} text-right tabular-nums">{{ number_format((float) $row->vat_amount, 2) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

@elseif ($tab === 'versions')
    <div class="space-y-4">
        @foreach ($versions as $version)
            <details class="{{ $card }}" @if ($loop->last) open @endif>
                <summary class="cursor-pointer px-4 py-3 text-sm">
                    <span class="font-semibold">v{{ $version->version }}</span>
                    <span class="text-slate-500">· {{ $version->kind->value }} · {{ $version->created_at?->toDateTimeString() }}@if ($version->created_by) · user #{{ $version->created_by }}@endif</span>
                    @if ($version->reason)<div class="mt-1 text-slate-600 dark:text-slate-300">“{{ $version->reason }}”</div>@endif
                    @if ($version->changed_fields)<div class="mt-1 font-mono text-xs text-slate-500">changed: {{ implode(', ', $version->changed_fields) }}</div>@endif
                </summary>
                <pre class="max-h-[32rem] overflow-auto border-t border-slate-100 bg-slate-50 p-4 text-xs dark:border-slate-800 dark:bg-slate-950">{{ $json($version->payload) }}</pre>
            </details>
        @endforeach
    </div>

@elseif ($tab === 'issuances')
    <div class="space-y-3">
        @forelse ($issuances as $issuance)
            <div class="{{ $card }} p-4 text-sm">
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <span class="font-semibold">#{{ $issuance->sequence }}</span>
                    @include('zatca::dashboard.partials.status', ['status' => $issuance->status])
                    <span class="text-slate-500">{{ $issuance->reason?->value }} · {{ $issuance->issued_at?->toDateTimeString() }}</span>
                </div>
                <dl class="grid gap-x-6 gap-y-1 md:grid-cols-2">
                    <div><dt class="inline text-slate-500">UUID</dt> <dd class="inline font-mono text-xs">{{ $issuance->uuid }}</dd></div>
                    <div><dt class="inline text-slate-500">ICV</dt> <dd class="inline font-mono text-xs">{{ $issuance->icv }}</dd></div>
                    <div class="md:col-span-2"><dt class="inline text-slate-500">PIH</dt> <dd class="inline break-all font-mono text-xs">{{ $issuance->previous_invoice_hash }}</dd></div>
                    <div class="md:col-span-2"><dt class="inline text-slate-500">Hash</dt> <dd class="inline break-all font-mono text-xs">{{ $issuance->invoice_hash }}</dd></div>
                    <div><dt class="inline text-slate-500">Issue date</dt> <dd class="inline">{{ $issuance->issue_date?->format('Y-m-d') }} {{ $issuance->issue_time }}</dd></div>
                </dl>
                @if ($can(\CodingTriplets\Zatca\Laravel\Dashboard\Abilities::INVOICES_EXPORT, $invoice))
                    <div class="mt-3 flex gap-3">
                        @foreach (($artifacts[$issuance->id] ?? collect())->filter(fn ($a) => in_array($a->kind->value, ['signed_xml', 'cleared_xml'], true)) as $artifact)
                            <a href="{{ $zroute('invoices.artifact', ['publicId' => $invoice->public_id, 'artifactId' => $artifact->id]) }}" class="text-emerald-700 hover:underline dark:text-emerald-400">Download {{ str_replace('_', ' ', $artifact->kind->value) }}</a>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <p class="text-sm text-slate-500">Not signed yet: no counter or UUID has been used.</p>
        @endforelse
    </div>

@elseif ($tab === 'attempts')
    <div class="{{ $card }} overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 dark:bg-slate-800/60"><tr>
                <th class="{{ $th }}">#</th><th class="{{ $th }}">Started</th><th class="{{ $th }}">Endpoint</th><th class="{{ $th }}">HTTP</th>
                <th class="{{ $th }}">ZATCA status</th><th class="{{ $th }}">Outcome</th><th class="{{ $th }}">Error</th><th class="{{ $th }} text-right">ms</th><th class="{{ $th }}">By</th>
            </tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            @forelse ($attempts as $a)
                <tr>
                    <td class="{{ $td }}">{{ $a->attempt_number }}</td>
                    <td class="{{ $td }} whitespace-nowrap">{{ $a->started_at?->toDateTimeString() }}</td>
                    <td class="{{ $td }} font-mono text-xs">{{ $a->endpoint }}</td>
                    <td class="{{ $td }}">{{ $a->http_status }}</td>
                    <td class="{{ $td }}">{{ $a->zatca_status }}</td>
                    <td class="{{ $td }}">{{ $a->outcome?->value }}</td>
                    <td class="{{ $td }}">{{ $a->error_code }} <span class="text-slate-500">{{ \Illuminate\Support\Str::limit((string) $a->error_message, 140) }}</span></td>
                    <td class="{{ $td }} text-right tabular-nums">{{ $a->duration_ms }}</td>
                    <td class="{{ $td }}">{{ $a->triggered_by }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-3 py-8 text-center text-slate-500">No submissions yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

@elseif ($tab === 'errors')
    <div class="space-y-3">
        @forelse ($zatcaErrors as $e)
            <div class="{{ $card }} p-4 text-sm {{ $e->is_resolved ? 'opacity-60' : '' }}">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-mono font-semibold">{{ $e->code }}</span>
                    <span class="rounded bg-slate-100 px-1.5 text-xs dark:bg-slate-800">{{ $e->category?->value }}</span>
                    <span class="rounded px-1.5 text-xs {{ in_array($e->severity?->value, ['ERROR', 'CRITICAL'], true) ? 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200' : 'bg-amber-100 text-amber-800' }}">{{ $e->severity?->value }}</span>
                    @if ($e->is_resolved)<span class="text-xs text-emerald-700">resolved {{ $e->resolved_at?->toDateTimeString() }}{{ $e->resolution_note ? ' — '.$e->resolution_note : '' }}</span>@endif
                </div>
                <p class="mt-1">{{ $e->message }}</p>
            </div>
        @empty
            <p class="text-sm text-slate-500">No errors.</p>
        @endforelse
        @if ($warnings->isNotEmpty())
            <h3 class="pt-2 text-sm font-semibold">Warnings</h3>
            @foreach ($warnings as $w)
                <div class="{{ $card }} p-3 text-sm"><span class="font-mono font-semibold">{{ $w->code }}</span> <span class="text-xs text-slate-500">{{ $w->source }}</span><p class="mt-1">{{ $w->message }}</p></div>
            @endforeach
        @endif
    </div>

@elseif ($tab === 'history')
    <ol class="{{ $card }} divide-y divide-slate-100 text-sm dark:divide-slate-800">
        @forelse ($logs as $log)
            <li class="flex flex-wrap gap-x-3 px-4 py-2">
                <span class="whitespace-nowrap text-slate-500">{{ $log->created_at?->toDateTimeString() }}</span>
                <span class="font-medium">{{ $log->event }}</span>
                @if ($log->previous_status || $log->new_status)<span class="text-slate-600 dark:text-slate-300">{{ $log->dimension }}: {{ $log->previous_status ?? '—' }} → {{ $log->new_status }}</span>@endif
                @if ($log->message)<span class="text-slate-500">{{ $log->message }}</span>@endif
                @if ($log->actor_id)<span class="text-xs text-slate-400">user #{{ $log->actor_id }}</span>@endif
            </li>
        @empty
            <li class="px-4 py-6 text-center text-slate-500">No history.</li>
        @endforelse
    </ol>

@elseif ($tab === 'related')
    <div class="{{ $card }} overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 dark:bg-slate-800/60"><tr><th class="{{ $th }}">Number</th><th class="{{ $th }}">Type</th><th class="{{ $th }} text-right">Total</th><th class="{{ $th }}">Status</th></tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            @forelse ($notes as $note)
                <tr>
                    <td class="{{ $td }}"><a class="text-emerald-700 hover:underline dark:text-emerald-400" href="{{ $zroute('invoices.show', $note->public_id) }}">{{ $note->invoice_number }}</a></td>
                    <td class="{{ $td }}">{{ ['388' => 'Invoice', '381' => 'Credit note', '383' => 'Debit note'][$note->document_type->value] }}</td>
                    <td class="{{ $td }} text-right tabular-nums">{{ $note->total_incl_vat !== null ? number_format((float) $note->total_incl_vat, 2) : '—' }}</td>
                    <td class="{{ $td }}">@include('zatca::dashboard.partials.status', ['status' => $note->processing_status])</td>
                </tr>
            @empty
                <tr><td colspan="4" class="px-3 py-8 text-center text-slate-500">No credit or debit notes.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endif
@endsection
