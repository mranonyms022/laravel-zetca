<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Invoices;

use CodingTriplets\Zatca\Core\Contracts\Locks\AdvisoryLock;
use CodingTriplets\Zatca\Core\Contracts\Persistence\TransactionManager;
use CodingTriplets\Zatca\Core\Contracts\Storage\ArtifactStore;
use CodingTriplets\Zatca\Core\Domain\Enums\ArtifactKind;
use CodingTriplets\Zatca\Core\Domain\Enums\IssuanceReason;
use CodingTriplets\Zatca\Core\Domain\Enums\IssuanceStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\ProcessingStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\SubmissionStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Core\Domain\Exceptions\SequenceException;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\InvoiceHash;
use CodingTriplets\Zatca\Core\Events\InvoiceIssued;
use CodingTriplets\Zatca\Core\Contracts\Events\EventDispatcher;
use CodingTriplets\Zatca\Core\Invoice\Shared\Chain\ChainPosition;
use CodingTriplets\Zatca\Laravel\Database\Models\B2bClearanceSubmission;
use CodingTriplets\Zatca\Laravel\Database\Models\B2cReportingSubmission;
use CodingTriplets\Zatca\Laravel\Database\Models\ChainEntry;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceArtifact;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceCounter;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceIssuance;
use CodingTriplets\Zatca\Laravel\Database\Models\InvoiceVersion;
use CodingTriplets\Zatca\Laravel\Database\Models\Warning;
use CodingTriplets\Zatca\Laravel\Processing\Outbox\OutboxRecorder;
use CodingTriplets\Zatca\Laravel\Processing\ProcessingLogger;
use Illuminate\Support\Str;

/**
 * T2 issuance (ARCHITECTURE 13): allocates the next ICV and PIH of the device chain and commits the signed document.
 *
 * Correctness does not depend on the optional advisory lock:
 *  1. `SELECT ... FOR UPDATE` on the device counter row serializes issuance per device + environment;
 *  2. the counter update is a compare-and-set on `lock_version` (lost updates are impossible, also on SQLite);
 *  3. the chain ledger's unique keys (device, env, icv) and (device, env, previous hash) make duplicate ICVs and
 *     chain forks impossible at the storage level.
 *
 * Everything (artifact row, issuance, ledger entry, counter, invoice pointers, submission row, outbox message) commits
 * together. If anything fails, nothing is committed and the ICV is not consumed. No HTTP call happens here.
 */
final class ChainService
{
    public function __construct(
        private readonly TransactionManager $transactions,
        private readonly AdvisoryLock $locks,
        private readonly ArtifactStore $artifacts,
        private readonly InvoiceGenerators $generators,
        private readonly PayloadPreparer $preparer,
        private readonly SigningCredentialProvider $credentials,
        private readonly ProcessingLogger $logger,
        private readonly OutboxRecorder $outbox,
        private readonly EventDispatcher $events,
    ) {
    }

    /**
     * @param int $deferSubmissionSeconds delay before the queued submission may run (sync mode submits in-request;
     *                                    the queued message is then only a safety net)
     */
    public function issue(Invoice $invoice, IssuanceReason $reason = IssuanceReason::Initial, ?int $actorId = null, int $deferSubmissionSeconds = 0): InvoiceIssuance
    {
        $device = Device::query()->withoutGlobalScopes()->findOrFail($invoice->device_id);
        $signing = $this->credentials->signing($device);          // decrypt outside the transaction
        $this->ensureCounter($device);

        $work = fn (): InvoiceIssuance => $this->transactions->run(
            fn (): InvoiceIssuance => $this->issueLocked((int) $invoice->id, $device, $signing, $reason, $actorId, $deferSubmissionSeconds),
            attempts: 3,
        );

        // Optional advisory lock (reduces DB lock contention across servers; never relied on for correctness).
        return (bool) config('zatca.performance.advisory_chain_lock', false)
            ? $this->locks->withLock("chain:{$device->id}:{$device->environment->value}", 30, max(1, (int) ceil(((int) config('zatca.performance.counter_lock_wait_ms', 2000)) / 1000)), $work)
            : $work();
    }

