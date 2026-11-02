<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers;

use CodingTriplets\Zatca\Core\Domain\Exceptions\ZatcaException;
use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\InvoiceValidationException;
use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\ValidationResult;
use CodingTriplets\Zatca\Laravel\Dashboard\Abilities;
use CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers\Concerns\DashboardHelpers;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceVersion;
use CodingTriplets\Zatca\Laravel\Database\Models\ZatcaError;
use CodingTriplets\Zatca\Laravel\Invoices\InvoiceOperations;
use CodingTriplets\Zatca\Laravel\Invoices\OperationNotAllowed;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Correction editor (new JSON version of a rejected or never-signed invoice) and credit / debit note forms.
 * Both need zatca.invoices.correct; the operations re-check the invoice state.
 */
final class InvoiceEditorController
{
    use DashboardHelpers;

    public const MAX_JSON_BYTES = 1_048_576;

    /** Statuses in which the editor is offered (the operation decides definitively). */
    public const CORRECTABLE = ['VALIDATION_FAILED', 'FAILED', 'READY_FOR_PROCESSING', 'MANUAL_REVIEW', 'REJECTED'];

    public const NOTE_ORIGINAL = ['REPORTED', 'CLEARED'];

    public static function canCorrect(Invoice $invoice): bool
    {
        return in_array($invoice->processing_status->value, self::CORRECTABLE, true)
            && ($invoice->processing_status->value === 'REJECTED' || $invoice->processing_status->value === 'MANUAL_REVIEW' || $invoice->current_issuance_id === null);
    }

    public static function canIssueNotes(Invoice $invoice): bool
    {
        return in_array($invoice->processing_status->value, self::NOTE_ORIGINAL, true) && $invoice->document_type->value === '388';
    }

    // ------------------------------------------------------------------ correction editor

    public function edit(Request $request, string $publicId): View
    {
        $invoice = $this->invoice($request, $publicId);
        abort_unless(self::canCorrect($invoice), 404);
        $current = InvoiceVersion::query()->findOrFail($invoice->current_version_id);

        return view('zatca::dashboard.invoices.correct', [
            'invoice' => $invoice,
            'current' => $current,
            'draft' => old('payload', self::pretty((array) $current->payload)),
            'zatcaErrors' => ZatcaError::query()->where('invoice_id', $invoice->id)->where('is_resolved', false)->orderByDesc('id')->limit(50)->get(['code', 'message', 'category']),
            'versions' => InvoiceVersion::query()->where('invoice_id', $invoice->id)->orderBy('version')->get(['id', 'version', 'kind', 'reason', 'created_by', 'created_at']),
            'fourEyes' => (bool) config('zatca.correction.four_eyes', false),
        ]);
    }

    public function update(Request $request, string $publicId, InvoiceOperations $operations): RedirectResponse
    {
        $invoice = $this->invoice($request, $publicId);
        $payload = $this->decode((string) $request->input('payload', ''));
        if (is_string($payload)) {
            return back()->withInput()->with('zatca_error', $payload);
        }

        try {
            if ($request->input('intent') === 'validate') {
                return back()->withInput()->with('zatca_check', self::report($operations->previewCorrection($invoice, $payload)));
            }
            $result = $operations->correct($invoice, $payload, (string) $request->input('reason', ''), $this->actorId($request));
        } catch (InvoiceValidationException $e) {
            return back()->withInput()->with('zatca_check', self::report($e->result));
        } catch (OperationNotAllowed|ZatcaException|\InvalidArgumentException $e) {
            return back()->withInput()->with('zatca_error', $e->getMessage());
        }

        $data = $result->toArray();
        $message = isset($data['counter'])
            ? "Correction saved and signed as counter {$data['counter']}."
            : 'Correction saved. ' . $result->message;

        return redirect($this->zroute('invoices.show', ['publicId' => $invoice->public_id, 'tab' => 'versions']))
            ->with($result->success ? 'zatca_status' : 'zatca_error', $message);
    }

    // ------------------------------------------------------------------ credit / debit notes

