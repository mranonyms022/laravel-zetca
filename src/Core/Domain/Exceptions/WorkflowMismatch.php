<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Exceptions;

use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;

/** A B2B document reached a B2C service or vice versa (rule R4). */
final class WorkflowMismatch extends ZatcaException
{
    public static function expected(Workflow $expected, Workflow $actual, string $service): self
    {
        return self::withContext(
            "{$service} only handles {$expected->value} documents, got {$actual->value}.",
            ['expected' => $expected->value, 'actual' => $actual->value, 'service' => $service],
        );
    }
}