    /** @param array{signing: \CodingTriplets\Zatca\Core\Invoice\Shared\Generation\SigningCredentials, certificateId: int, fallback: bool} $signing */
    private function issueLocked(int $invoiceId, Device $device, array $signing, IssuanceReason $reason, ?int $actorId, int $deferSubmissionSeconds): InvoiceIssuance
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($invoiceId);

        // Idempotent: an initial issuance that already exists is returned, never duplicated.
        if ($reason === IssuanceReason::Initial && $invoice->current_issuance_id !== null) {
            return InvoiceIssuance::query()->findOrFail($invoice->current_issuance_id);
        }
        if ($reason === IssuanceReason::Initial && $invoice->processing_status !== ProcessingStatus::ReadyForProcessing) {
            throw new \LogicException("Invoice {$invoice->public_id} is {$invoice->processing_status->value}, not READY_FOR_PROCESSING.");
        }
        if ((int) $invoice->device_id !== (int) $device->id) {
            throw new \LogicException('Invoice device changed during issuance.');
        }

        $workflow = $invoice->workflow;
        $env = $device->environment;
        /** @var InvoiceCounter $counter */
        $counter = InvoiceCounter::query()->where('device_id', $device->id)->where('environment', $env)->lockForUpdate()->firstOrFail();

        $icv = (int) $counter->last_icv + 1;
        $pih = $counter->last_invoice_hash !== null ? InvoiceHash::fromBase64((string) $counter->last_invoice_hash) : InvoiceHash::genesis();

        /** @var InvoiceVersion $version */
        $version = InvoiceVersion::query()->findOrFail($invoice->current_version_id);
        $payload = $this->preparer->prepare((array) $version->payload, $workflow, $device, (string) $invoice->invoice_number);
        $uuid = (string) Str::uuid();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Riyadh'));

        // Throws InvoiceValidationException / CredentialException → rollback, ICV not consumed.
        $generated = $this->generators->for($workflow)->generate($payload, new ChainPosition($icv, $pih), $uuid, $signing['signing'], $now);

        $stored = $this->artifacts->putImmutable(
            implode('/', array_filter([(string) $invoice->tenant_id, 'c' . $invoice->company_id, 'd' . $device->id, $now->format('Y/m')])),
            $generated->xml(),
        );

        $issuance = InvoiceIssuance::query()->create([
            'invoice_id' => $invoice->id,
            'sequence' => (int) $invoice->issuance_count + 1,
            'version_id' => $version->id,
            'uuid' => $generated->uuid,
            'device_id' => $device->id,
            'environment' => $env,
            'icv' => $icv,
            'previous_invoice_hash' => $pih->value,
            'invoice_hash' => $generated->hash(),
            'issue_date' => $generated->document->issuedAt->format('Y-m-d'),
            'issue_time' => $generated->document->issuedAt->format('H:i:s'),
            'certificate_id' => $signing['certificateId'],
            'signature_value' => $generated->signed->signature,
            'signing_time' => $now,
            'qr_payload' => $generated->qrCode(),
            'reason' => $reason,
            'supersedes_issuance_id' => $reason === IssuanceReason::Initial ? null : $invoice->current_issuance_id,
            'status' => IssuanceStatus::Issued,
            'issued_by' => $actorId,
            'issued_at' => now(),
        ]);

        InvoiceArtifact::query()->create([
            'issuance_id' => $issuance->id,
            'invoice_id' => $invoice->id,
            'kind' => ArtifactKind::SignedXml,
            'disk' => $stored->disk,
            'path' => $stored->path,
            'sha256' => $stored->sha256,
            'bytes' => $stored->bytes,
            'encrypted' => $stored->encrypted,
            'mime' => 'application/xml',
        ]);

