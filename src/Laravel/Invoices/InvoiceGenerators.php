<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Invoices;

use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Core\Invoice\B2B\Generator\StandardInvoiceGenerator;
use CodingTriplets\Zatca\Core\Invoice\B2B\Validator\StandardBusinessRules;
use CodingTriplets\Zatca\Core\Invoice\B2C\Generator\SimplifiedInvoiceGenerator;
use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\InvoicePayloadMapper;

/** The B2B and B2C generators, configured from `config/zatca.php`. Never mixes the two. */
final class InvoiceGenerators
{
    private ?StandardInvoiceGenerator $standard = null;

    private ?SimplifiedInvoiceGenerator $simplified = null;

    public function for(Workflow $workflow): StandardInvoiceGenerator|SimplifiedInvoiceGenerator
    {
        $mapper = new InvoicePayloadMapper('Asia/Riyadh', (bool) config('zatca.b2c.infer_standard_vat_category', true));

        return $workflow === Workflow::B2B
            ? $this->standard ??= new StandardInvoiceGenerator($mapper, new StandardBusinessRules(requireBuyerIdentification: (bool) config('zatca.b2b.require_buyer_identification', true)))
            : $this->simplified ??= new SimplifiedInvoiceGenerator($mapper);
    }
}
