<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\Validation;

use CodingTriplets\Zatca\Core\Domain\Enums\DocumentType;
use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;
use CodingTriplets\Zatca\Core\Invoice\Shared\Calculation\Amount;
use CodingTriplets\Zatca\Core\Invoice\Shared\DTOs\Address;
use CodingTriplets\Zatca\Core\Invoice\Shared\DTOs\AllowanceCharge;
use CodingTriplets\Zatca\Core\Invoice\Shared\DTOs\InvoiceDocument;
use CodingTriplets\Zatca\Core\Invoice\Shared\DTOs\InvoiceLine;
use CodingTriplets\Zatca\Core\Invoice\Shared\DTOs\Party;
use CodingTriplets\Zatca\Core\Invoice\Shared\DTOs\VatCategory;

/**
 * Maps an invoice payload (array / decoded JSON) to an InvoiceDocument, collecting every structural
 * problem as a field-level error instead of stopping at the first one.
 *
 * Payload (keys in snake_case):
 *   workflow (b2b|b2c, optional if subtype given), type (invoice|credit_note|debit_note or 388|381|383),
 *   invoice_number, issued_at (ISO 8601, Asia/Riyadh if no offset), currency (SAR),
 *   subtype {third_party, nominal, export, summary, self_billed}, supply_date, supply_end_date,
 *   payment_means_code, billing_reference (string|list), reason, notes[], prepaid_amount,
 *   seller {name, vat_number, id_scheme, id_value, address{...}},
 *   buyer {name, vat_number, identification{scheme, value}, address{...}},
 *   lines [{name, quantity, unit_price, unit_code, discount, vat_category, vat_rate,
 *           exemption_reason_code, exemption_reason}],
 *   allowances [{amount, reason, reason_code, vat_category, vat_rate, ...}], charges [...]
 */
final class InvoicePayloadMapper
{
    public function __construct(
        private readonly string $timezone = 'Asia/Riyadh',
        private readonly bool $inferStandardCategory = true,
    ) {
    }

    /** @param array<string, mixed> $p */
    public function map(array $p, ValidationResult $result, ?Workflow $expected = null): ?InvoiceDocument
    {
        $workflow = $this->workflow($p, $result, $expected);
        $type = $this->documentType($p['type'] ?? 'invoice', $result);
        $subtype = $workflow !== null ? $this->subtype($workflow, (array) ($p['subtype'] ?? []), $result) : null;
        $issuedAt = $this->dateTime($p['issued_at'] ?? null, 'issued_at', $result, required: false) ?? new \DateTimeImmutable('now', new \DateTimeZone($this->timezone));

        $number = trim((string) ($p['invoice_number'] ?? ''));
        if ($number === '') {
            $result->error('invoice_number', 'BR-02', 'Invoice number is required.');
        } elseif (mb_strlen($number) > 127) {
            $result->error('invoice_number', 'BR-02', 'Invoice number must be at most 127 characters.');
        }

        $currency = strtoupper(trim((string) ($p['currency'] ?? 'SAR')));
        if ($currency !== 'SAR') {
            $result->error('currency', 'UNSUPPORTED', 'Only SAR invoices are supported in this version.');
        }

        $seller = $this->party((array) ($p['seller'] ?? []), 'seller', $result, required: true);
        $buyer = isset($p['buyer']) && is_array($p['buyer']) && $p['buyer'] !== []
            ? $this->party($p['buyer'], 'buyer', $result, required: false)
            : null;

        $lines = [];
        $rawLines = $p['lines'] ?? [];
        if (! is_array($rawLines) || $rawLines === []) {
            $result->error('lines', 'BR-16', 'An invoice must have at least one line.');
        } else {
            foreach (array_values($rawLines) as $i => $raw) {
                $line = $this->line(is_array($raw) ? $raw : [], $i, $result);
                if ($line !== null) {
                    $lines[] = $line;
                }
            }
        }

        $acs = [];
        foreach (['allowances' => false, 'charges' => true] as $key => $isCharge) {
            foreach (array_values((array) ($p[$key] ?? [])) as $i => $raw) {
                $ac = $this->allowanceCharge(is_array($raw) ? $raw : [], "{$key}.{$i}", $isCharge, $result);
                if ($ac !== null) {
                    $acs[] = $ac;
                }
            }
        }

        $refs = array_values(array_filter(array_map(
            static fn ($r) => trim((string) $r),
            is_array($p['billing_reference'] ?? null) ? $p['billing_reference'] : [($p['billing_reference'] ?? '')],
        ), static fn (string $r) => $r !== ''));

        $prepaid = $this->amount($p['prepaid_amount'] ?? '0', 'prepaid_amount', $result) ?? Amount::zero();
        if (! $prepaid->isZero()) {
            // BR-KSA-73/80: prepaid amounts need prepayment adjustment lines (KSA-26..34), not supported yet.
            $result->error('prepaid_amount', 'UNSUPPORTED', 'Advance payment adjustments (prepaid_amount) are not supported yet.');
        }

        if (! $result->isValid() || $workflow === null || $type === null || $subtype === null || $seller === null) {
            return null;
        }

        return new InvoiceDocument(
            $workflow,
            $type,
            $subtype,
            $number,
            $issuedAt,
            $currency,
            $seller,
            $buyer,
            $lines,
            $acs,
            $refs,
            isset($p['reason']) && trim((string) $p['reason']) !== '' ? trim((string) $p['reason']) : null,
            trim((string) ($p['payment_means_code'] ?? '10')) ?: '10',
            $this->dateTime($p['supply_date'] ?? null, 'supply_date', $result, required: false),
            $this->dateTime($p['supply_end_date'] ?? null, 'supply_end_date', $result, required: false),
            $prepaid,
            array_values(array_map('strval', (array) ($p['notes'] ?? []))),
        );
    }

