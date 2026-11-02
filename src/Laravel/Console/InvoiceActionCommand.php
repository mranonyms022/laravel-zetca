<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Console;

use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\InvoiceValidationException;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Invoices\InvoiceOperations;
use CodingTriplets\Zatca\Laravel\Invoices\OperationNotAllowed;
use Illuminate\Console\Command;

/**
 * Operator actions on one invoice (the dashboard offers the same in M7).
 *   zatca:invoice show    01J...
 *   zatca:invoice retry   01J... [--now]
 *   zatca:invoice reissue 01J... --reason="signature fixed"
 *   zatca:invoice correct 01J... --file=corrected.json --reason="wrong VAT category"
 *   zatca:invoice approve 01J... --actor=7
 *   zatca:invoice credit  01J... --file=note.json --reason="returned"   (also: debit)
 *   zatca:invoice cancel  01J... --reason="order cancelled"            (full credit note)
 *   zatca:invoice discard 01J... --reason="entered twice"              (never signed)
 *   zatca:invoice abandon 01J... --reason="sale reversed"              (rejected)
 */
final class InvoiceActionCommand extends Command
{
    protected $signature = 'zatca:invoice
        {action : show | retry | reissue | correct | approve | credit | debit | cancel | discard | abandon}
        {invoice : Invoice ID (public ULID)}
        {--reason= : Required for reissue, correct, credit, debit, cancel, discard and abandon}
        {--file= : JSON payload (correct, credit, debit)}
        {--actor= : User ID recorded as the actor}
        {--now : retry: submit immediately instead of queuing}';

    protected $description = 'Show, retry, reissue, correct, approve, credit, debit, cancel, discard or abandon one invoice';

    public function handle(InvoiceOperations $operations): int
    {
        $id = (string) $this->argument('invoice');
        $actor = $this->option('actor') !== null ? (int) $this->option('actor') : null;

        try {
            switch ((string) $this->argument('action')) {
                case 'show':
                    $invoice = Invoice::query()->where('public_id', $id)->firstOrFail();
                    $this->table(['Field', 'Value'], collect([
                        'invoice_number' => $invoice->invoice_number, 'workflow' => $invoice->workflow->value,
                        'type' => $invoice->document_type->value, 'processing' => $invoice->processing_status->value,
                        'reporting' => $invoice->reporting_status->value, 'clearance' => $invoice->clearance_status->value,
                        'issuances' => $invoice->issuance_count, 'attempts' => $invoice->attempts,
                        'unresolved errors' => $invoice->unresolved_error_count, 'last error' => $invoice->last_error_code,
                    ])->map(static fn ($v, $k) => [$k, (string) $v])->values()->all());

                    return self::SUCCESS;

                case 'retry':
                    $this->info('Submission: ' . $operations->retry($id, $actor, (bool) $this->option('now')));

                    return self::SUCCESS;

                case 'reissue':
                    $this->printResult($operations->reissueUnchanged($id, (string) $this->option('reason'), $actor)->toArray());

                    return self::SUCCESS;

                case 'correct':
                case 'credit':
                case 'debit':
                    $file = (string) $this->option('file');
                    $payload = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
                    if (! is_array($payload)) {
                        $this->error('--file must point to a JSON object.');

                        return self::INVALID;
                    }
                    $reason = (string) $this->option('reason');
                    $result = match ((string) $this->argument('action')) {
                        'correct' => $operations->correct($id, $payload, $reason, $actor),
                        'credit' => $operations->creditNote($id, $payload, $reason, $actor),
                        default => $operations->debitNote($id, $payload, $reason, $actor),
                    };
                    $this->printResult($result->toArray());

                    return self::SUCCESS;

                case 'cancel':
                    $this->printResult($operations->cancel($id, (string) $this->option('reason'), $actor)->toArray());

                    return self::SUCCESS;

                case 'discard':
                case 'abandon':
                    $action = (string) $this->argument('action');
                    $invoice = $operations->{$action}($id, (string) $this->option('reason'), $actor);
                    $this->info("Invoice {$id}: {$invoice->processing_status->value}");

                    return self::SUCCESS;

                case 'approve':
                    if ($actor === null) {
                        $this->error('--actor is required (the approving user).');

                        return self::INVALID;
                    }
                    $this->printResult($operations->approve($id, $actor)->toArray());

                    return self::SUCCESS;
            }
        } catch (OperationNotAllowed|\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (InvoiceValidationException $e) {
            $this->error('The corrected payload is invalid; nothing was saved.');
            foreach ($e->result->errors() as $error) {
                $this->line("  {$error['field']} [{$error['code']}] {$error['message']}");
            }

            return self::FAILURE;
        }

        $this->error('Unknown action.');

        return self::INVALID;
    }

    /** @param array<string, mixed> $result */
    private function printResult(array $result): void
    {
        unset($result['qr']);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
