<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Exceptions;

use CodingTriplets\Zatca\Core\Domain\Enums\ProcessingStatus;
use CodingTriplets\Zatca\Core\Domain\Enums\Workflow;

final class IllegalTransition extends ZatcaException
{
    public static function between(Workflow $workflow, ProcessingStatus $from, ProcessingStatus $to): self
    {
        return self::withContext(
            "Illegal {$workflow->value} transition {$from->value} -> {$to->value}.",
            ['workflow' => $workflow->value, 'from' => $from->value, 'to' => $to->value],
        );
    }
}
