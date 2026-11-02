<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\Generation;

use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Core\Invoice\Shared\Calculation\InvoiceTotals;
use CodingTriplets\Zatca\Core\Invoice\Shared\Chain\ChainPosition;
use CodingTriplets\Zatca\Core\Invoice\Shared\DTOs\InvoiceDocument;
use CodingTriplets\Zatca\Core\Invoice\Shared\Signing\XadesSigner;
use CodingTriplets\Zatca\Core\Invoice\Shared\Xml\UblInvoiceWriter;

/**
 * Totals → UBL XML (final layout) → hash → XAdES signature → QR. Used by the B2B and B2C generators
 * after their workflow-specific validation; not meant to be called directly.
 *
 * @internal
 */
final class InvoiceBuilder
{
    public function __construct(
        private readonly UblInvoiceWriter $writer = new UblInvoiceWriter(),
        private readonly XadesSigner $signer = new XadesSigner(),
    ) {
    }

    /** @param list<array{field: string, code: string, message: string}> $warnings */
    public function build(InvoiceDocument $doc, ChainPosition $position, string $uuid, SigningCredentials $credentials, \DateTimeImmutable $signingTime, array $warnings = []): GeneratedInvoice
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) !== 1) {
            throw new \InvalidArgumentException('Invoice UUID must be an RFC 4122 UUID.');
        }

        $totals = InvoiceTotals::calculate($doc);
        $xml = $this->writer->write($doc, $totals, strtolower($uuid), $position->counter, $position->previousHash->value);

        $signed = $this->signer->sign($xml, $credentials->certificate, $credentials->privateKey, $signingTime, [
            'seller_name' => $doc->seller->name,
            'vat_number' => (string) $doc->seller->vatNumber,
            'timestamp' => $doc->issuedAt->format('Y-m-d\TH:i:s'),
            'total' => $totals->payable->format(2),          // BT-115 (ZATCA: tag 4 = amount due)
            'vat_total' => $totals->taxTotal->format(2),
        ], simplified: $doc->workflow === Workflow::B2C);

        return new GeneratedInvoice($doc, $totals, strtolower($uuid), $position->counter, $position->previousHash->value, $signed, $warnings);
    }
}
