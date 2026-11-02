<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Enums;

/** B2B outcome dimension (always NOT_APPLICABLE for B2C). */
enum ClearanceStatus: string
{
    case NotApplicable = 'NOT_APPLICABLE';
    case Pending = 'PENDING';
    case Cleared = 'CLEARED';
    case ClearedWithWarnings = 'CLEARED_WITH_WARNINGS';
    case NotCleared = 'NOT_CLEARED';
    case ClearanceDeactivated = 'CLEARANCE_DEACTIVATED';
}