    public function noteForm(Request $request, string $publicId, string $kind): View
    {
        $invoice = $this->invoice($request, $publicId);
        abort_unless(self::canIssueNotes($invoice), 404);
        $payload = (array) InvoiceVersion::query()->whereKey($invoice->current_version_id)->value('payload');
        $lines = $kind === 'credit'
            ? (array) ($payload['lines'] ?? [])
            : [['name' => '', 'quantity' => '1', 'unit_price' => '0.00', 'vat_category' => 'S', 'vat_rate' => '15']];
        $count = Invoice::query()->where('billing_reference_invoice_id', $invoice->id)->count() + 1;

        return view('zatca::dashboard.invoices.note', [
            'invoice' => $invoice,
            'kind' => $kind,
            'lines' => old('lines', self::pretty(array_values($lines))),
            'number' => old('invoice_number', $invoice->invoice_number . ($kind === 'credit' ? '-CN' : '-DN') . $count),
            'creditable' => $invoice->creditableAmount(),
        ]);
    }

    public function issueNote(Request $request, string $publicId, string $kind, InvoiceOperations $operations): RedirectResponse
    {
        $invoice = $this->invoice($request, $publicId);
        $lines = $this->decode((string) $request->input('lines', ''));
        if (is_string($lines)) {
            return back()->withInput()->with('zatca_error', $lines);
        }
        if ($lines === [] || ! array_is_list($lines)) {
            return back()->withInput()->with('zatca_error', 'Lines must be a JSON list with at least one line: [{"name": …, "quantity": …, …}].');
        }
        $payload = ['invoice_number' => trim((string) $request->input('invoice_number', '')), 'lines' => $lines];
        $reason = (string) $request->input('reason', '');

        try {
            $result = $kind === 'credit'
                ? $operations->creditNote($invoice, $payload, $reason, $this->actorId($request))
                : $operations->debitNote($invoice, $payload, $reason, $this->actorId($request));
        } catch (OperationNotAllowed|ZatcaException|\InvalidArgumentException $e) {
            return back()->withInput()->with('zatca_error', $e->getMessage());
        }

        if (! $result->success) {
            $errors = array_map(static fn ($e) => trim(($e['code'] ?? '') . ' ' . ($e['message'] ?? '')), $result->errors);

            return back()->withInput()->with('zatca_error', $result->message . ($errors !== [] ? ': ' . implode(' · ', array_slice($errors, 0, 10)) : ''));
        }

        $note = $result->invoice;

        return redirect($this->zroute('invoices.show', $note?->public_id ?? $invoice->public_id))
            ->with('zatca_status', ucfirst($kind) . " note {$note?->invoice_number} issued (counter {$result->toArray()['counter']}).");
    }

    // ------------------------------------------------------------------ helpers

    private function invoice(Request $request, string $publicId): Invoice
    {
        $invoice = Invoice::query()->where('public_id', $publicId)->firstOrFail();
        abort_unless(app(\CodingTriplets\Zatca\Laravel\Dashboard\PermissionResolver::class)->allows($this->user($request), Abilities::view($invoice->workflow->value), $invoice), 403);
        $this->authorizeAbility($request, Abilities::INVOICES_CORRECT, $invoice);

        return $invoice;
    }

    /** @return array<mixed>|string the decoded JSON array, or an error message */
    private function decode(string $json): array|string
    {
        if (strlen($json) > self::MAX_JSON_BYTES) {
            return 'The JSON is larger than 1 MB.';
        }
        try {
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return 'Invalid JSON: ' . $e->getMessage() . '. Nothing was saved.';
        }

        return is_array($value) ? $value : 'The JSON must be an object or a list.';
    }

    /** @return array{valid: bool, errors: list<array<string, string>>, warnings: list<array<string, string>>} */
    private static function report(ValidationResult $result): array
    {
        return ['valid' => $result->isValid(), 'errors' => array_slice($result->errors(), 0, 100), 'warnings' => array_slice($result->warnings(), 0, 100)];
    }

    private static function pretty(array $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
