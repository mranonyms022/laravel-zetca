<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Exceptions;

/**
 * ICV/PIH chain problems. CounterConflict is retryable (the issuance transaction is re-run);
 * ChainIntegrityViolation is not and pauses the device.
 */
class SequenceException extends ZatcaException
{
    public static function counterConflict(int $deviceId, string $environment): self
    {
        return self::withContext('Invoice counter changed concurrently; issuance must be retried.', [
            'device_id' => $deviceId, 'environment' => $environment, 'retryable' => true,
        ]);
    }

    public static function chainIntegrityViolation(int $deviceId, string $environment, string $detail): self
    {
        return self::withContext("Hash chain integrity violation: {$detail}", [
            'device_id' => $deviceId, 'environment' => $environment, 'retryable' => false,
        ]);
    }

    public function isRetryable(): bool
    {
        return (bool) ($this->context['retryable'] ?? false);
    }
}
