<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Exceptions;

/** A value object received input that violates a ZATCA format rule. */
final class InvalidValue extends ZatcaException
{
    public static function for(string $field, string $reason, ?string $rule = null): self
    {
        $message = $rule !== null ? "[{$rule}] {$field}: {$reason}" : "{$field}: {$reason}";

        return self::withContext($message, ['field' => $field, 'rule' => $rule]);
    }
}
