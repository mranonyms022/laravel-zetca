<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Invoices;

use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;

/** Queue names from `zatca.queues.names` (B2B and B2C never share a queue). */
final class QueueNames
{
    public static function generation(Workflow $workflow): string
    {
        return self::name($workflow === Workflow::B2B ? 'b2b_generation' : 'b2c_generation');
    }

    public static function submission(Workflow $workflow): string
    {
        return self::name($workflow === Workflow::B2B ? 'b2b_clearance' : 'b2c_reporting');
    }

    public static function retry(Workflow $workflow): string
    {
        return self::name($workflow === Workflow::B2B ? 'b2b_retry' : 'b2c_retry');
    }

    private static function name(string $key): string
    {
        return (string) config("zatca.queues.names.{$key}", 'zatca-' . str_replace('_', '-', $key));
    }
}
