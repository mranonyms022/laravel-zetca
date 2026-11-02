<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Http\Controllers;

use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Invoices\DeviceUnavailable;
use CodingTriplets\Zatca\Laravel\Invoices\DuplicateInvoice;
use CodingTriplets\Zatca\Laravel\Invoices\InvoiceIssuer;
use CodingTriplets\Zatca\Laravel\Invoices\IssueResult;
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

    private function issue(InvoiceIssuer $issuer, Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        if (! is_array($payload) || $payload === []) {
            return new JsonResponse(['status' => false, 'message' => 'a JSON object is required'], 400);
        }

        $options = array_filter([
            'device' => $request->header('X-Zatca-Device'),
            'external_id' => $request->header('Idempotency-Key'),
            'actor_id' => $request->user()?->getAuthIdentifier(),
        ], static fn ($v) => $v !== null && $v !== '');

        try {
            $result = $issuer->issue($payload, $options);
        } catch (DuplicateInvoice $e) {
            return new JsonResponse(['status' => false, 'message' => $e->getMessage(), 'invoice_id' => $e->publicId], 409);
        } catch (DeviceUnavailable $e) {
            return new JsonResponse(['status' => false, 'message' => $e->getMessage()], 503, ['Retry-After' => '60']);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['status' => false, 'message' => $e->getMessage()], 422);
        }

        return new JsonResponse($result->toArray(), $result->httpStatus);
    }
}
