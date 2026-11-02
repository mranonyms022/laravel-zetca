<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\B2B\Generator;

use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Core\Invoice\B2B\Validator\StandardBusinessRules;
use CodingTriplets\Zatca\Core\Invoice\Shared\Chain\ChainPosition;
use CodingTriplets\Zatca\Core\Invoice\Shared\Generation\GeneratedInvoice;
use CodingTriplets\Zatca\Core\Invoice\Shared\Generation\InvoiceBuilder;
use CodingTriplets\Zatca\Core\Invoice\Shared\Generation\SigningCredentials;
use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\InvoicePayloadMapper;
use CodingTriplets\Zatca\Core\Invoice\Shared\Validation\ValidationResult;

/**
 * B2B pipeline step 1: validate a standard invoice / note and produce the signed XML to be cleared.
 * Only B2B payloads are accepted (a B2C payload is rejected, never converted).
 */
final class StandardInvoiceGenerator
{
    public function __construct(
        private readonly InvoicePayloadMapper $mapper = new InvoicePayloadMapper(),
        private readonly StandardBusinessRules $rules = new StandardBusinessRules(),
        private readonly InvoiceBuilder $builder = new InvoiceBuilder(),
    ) {
    }

    /** Validation only (no ICV, no signing). */
    public function validate(array $payload, ?\DateTimeImmutable $now = null): ValidationResult
    {
        $result = new ValidationResult();
        $doc = $this->mapper->map($payload, $result, Workflow::B2B);

        return $doc === null ? $result : $result->merge($this->rules->validate($doc, $now));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws \CodingTriplets\Zatca\Core\Invoice\Shared\Validation\InvoiceValidationException
     */
    public function generate(array $payload, ChainPosition $position, string $uuid, SigningCredentials $credentials, ?\DateTimeImmutable $now = null): GeneratedInvoice
    {
        $result = new ValidationResult();
        $doc = $this->mapper->map($payload, $result, Workflow::B2B);
        if ($doc !== null) {
            $result->merge($this->rules->validate($doc, $now));
        }
        $result->throwIfInvalid();
        assert($doc !== null);

        return $this->builder->build($doc, $position, $uuid, $credentials, $now ?? new \DateTimeImmutable(), $result->warnings());
    }
}
