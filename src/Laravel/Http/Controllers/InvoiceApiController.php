<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Http\Controllers;

use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\InvoiceValidationException;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Invoices\DeviceUnavailable;
use CodingTriplets\Zatca\Laravel\Invoices\DuplicateInvoice;
use CodingTriplets\Zatca\Laravel\Invoices\InvoiceIssuer;
use CodingTriplets\Zatca\Laravel\Invoices\InvoiceOperations;
use CodingTriplets\Zatca\Laravel\Invoices\IssueResult;
use CodingTriplets\Zatca\Laravel\Invoices\OperationNotAllowed;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class InvoiceApiController extends Controller
{
    /** POST {prefix}/b2c/invoices → {"status":true,"message":"invoice queued","qr":"...","counter":123,...} */
    public function b2c(Request $request): JsonResponse
    {
        return $this->issue(app('zatca.b2c'), $request);
    }

    /** POST {prefix}/b2b/invoices → issued and queued for clearance (share it with the buyer only once CLEARED). */
    public function b2b(Request $request): JsonResponse
    {
        return $this->issue(app('zatca.b2b'), $request);
    }

    public function show(string $publicId): JsonResponse
    {
        $invoice = Invoice::query()->where('public_id', $publicId)->first();
        if ($invoice === null) {
            return new JsonResponse(['status' => false, 'message' => 'invoice not found'], 404);
        }
        $result = IssueResult::fromInvoice($invoice, true);

        return new JsonResponse($result->toArray(), 200);
    }

    /**
     * POST {prefix}/invoices/{invoice_id}/credit-note: body = note payload + "reason" (+ optional "device").
     * The note runs in the original's workflow (B2C → reported, B2B → cleared).
     */
    public function creditNote(Request $request, string $publicId): JsonResponse
    {
        return $this->note($request, $publicId, 'credit');
    }

    /** POST {prefix}/invoices/{invoice_id}/debit-note: body = note payload + "reason" (+ optional "device"). */
    public function debitNote(Request $request, string $publicId): JsonResponse
    {
        return $this->note($request, $publicId, 'debit');
    }

    /**
     * POST {prefix}/invoices/{invoice_id}/cancel: body {"reason": "...", "invoice_number": "CN-..." (optional)}.
     * Issues a full credit note for an accepted invoice; the response is the credit note's result.
     */
    public function cancel(Request $request, string $publicId): JsonResponse
    {
        $body = $request->json()->all();
        $reason = is_array($body) ? trim((string) ($body['reason'] ?? '')) : '';
        if ($reason === '') {
            return $this->invalid('reason', 'A reason is required.');
        }
        $options = array_filter([
            'invoice_number' => isset($body['invoice_number']) ? (string) $body['invoice_number'] : null,
            'external_id' => $request->header('Idempotency-Key'),
        ], static fn ($v) => $v !== null && $v !== '');
        $ops = app(InvoiceOperations::class);
        $actor = $this->actorId($request);

        return $this->run(static fn () => $ops->cancel($publicId, $reason, $actor, $options));
    }

    private function note(Request $request, string $publicId, string $kind): JsonResponse
    {
        [$payload, $device] = $this->payload($request);
        if ($payload === null) {
            return new JsonResponse(['status' => false, 'message' => 'a JSON object is required'], 400);
        }
        $reason = trim((string) ($payload['reason'] ?? ''));
        unset($payload['reason']);
        if ($reason === '') {
            return $this->invalid('reason', 'A reason is required (KSA-10).');
        }
        $options = array_filter([
            'device' => $device,
            'external_id' => $request->header('Idempotency-Key'),
        ], static fn ($v) => $v !== null && $v !== '');
        $ops = app(InvoiceOperations::class);
        $actor = $this->actorId($request);

        return $this->run(static fn () => $kind === 'credit'
            ? $ops->creditNote($publicId, $payload, $reason, $actor, $options)
            : $ops->debitNote($publicId, $payload, $reason, $actor, $options));
    }

    private function issue(InvoiceIssuer $issuer, Request $request): JsonResponse
    {
        [$payload, $device] = $this->payload($request);
        if ($payload === null) {
            return new JsonResponse(['status' => false, 'message' => 'a JSON object is required'], 400);
        }

        $options = array_filter([
            'device' => $device,
            'external_id' => $request->header('Idempotency-Key'),
            'actor_id' => $this->actorId($request),
        ], static fn ($v) => $v !== null && $v !== '');

        return $this->run(static fn () => $issuer->issue($payload, $options));
    }

    /**
     * The JSON body without the transport field "device". The device comes from the X-Zatca-Device header
     * or, when the header is absent, from "device" in the body (device ID or device UUID).
     *
     * @return array{0: ?array<string, mixed>, 1: int|string|null}
     */
    private function payload(Request $request): array
    {
        $payload = $request->json()->all();
        if (! is_array($payload) || $payload === []) {
            return [null, null];
        }
        $device = $request->header('X-Zatca-Device');
        if (($device === null || $device === '') && (is_string($payload['device'] ?? null) || is_int($payload['device'] ?? null))) {
            $device = $payload['device'];
        }
        unset($payload['device']);

        return [$payload, $device];
    }

    private function actorId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;
    }

    private function invalid(string $field, string $message): JsonResponse
    {
        return new JsonResponse([
            'status' => false,
            'message' => 'validation failed',
            'errors' => [['field' => $field, 'code' => 'REQUIRED', 'message' => $message]],
        ], 422);
    }

    /** @param \Closure(): IssueResult $call */
    private function run(\Closure $call): JsonResponse
    {
        try {
            $result = $call();
        } catch (ModelNotFoundException) {
            return new JsonResponse(['status' => false, 'message' => 'invoice not found'], 404);
        } catch (DuplicateInvoice $e) {
            return new JsonResponse(['status' => false, 'message' => $e->getMessage(), 'invoice_id' => $e->publicId], 409);
        } catch (OperationNotAllowed $e) {
            return new JsonResponse(['status' => false, 'message' => $e->getMessage()], 409);
        } catch (DeviceUnavailable $e) {
            return new JsonResponse(['status' => false, 'message' => $e->getMessage()], 503, ['Retry-After' => '60']);
        } catch (InvoiceValidationException|\InvalidArgumentException $e) {
            return new JsonResponse(['status' => false, 'message' => $e->getMessage()], 422);
        }

        return new JsonResponse($result->toArray(), $result->httpStatus);
    }
}