    /** @param array<string, mixed> $p */
    private function workflow(array $p, ValidationResult $result, ?Workflow $expected): ?Workflow
    {
        $given = isset($p['workflow']) ? Workflow::tryFrom(strtolower((string) $p['workflow'])) : null;
        if (isset($p['workflow']) && $given === null) {
            $result->error('workflow', 'WORKFLOW', 'workflow must be "b2b" or "b2c".');

            return null;
        }

        $workflow = $given ?? $expected;
        if ($workflow === null) {
            $result->error('workflow', 'WORKFLOW', 'workflow ("b2b" or "b2c") is required.');

            return null;
        }
        if ($expected !== null && $workflow !== $expected) {
            $result->error('workflow', 'WORKFLOW', "This is the {$expected->value} pipeline; a {$workflow->value} invoice cannot be processed here.");

            return null;
        }

        return $workflow;
    }

    private function documentType(mixed $type, ValidationResult $result): ?DocumentType
    {
        $map = ['invoice' => '388', 'credit_note' => '381', 'debit_note' => '383'];
        $code = $map[strtolower((string) $type)] ?? (string) $type;
        $resolved = DocumentType::tryFrom($code);
        if ($resolved === null) {
            $result->error('type', 'BR-KSA-05', 'type must be invoice, credit_note or debit_note (388, 381, 383).');
        }

        return $resolved;
    }

    /** @param array<string, mixed> $flags */
    private function subtype(Workflow $workflow, array $flags, ValidationResult $result): string
    {
        $bits = '';
        foreach (['third_party', 'nominal', 'export', 'summary', 'self_billed'] as $flag) {
            $bits .= ! empty($flags[$flag]) ? '1' : '0';
        }
        if ($workflow === Workflow::B2C && $bits[2] === '1') {
            $result->error('subtype.export', 'BR-KSA-07', 'Export invoices must be standard (B2B) invoices.');
        }
        if ($bits[2] === '1' && $bits[4] === '1') {
            $result->error('subtype', 'BR-KSA-07', 'An export invoice cannot be self-billed.');
        }

        return $workflow->subtypePrefix() . $bits;
    }

    /** @param array<string, mixed> $d */
    private function party(array $d, string $path, ValidationResult $result, bool $required): ?Party
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($required && $name === '') {
            $result->error("{$path}.name", $path === 'seller' ? 'BR-06' : 'BR-KSA-42', ucfirst($path) . ' name is required.');
        }

