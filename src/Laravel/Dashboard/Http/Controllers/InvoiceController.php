<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers;

use CodingTriplets\Zatca\Core\Contracts\Storage\ArtifactStore;
use CodingTriplets\Zatca\Core\Contracts\Storage\StoredArtifact;
use CodingTriplets\Zatca\Core\Domain\Enums\ArtifactKind;
use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Laravel\Dashboard\Abilities;
use CodingTriplets\Zatca\Laravel\Dashboard\PermissionResolver;
use CodingTriplets\Zatca\Laravel\Dashboard\Queries\InvoiceListQuery;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceArtifact;
use CodingTriplets\Zatca\Laravel\Invoices\InvoiceOperations;
use CodingTriplets\Zatca\Laravel\Invoices\OperationNotAllowed;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** B2B / B2C lists, invoice detail, operator actions and XML download. */
final class InvoiceController
{
    /** action => [ability, needs reason] */
    private const ACTIONS = [
        'retry' => [null, false],                                  // ability depends on the workflow
        'reissue' => [Abilities::INVOICES_REPROCESS, true],
        'approve' => [Abilities::INVOICES_REPROCESS, false],
        'cancel' => [Abilities::INVOICES_CORRECT, true],
        'abandon' => [Abilities::INVOICES_CORRECT, true],
        'discard' => [Abilities::INVOICES_EDIT, true],
    ];

    public const TABS = ['summary', 'lines', 'versions', 'issuances', 'attempts', 'errors', 'history', 'related'];

    public function __construct(private readonly PermissionResolver $permissions)
    {
    }

