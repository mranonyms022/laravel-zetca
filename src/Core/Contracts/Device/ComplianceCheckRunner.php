<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Contracts\Device;

use CodingTriplets\Zatca\Core\Contracts\Fatoora\CsidCredentials;

/**
 * Runs ZATCA's onboarding compliance checks (sample documents signed with the compliance CSID,
 * POST /compliance/invoices) for the device's invoice types. Implemented by the invoice engine (M4).
 */
interface ComplianceCheckRunner
{
    /**
     * @param array<string, mixed> $device device attributes (id, invoice_type_flags, environment, ...)
     * @return array<string, array{passed: bool, status: ?string, errors: list<array{code: string, message: string}>}>
     *         keyed by check name (e.g. "standard_invoice", "simplified_credit_note")
     */
    public function run(array $device, CsidCredentials $compliance, string $privateKeyPem): array;
}
