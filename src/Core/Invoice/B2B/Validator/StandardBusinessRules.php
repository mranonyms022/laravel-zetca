<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\B2B\Validator;

use CodingTriplets\Zatca\Core\Domain\Enums\BuyerIdScheme;
use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Core\Domain\Exceptions\WorkflowMismatch;
use CodingTriplets\Zatca\Core\Domain\ValueObjects\VatNumber;
use CodingTriplets\Zatca\Core\Invoice\Shared\DTOs\InvoiceDocument;
use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\CommonBusinessRules;
use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\ValidationResult;

/** Rules for standard (B2B) invoices and notes: buyer identification and address (ARCHITECTURE 2.2). */
final class StandardBusinessRules
{
    public function __construct(
        private readonly CommonBusinessRules $common = new CommonBusinessRules(),
        private readonly bool $requireBuyerIdentification = true,
    ) {
    }

    public function validate(InvoiceDocument $doc, ?\DateTimeImmutable $now = null): ValidationResult
    {
        if ($doc->workflow !== Workflow::B2B) {
            throw WorkflowMismatch::withContext('StandardBusinessRules received a B2C document.');
        }

        $result = new ValidationResult();
        $this->common->validate($doc, $result, $now);

        $buyer = $doc->buyer;
        if ($buyer === null) {
            $result->error('buyer', 'BR-KSA-42', 'Standard (B2B) invoices require buyer details.');

            return $result;
        }

        if ($buyer->name === '') {
            $result->error('buyer.name', 'BR-KSA-42', 'Buyer name is required for standard invoices.');
        }

        $isExport = substr($doc->subtypeFlags, 4, 1) === '1';

        if ($buyer->vatNumber !== null) {
            if ($buyer->address->country === 'SA' && ! VatNumber::isValid($buyer->vatNumber)) {
                $result->error('buyer.vat_number', 'BR-KSA-44', 'Buyer VAT number must be 15 digits, starting and ending with 3.');
            }
            if ($buyer->vatNumber === $doc->seller->vatNumber) {
                $result->error('buyer.vat_number', 'BR-CUSTOM-VALIDATION-01', 'Buyer VAT number must differ from the seller VAT number.');
            }
            if ($isExport) {
                $result->error('buyer.vat_number', 'BR-KSA-46', 'Export invoices must not contain a buyer VAT number.');
            }
        } elseif ($buyer->idScheme === null || $buyer->idValue === null) {
            if ($this->requireBuyerIdentification) {
                $result->error('buyer.identification', 'BR-KSA-81', 'Buyer without a VAT number needs another ID (e.g. CRN, or NAT for a national ID).');
            } else {
                $result->warning('buyer.identification', 'BR-KSA-81', 'Buyer has neither a VAT number nor another ID.');
            }
        }

        if ($buyer->idScheme !== null) {
            if (BuyerIdScheme::tryFrom($buyer->idScheme) === null) {
                $result->error('buyer.identification.scheme', 'BR-KSA-14', 'Unsupported buyer ID scheme.');
            }
            if ($buyer->idValue === null || preg_match('/^[a-zA-Z0-9]+$/', $buyer->idValue) !== 1) {
                $result->error('buyer.identification.value', 'BR-KSA-14', 'Buyer ID must be alphanumeric.');
            }
        }

        $this->common->saudiAddress($buyer->address, 'buyer.address', $result, sellerRules: false);

        return $result;
    }
}
