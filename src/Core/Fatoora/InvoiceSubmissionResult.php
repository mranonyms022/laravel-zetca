<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Fatoora;

/**
 * Parsed response of an invoice endpoint (compliance check, clearance, reporting).
 *
 * Acceptance is decided from the body (validationResults.status and reportingStatus / clearanceStatus),
 * never from the HTTP status alone.
 */
final class InvoiceSubmissionResult
{
    /**
     * @param list<array{code: string, category: string, message: string}> $errors
     * @param list<array{code: string, category: string, message: string}> $warnings
     * @param list<array{code: string, category: string, message: string}> $info
     * @param array<string, mixed>                                            $body sanitized response body
     */
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $validationStatus,
        public readonly ?string $reportingStatus,
        public readonly ?string $clearanceStatus,
        public readonly ?string $clearedInvoice,
        public readonly array $errors,
        public readonly array $warnings,
        public readonly array $info,
        public readonly array $body,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromResponse(int $httpStatus, array $body): self
    {
        $v = is_array($body['validationResults'] ?? null) ? $body['validationResults'] : [];
        $msgs = static fn (string $key): array => array_values(array_map(static fn ($m): array => [
            'code' => (string) (is_array($m) ? ($m['code'] ?? '') : ''),
            'category' => (string) (is_array($m) ? ($m['category'] ?? '') : ''),
            'message' => mb_substr((string) (is_array($m) ? ($m['message'] ?? '') : $m), 0, 1000),
        ], is_array($v[$key] ?? null) ? $v[$key] : []));

        $cleared = isset($body['clearedInvoice']) && is_string($body['clearedInvoice']) ? $body['clearedInvoice'] : null;
        $sanitized = $body;
        unset($sanitized['clearedInvoice']);

        return new self(
            $httpStatus,
            strtoupper((string) ($v['status'] ?? '')),
            isset($body['reportingStatus']) ? strtoupper((string) $body['reportingStatus']) : null,
            isset($body['clearanceStatus']) ? strtoupper((string) $body['clearanceStatus']) : null,
            $cleared,
            $msgs('errorMessages'),
            $msgs('warningMessages'),
            $msgs('infoMessages'),
            $sanitized,
        );
    }

    /** ZATCA accepted the document (possibly with warnings). */
    public function isAccepted(): bool
    {
        if (! in_array($this->httpStatus, [200, 202], true) || $this->errors !== []) {
            return false;
        }
        if (! in_array($this->validationStatus, ['PASS', 'WARNING'], true)) {
            return false;
        }
        foreach ([$this->reportingStatus, $this->clearanceStatus] as $status) {
            if ($status !== null && ! in_array($status, ['REPORTED', 'CLEARED'], true)) {
                return false;
            }
        }

        return true;
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }
}
