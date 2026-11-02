<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Exceptions;

/** Base type for every exception thrown by the package. */
class ZatcaException extends \RuntimeException
{
    /** @var array<string, mixed> */
    protected array $context = [];

    /** @param array<string, mixed> $context sanitized, safe-to-log context */
    public static function withContext(string $message, array $context = [], ?\Throwable $previous = null): static
    {
        $e = new static($message, 0, $previous);
        $e->context = $context;

        return $e;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }
}