        ChainEntry::query()->create([
            'device_id' => $device->id,
            'environment' => $env,
            'icv' => $icv,
            'invoice_hash' => $generated->hash(),
            'previous_invoice_hash' => $pih->value,
            'issuance_id' => $issuance->id,
            'committed_at' => now(),
        ]);

        $updated = InvoiceCounter::query()
            ->whereKey($counter->id)
            ->where('lock_version', $counter->lock_version)
            ->update([
                'last_icv' => $icv,
                'last_invoice_hash' => $generated->hash(),
                'last_issuance_id' => $issuance->id,
                'lock_version' => (int) $counter->lock_version + 1,
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            throw SequenceException::counterConflict((int) $device->id, $env->value);
        }

        InvoiceVersion::query()->whereKey($version->id)->whereNull('issuance_id')->update(['issuance_id' => $issuance->id]);

        $submission = $this->createSubmission($invoice, $issuance, $workflow, $now);

        $invoice->forceFill([
            'current_issuance_id' => $issuance->id,
            'issuance_count' => (int) $invoice->issuance_count + 1,
            'current_uuid' => $generated->uuid,
            'first_issued_at' => $invoice->first_issued_at ?? now(),
            'reporting_deadline_at' => $workflow === Workflow::B2C ? $submission->deadline_at : null,
        ]);
        $this->logger->log($invoice, 'processing', 'issued', $invoice->processing_status->value, ProcessingStatus::Signed->value, "ICV {$icv}", ['icv' => $icv, 'uuid' => $generated->uuid], (int) $issuance->id, $actorId);
        $this->logger->transition($invoice, [
            'processing_status' => ProcessingStatus::Queued,
            'submission_status' => SubmissionStatus::Pending,
        ], 'queued', issuanceId: (int) $issuance->id);
        $invoice->save();

        if ($signing['fallback']) {
            $invoice->increment('warning_count');
            Warning::query()->create([
                'tenant_id' => (string) $invoice->tenant_id,
                'invoice_id' => $invoice->id,
                'issuance_id' => $issuance->id,
                'workflow' => $workflow,
                'source' => 'local',
                'code' => 'SANDBOX_SIGNING_CERTIFICATE',
                'message' => 'Developer-portal sandbox: signed with the compliance certificate because the portal\'s production certificate does not belong to the device key.',
            ]);
        }

        $this->outbox->record(
            'submission',
            (int) $submission->id,
            OutboxRecorder::EVENT_SUBMIT,
            QueueNames::submission($workflow),
            "submit:{$generated->uuid}:1",
            ['workflow' => $workflow->value],
            $deferSubmissionSeconds > 0 ? now()->addSeconds($deferSubmissionSeconds) : null,
            (string) $invoice->tenant_id,
        );

        $event = new InvoiceIssued((int) $invoice->id, (string) $invoice->public_id, (int) $issuance->id, $workflow->value, $icv, $generated->uuid);
        $this->events->dispatchAfterCommit($event);

        return $issuance;
    }

    private function createSubmission(Invoice $invoice, InvoiceIssuance $issuance, Workflow $workflow, \DateTimeImmutable $now): B2bClearanceSubmission|B2cReportingSubmission
    {
        $common = ['issuance_id' => $issuance->id, 'invoice_id' => $invoice->id, 'status' => 'pending', 'attempts' => 0];

        return $workflow === Workflow::B2C
            ? B2cReportingSubmission::query()->create($common + [
                'deadline_at' => \Illuminate\Support\Carbon::instance($now)->addHours((int) config('zatca.b2c.reporting_deadline_hours', 24)),
            ])
            : B2bClearanceSubmission::query()->create($common);
    }

    /** Creates the device counter row once (outside the issuance transaction; race-safe). */
    private function ensureCounter(Device $device): void
    {
        $model = new InvoiceCounter();
        $model->getConnection()->table($model->getTable())->insertOrIgnore([
            'device_id' => $device->id,
            'environment' => $device->environment->value,
            'last_icv' => 0,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
