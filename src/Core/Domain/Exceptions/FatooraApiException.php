<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Exceptions;

/**
 * A Fatoora API call failed. `retryable` is true only for transport errors and 5xx/429:
 * 4xx answers (bad OTP, invalid CSR, ...) need a human or a data fix, never a blind retry.
 * Messages contain ZATCA's error codes/messages only, never credentials or OTPs.
 */
final class FatooraApiException extends ZatcaException
{
    public ?int $httpStatus = null;

    public bool $retryable = false;

    /** @var list<array{code: string, message: string}> */
    public array $errors = [];

    /** @param list<array{code: string, message: string}> $errors */
    public static function rejected(int $status, array $errors): self
    {
        $summary = $errors === []
            ? "HTTP {$status}"
            : implode('; ', array_map(static fn (array $e) => trim($e['code'] . ': ' . $e['message'], ': '), $errors));

        $e = self::withContext("ZATCA rejected the request ({$summary}).", ['http_status' => $status, 'errors' => $errors]);
        $e->httpStatus = $status;
        $e->errors = $errors;
        $e->retryable = $status === 429 || $status >= 500;

        return $e;
    }

    public static function transport(string $reason, ?\Throwable $previous = null): self
    {
        $e = self::withContext("Could not reach ZATCA: {$reason}", [], $previous);
        $e->retryable = true;

        return $e;
    }

    public static function unexpected(int $status, string $reason): self
    {
        $e = self::withContext("Unexpected ZATCA response (HTTP {$status}): {$reason}", ['http_status' => $status]);
        $e->httpStatus = $status;

        return $e;
    }
}
