<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers;

use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Laravel\Dashboard\Abilities;
use CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers\Concerns\DashboardHelpers;
use CodingTriplets\Zatca\Laravel\Dashboard\PermissionResolver;
use CodingTriplets\Zatca\Laravel\Dashboard\Queries\InvoiceListQuery;
use CodingTriplets\Zatca\Laravel\Dashboard\Queries\ReportQuery;
use CodingTriplets\Zatca\Laravel\Database\Models\Company;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Reports (daily volume charts, VAT summary, per device, errors) and CSV exports. */
final class ReportController
{
    use DashboardHelpers;

    private const INVOICE_COLUMNS = [
        'invoice_id', 'workflow', 'document_type', 'invoice_number', 'external_invoice_id', 'invoice_date', 'device',
        'buyer_vat_number', 'currency', 'total_excl_vat', 'vat_total', 'total_incl_vat', 'processing_status',
        'reporting_status', 'clearance_status', 'uuid', 'billing_reference', 'first_issued_at',
    ];

    public function index(Request $request): View
    {
        $this->authorizeAbility($request, Abilities::REPORTS_VIEW);
        $report = new ReportQuery($this->filters($request));

        return view('zatca::dashboard.reports.index', [
            'report' => $report,
            'daily' => $report->daily(),
            'vat' => $report->vatSummary(),
            'devices' => $report->byDevice(),
            'errorsByCategory' => $report->errorsByCategory(),
            'filters' => $request->only(['from', 'to', 'workflow', 'company', 'device']),
            'companies' => Company::query()->orderBy('name_en')->get(['id', 'name_en']),
            'deviceOptions' => Device::query()->orderBy('name')->get(['id', 'name']),
            'canExport' => $this->can($request, Abilities::INVOICES_EXPORT),
        ]);
    }

    /** All documents of the report period (same filters as the report page). */
    public function exportInvoices(Request $request): StreamedResponse
    {
        $this->authorizeAbility($request, Abilities::REPORTS_VIEW);
        $this->authorizeAbility($request, Abilities::INVOICES_EXPORT);
        $report = new ReportQuery($this->filters($request));
        $query = $this->visibleWorkflows($request, $report->invoices());

        return $this->invoicesCsv($query, sprintf('zatca-invoices-%s-%s.csv', $report->from->toDateString(), $report->to->toDateString()));
    }

    /** The documents of a B2B / B2C list with its current tab, search and filters. */
    public function exportList(Request $request, string $workflow, InvoiceListQuery $list): StreamedResponse
    {
        $flow = Workflow::from($workflow);
        $this->authorizeAbility($request, Abilities::view($flow->value));
        $this->authorizeAbility($request, Abilities::INVOICES_EXPORT);
        $query = $list->query($flow, $request->only(['tab', 'q', 'device', 'type', 'from', 'to']));

        return $this->invoicesCsv($query, sprintf('zatca-%s-%s.csv', $flow->value, now()->format('Ymd-His')));
    }

    public function exportVat(Request $request): StreamedResponse
    {
        $this->authorizeAbility($request, Abilities::REPORTS_VIEW);
        $report = new ReportQuery($this->filters($request));
        $summary = $report->vatSummary();
        $names = ['388' => 'invoice', '383' => 'debit_note', '381' => 'credit_note'];

        return $this->csv(sprintf('zatca-vat-summary-%s-%s.csv', $report->from->toDateString(), $report->to->toDateString()), function ($out) use ($summary, $names, $report): void {
            fputcsv($out, ['period_from', 'period_to', 'workflow', 'document', 'count', 'total_excl_vat', 'vat_total', 'total_incl_vat']);
            foreach ($summary as $workflow => $data) {
                foreach ($data['rows'] as $type => $row) {
                    fputcsv($out, [$report->from->toDateString(), $report->to->toDateString(), $workflow, $names[$type], $row['count'], $row['excl'], $row['vat'], $row['incl']]);
                }
                fputcsv($out, [$report->from->toDateString(), $report->to->toDateString(), $workflow, 'net', '', $data['net']['excl'], $data['net']['vat'], $data['net']['incl']]);
            }
        });
    }

    // ------------------------------------------------------------------ helpers

    /** @param Builder<Invoice> $query */
    private function invoicesCsv(Builder $query, string $filename): StreamedResponse
    {
        $query->with(['device:id,name', 'billingReference:id,invoice_number']);

        return $this->csv($filename, static function ($out) use ($query): void {
            fputcsv($out, self::INVOICE_COLUMNS);
            $query->reorder()->orderBy('id')->chunkById(1000, static function ($invoices) use ($out): void {
                foreach ($invoices as $invoice) {
                    fputcsv($out, array_map([self::class, 'cell'], [
                        $invoice->public_id,
                        $invoice->workflow->value,
                        $invoice->document_type->value,
                        $invoice->invoice_number,
                        $invoice->external_invoice_id,
                        $invoice->invoice_date?->toDateString(),
                        $invoice->device?->name,
                        $invoice->buyer_vat_number,
                        $invoice->currency,
                        (string) $invoice->total_excl_vat,
                        (string) $invoice->vat_total,
                        (string) $invoice->total_incl_vat,
                        $invoice->processing_status->value,
                        $invoice->reporting_status?->value,
                        $invoice->clearance_status?->value,
                        $invoice->current_uuid,
                        $invoice->billingReference?->invoice_number,
                        $invoice->first_issued_at?->toIso8601String(),
                    ]));
                }
            });
        });
    }

    /** @param \Closure(resource): void $write */
    private function csv(string $filename, \Closure $write): StreamedResponse
    {
        return response()->streamDownload(static function () use ($write): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel shows Arabic names correctly
            $write($out);
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Neutralises spreadsheet formulas in text cells (CSV injection). Numbers are left as they are. */
    public static function cell(mixed $value): string
    {
        $value = (string) ($value ?? '');
        if ($value !== '' && ! is_numeric($value) && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    /** @param Builder<Invoice> $query */
    private function visibleWorkflows(Request $request, Builder $query): Builder
    {
        $visible = array_values(array_filter(['b2b', 'b2c'], fn ($w) => $this->can($request, Abilities::view($w))));
        abort_if($visible === [], 403);

        return $query->whereIn('workflow', $visible);
    }

    private function can(Request $request, string $ability): bool
    {
        return app(PermissionResolver::class)->allows($this->user($request), $ability);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'workflow' => $request->query('workflow'),
            'company' => $request->query('company'),
            'device' => $request->query('device'),
        ];
    }
}
