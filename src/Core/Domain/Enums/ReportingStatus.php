<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Enums;

/** B2C outcome dimension (NOT_APPLICABLE for B2B unless clearance is deactivated). */
enum ReportingStatus: string
{
    case NotApplicable = 'NOT_APPLICABLE';
    case Pending = 'PENDING';
    case Reported = 'REPORTED';
    case ReportedWithWarnings = 'REPORTED_WITH_WARNINGS';
    case NotReported = 'NOT_REPORTED';
}