    public function index(Request $request, string $workflow, InvoiceListQuery $query): View
    {
        $flow = Workflow::from($workflow);
        abort_unless($this->permissions->allows($this->user($request), Abilities::view($flow->value)), 403);
        $filters = $request->only(['tab', 'q', 'device', 'type', 'from', 'to']);

        return view('zatca::dashboard.invoices.index', [
            'workflow' => $flow,
            'tabs' => InvoiceListQuery::tabs($flow),
            'tab' => isset(InvoiceListQuery::tabs($flow)[$filters['tab'] ?? '']) ? $filters['tab'] : 'all',
            'filters' => $filters,
            'invoices' => $query->paginate($flow, $filters),
            'devices' => Device::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, string $publicId): View
    {
        $invoice = $this->find($request, $publicId);
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'summary';

        $invoice->load(['device:id,name,environment', 'billingReference:id,public_id,invoice_number', 'cancellationNote:id,public_id,invoice_number']);
        $data = match ($tab) {
            'lines' => ['lines' => $invoice->lines()->orderBy('line_no')->get(), 'vat' => $invoice->vatBreakdown()->get()],
            'versions' => ['versions' => $invoice->versions()->orderBy('version')->get()],
            'issuances' => ['issuances' => $invoice->issuances()->orderBy('sequence')->get(),
                'artifacts' => InvoiceArtifact::query()->where('invoice_id', $invoice->id)->get()->groupBy('issuance_id')],
            'attempts' => ['attempts' => $invoice->attempts()->orderByDesc('id')->limit(200)->get()],
            'errors' => ['zatcaErrors' => $invoice->errors()->orderByDesc('id')->get(), 'warnings' => $invoice->warnings()->orderByDesc('id')->get()],
            'history' => ['logs' => $invoice->processingLogs()->orderByDesc('id')->limit(500)->get()],
            'related' => ['notes' => $invoice->adjustmentNotes()->orderByDesc('id')->get()],
            default => [],
        };

        return view('zatca::dashboard.invoices.show', [
            'invoice' => $invoice,
            'tab' => $tab,
            'actions' => $this->availableActions($request, $invoice),
        ] + $data);
    }

    public function action(Request $request, string $publicId, string $action, InvoiceOperations $operations): RedirectResponse
    {
        abort_unless(isset(self::ACTIONS[$action]), 404);
        $invoice = $this->find($request, $publicId);
        abort_unless($this->allowed($request, $invoice, $action), 403);

        $reason = trim((string) $request->input('reason', ''));
        if (self::ACTIONS[$action][1] && $reason === '') {
            return back()->withErrors(['reason' => 'A reason is required.'])->withInput();
        }
        $reason = mb_substr($reason, 0, 1000);
        $actor = $this->actorId($request);

        try {
            $message = match ($action) {
                'retry' => 'Submission ' . $operations->retry($invoice, $actor),
                'reissue' => 'Reissued as counter ' . $operations->reissueUnchanged($invoice, $reason, $actor)->toArray()['counter'],
                'approve' => $this->approve($operations, $invoice, $actor),
                'cancel' => 'Credit note ' . $operations->cancel($invoice, $reason, $actor)->toArray()['invoice_id'] . ' issued',
                'abandon' => 'Invoice ' . $operations->abandon($invoice, $reason, $actor)->processing_status->value,
                'discard' => 'Invoice ' . $operations->discard($invoice, $reason, $actor)->processing_status->value,
            };
        } catch (OperationNotAllowed|\InvalidArgumentException $e) {
            return back()->with('zatca_error', $e->getMessage());
        }

        return redirect()->route(config('zatca.ui.route_name_prefix', 'zatca.') . 'invoices.show', $invoice->public_id)->with('zatca_status', $message);
    }

    public function artifact(Request $request, string $publicId, int $artifactId, ArtifactStore $store): Response
    {
        $invoice = $this->find($request, $publicId);
        abort_unless($this->permissions->allows($this->user($request), Abilities::INVOICES_EXPORT, $invoice), 403);

        $artifact = InvoiceArtifact::query()->where('invoice_id', $invoice->id)->whereKey($artifactId)->firstOrFail();
        abort_unless(in_array($artifact->kind, [ArtifactKind::SignedXml, ArtifactKind::ClearedXml], true), 404);
        $xml = $store->get(new StoredArtifact($artifact->disk, $artifact->path, $artifact->sha256, (int) $artifact->bytes, (bool) $artifact->encrypted));

        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', "{$invoice->invoice_number}_{$artifact->kind->value}") . '.xml';

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$name}\"",
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ------------------------------------------------------------------ helpers

    private function approve(InvoiceOperations $operations, Invoice $invoice, ?int $actor): string
    {
        if ($actor === null) {
            throw new \InvalidArgumentException('Approval needs a numeric user ID.');
        }

        return 'Approved; counter ' . $operations->approve($invoice, $actor)->toArray()['counter'];
    }

    /** @return list<string> actions the user may try (the operation itself still checks the invoice state) */
    private function availableActions(Request $request, Invoice $invoice): array
    {
        $status = $invoice->processing_status->value;
        $candidates = match (true) {
            in_array($status, ['QUEUED', 'RETRY_PENDING', 'FAILED', 'OUTCOME_UNKNOWN', 'MANUAL_REVIEW'], true) && $invoice->current_issuance_id !== null => ['retry'],
            default => [],
        };
        if ($status === 'REJECTED') {
            $candidates = array_merge($candidates, ['reissue', 'abandon']);
        }
        if ($status === 'MANUAL_REVIEW') {
            $candidates = array_merge($candidates, $invoice->requires_approval ? ['approve'] : [], $invoice->current_issuance_id === null ? ['discard'] : ['abandon']);
        }
        if (in_array($status, ['VALIDATION_FAILED', 'READY_FOR_PROCESSING', 'FAILED'], true) && $invoice->current_issuance_id === null) {
            $candidates[] = 'discard';
        }
        if (in_array($status, ['REPORTED', 'CLEARED'], true) && $invoice->document_type->value === '388'
            && $invoice->cancellation_invoice_id === null && (float) $invoice->credited_amount === 0.0) {
            $candidates[] = 'cancel';
        }

        return array_values(array_filter(array_unique($candidates), fn (string $a) => $this->allowed($request, $invoice, $a)));
    }

    private function allowed(Request $request, Invoice $invoice, string $action): bool
    {
        $ability = self::ACTIONS[$action][0] ?? Abilities::retry($invoice->workflow->value);

        return $this->permissions->allows($this->user($request), $ability, $invoice);
    }

    /** Finds the invoice and checks the workflow view ability (tenant scope applies through the model). */
    private function find(Request $request, string $publicId): Invoice
    {
        $invoice = Invoice::query()->where('public_id', $publicId)->firstOrFail();
        abort_unless($this->permissions->allows($this->user($request), Abilities::view($invoice->workflow->value), $invoice), 403);

        return $invoice;
    }

    private function user(Request $request): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        $guard = config('zatca.ui.guard');

        return $request->user($guard ?: null);
    }

    private function actorId(Request $request): ?int
    {
        $id = $this->user($request)?->getAuthIdentifier();

        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;
    }
}
