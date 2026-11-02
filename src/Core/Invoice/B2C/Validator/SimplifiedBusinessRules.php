<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\B2C\Validator;

use CodingTriplets\Zatca\Core\Domain\Enums\BuyerIdScheme;
use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Core\Domain\Exceptions\WorkflowMismatch;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\VatNumber;
use CodingTriplets\Zatca\Core\Invoice\Shared\DTOs\InvoiceDocument;
use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\CommonBusinessRules;
use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\ValidationResult;

/** Rules for simplified (B2C) invoices and notes. Buyer details are optional. */
final class SimplifiedBusinessRules
{
    public function __construct(private readonly CommonBusinessRules $common = new CommonBusinessRules())
    {
    }

    public function validate(InvoiceDocument $doc, ?\DateTimeImmutable $now = null): ValidationResult
    {
        if ($doc->workflow !== Workflow::B2C) {
            throw WorkflowMismatch::withContext('SimplifiedBusinessRules received a B2B document.');
        }

        $result = new ValidationResult();
        $this->common->validate($doc, $result, $now);

        $buyer = $doc->buyer;

        // BR-KSA-49: private education / healthcare zero-rating for citizens needs the national ID.
        foreach ($doc->lines as $i => $line) {
            if (in_array($line->vat->reasonCode, ['VATEX-SA-EDU', 'VATEX-SA-HEA'], true)
                && ($buyer === null || $buyer->idScheme !== BuyerIdScheme::Nat->value || $buyer->idValue === null)) {
                $result->error("lines.{$i}.exemption_reason_code", 'BR-KSA-49', 'VATEX-SA-EDU / VATEX-SA-HEA require the buyer national ID (identification scheme NAT).');
            }
        }

        if ($buyer !== null) {
            if ($buyer->vatNumber !== null && $buyer->address->country === 'SA' && ! VatNumber::isValid($buyer->vatNumber)) {
                $result->error('buyer.vat_number', 'BR-KSA-44', 'Buyer VAT number must be 15 digits, starting and ending with 3.');
            }
            if ($buyer->idScheme !== null && BuyerIdScheme::tryFrom($buyer->idScheme) === null) {
                $result->error('buyer.identification.scheme', 'BR-KSA-14', 'Unsupported buyer ID scheme.');
            }
            if ($buyer->idValue !== null && preg_match('/^[a-zA-Z0-9]+$/', $buyer->idValue) !== 1) {
                $result->error('buyer.identification.value', 'BR-KSA-14', 'Buyer ID must be alphanumeric.');
            }
        }

        return $result;
    }
}