        $id = (array) ($d['identification'] ?? []);
        $scheme = $d['id_scheme'] ?? $id['scheme'] ?? null;
        $value = $d['id_value'] ?? $id['value'] ?? null;

        return new Party(
            $name,
            isset($d['vat_number']) && trim((string) $d['vat_number']) !== '' ? trim((string) $d['vat_number']) : null,
            $scheme !== null && trim((string) $scheme) !== '' ? strtoupper(trim((string) $scheme)) : null,
            $value !== null && trim((string) $value) !== '' ? trim((string) $value) : null,
            Address::fromArray((array) ($d['address'] ?? [])),
        );
    }

    /** @param array<string, mixed> $d */
    private function line(array $d, int $i, ValidationResult $result): ?InvoiceLine
    {
        $f = "lines.{$i}";
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            $result->error("{$f}.name", 'BR-25', 'Item name is required.');
        }

        $qty = $this->amount($d['quantity'] ?? null, "{$f}.quantity", $result, 'BR-22');
        $price = $this->amount($d['unit_price'] ?? null, "{$f}.unit_price", $result, 'BR-26');
        $discount = $this->amount($d['discount'] ?? '0', "{$f}.discount", $result) ?? Amount::zero();

        if ($qty !== null && ($qty->isNegative() || $qty->isZero())) {
            $result->error("{$f}.quantity", 'BR-22', 'Quantity must be greater than zero (use a credit note for returns).');
        }
        if ($price !== null && $price->isNegative()) {
            $result->error("{$f}.unit_price", 'BR-27', 'Unit price must not be negative.');
        }
        if ($discount->isNegative() || ($price !== null && $discount->compare($price) > 0)) {
            $result->error("{$f}.discount", 'BR-27', 'Discount must be between 0 and the unit price.');
        }

        $vat = $this->vat($d, $f, $result);

        if ($qty === null || $price === null || $vat === null || $name === '') {
            return null;
        }

        return new InvoiceLine((string) ($i + 1), $name, $qty, strtoupper(trim((string) ($d['unit_code'] ?? 'PCE'))) ?: 'PCE', $price, $discount, $vat);
    }

    /** @param array<string, mixed> $d */
    private function allowanceCharge(array $d, string $f, bool $isCharge, ValidationResult $result): ?AllowanceCharge
    {
        $amount = $this->amount($d['amount'] ?? null, "{$f}.amount", $result, $isCharge ? 'BR-36' : 'BR-31');
        $reason = trim((string) ($d['reason'] ?? ''));
        $code = isset($d['reason_code']) && trim((string) $d['reason_code']) !== '' ? trim((string) $d['reason_code']) : null;
        if ($reason === '' && $code === null) {
            $result->error("{$f}.reason", $isCharge ? 'BR-39' : 'BR-33', 'A reason or reason code is required.');
        }
        if ($amount !== null && $amount->isNegative()) {
            $result->error("{$f}.amount", $isCharge ? 'BR-36' : 'BR-31', 'Amount must not be negative.');
        }
        $vat = $this->vat($d, $f, $result);

        return $amount !== null && $vat !== null ? new AllowanceCharge($isCharge, $amount, $reason !== '' ? $reason : (string) $code, $code, $vat) : null;
    }

    /**
     * VAT category + rate, with the inference rule from ARCHITECTURE 9.1:
     * missing category is inferred as S only for a 15% rate (warning); 0% stays ambiguous (error).
     *
     * @param array<string, mixed> $d
     */
    private function vat(array $d, string $f, ValidationResult $result): ?VatCategory
    {
        $rawRate = $d['vat_rate'] ?? null;
        $code = strtoupper(trim((string) ($d['vat_category'] ?? '')));

        if ($rawRate === null || ! Amount::isNumeric($rawRate)) {
            if ($code === VatCategory::ZERO || $code === VatCategory::EXEMPT || $code === VatCategory::OUT_OF_SCOPE) {
                $rawRate = '0';
            } else {
                $result->error("{$f}.vat_rate", 'BR-S-05', 'vat_rate (percent, e.g. 15) is required.');

                return null;
            }
        }

        $rate = Amount::fromInput($rawRate);
        if ($rate->isNegative() || $rate->compare(Amount::of('100')) > 0) {
            $result->error("{$f}.vat_rate", 'BR-S-05', 'vat_rate must be a percentage between 0 and 100.');

            return null;
        }
        if (! $rate->isZero() && $rate->compare(Amount::of('1')) < 0) {
            $result->error("{$f}.vat_rate", 'BR-S-05', 'vat_rate is a percentage: use 15 for 15%, not 0.15.');

            return null;
        }

        if ($code === '') {
            if ($this->inferStandardCategory && $rate->equals(Amount::of('15'))) {
                $result->warning("{$f}.vat_category", 'INFERRED', 'vat_category was missing and was set to S (standard rate) from the 15% rate. Send it explicitly.');
                $code = VatCategory::STANDARD;
            } else {
                $result->error("{$f}.vat_category", 'BR-CL-18', 'vat_category is required (S, Z, E or O). A 0% rate can be zero-rated, exempt or out of scope.');

                return null;
            }
        }

        if (! in_array($code, [VatCategory::STANDARD, VatCategory::ZERO, VatCategory::EXEMPT, VatCategory::OUT_OF_SCOPE], true)) {
            $result->error("{$f}.vat_category", 'BR-CL-18', 'vat_category must be S, Z, E or O.');

            return null;
        }

        if ($code === VatCategory::STANDARD && $rate->isZero()) {
            $result->error("{$f}.vat_rate", 'BR-S-05', 'Standard-rated (S) items must have a rate greater than 0.');

            return null;
        }
        if ($code !== VatCategory::STANDARD && ! $rate->isZero()) {
            $result->error("{$f}.vat_rate", "BR-{$code}-05", "Category {$code} must have a 0% rate.");

            return null;
        }

        $reasonCode = isset($d['exemption_reason_code']) && trim((string) $d['exemption_reason_code']) !== '' ? strtoupper(trim((string) $d['exemption_reason_code'])) : null;
        $reason = isset($d['exemption_reason']) && trim((string) $d['exemption_reason']) !== '' ? trim((string) $d['exemption_reason']) : null;

        if ($code === VatCategory::STANDARD) {
            if ($reasonCode !== null || $reason !== null) {
                $result->error("{$f}.exemption_reason_code", 'BR-S-10', 'Standard-rated items must not have an exemption reason.');
            }

            return new VatCategory($code, $rate);
        }

        if ($reasonCode === null) {
            $result->error("{$f}.exemption_reason_code", 'BR-KSA-CL-04', "Category {$code} requires an exemption reason code (" . implode(', ', VatCategory::REASON_CODES[$code]) . ').');

            return null;
        }
        if (! in_array($reasonCode, VatCategory::REASON_CODES[$code], true)) {
            $result->error("{$f}.exemption_reason_code", 'BR-KSA-CL-04', "Reason code {$reasonCode} is not valid for category {$code}.");

            return null;
        }
        if ($reason === null) {
            $result->error("{$f}.exemption_reason", 'BR-KSA-69', "Category {$code} requires an exemption reason text.");

            return null;
        }

        return new VatCategory($code, $rate, $reasonCode, $reason);
    }

    private function amount(mixed $value, string $field, ValidationResult $result, string $rule = 'FORMAT'): ?Amount
    {
        if ($value === null || $value === '') {
            $result->error($field, $rule, 'A value is required.');

            return null;
        }
        if (! Amount::isNumeric($value)) {
            $result->error($field, 'FORMAT', 'Must be a decimal number (e.g. "12.50").');

            return null;
        }

        $amount = Amount::fromInput($value);
        if ($amount->scale() > 6) {
            $result->error($field, 'FORMAT', 'At most 6 decimal places are allowed.');

            return null;
        }

        return $amount;
    }

    private function dateTime(mixed $value, string $field, ValidationResult $result, bool $required): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            if ($required) {
                $result->error($field, 'FORMAT', 'A date is required.');
            }

            return null;
        }

        try {
            $tz = new \DateTimeZone($this->timezone);
            $date = new \DateTimeImmutable((string) $value, $tz);

            return $date->setTimezone($tz);
        } catch (\Exception) {
            $result->error($field, 'FORMAT', 'Must be an ISO 8601 date or date-time.');

            return null;
        }
    }
}
