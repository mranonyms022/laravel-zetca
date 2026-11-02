<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Enums;

/** Transport dimension only: says nothing about whether ZATCA accepted the document. */
enum SubmissionStatus: string
{
    case NotStarted = 'NOT_STARTED';
    case Pending = 'PENDING';
    case InFlight = 'IN_FLIGHT';
    case Delivered = 'DELIVERED';
    case Unknown = 'UNKNOWN';
    case FailedTransient = 'FAILED_TRANSIENT';
    case FailedPermanent = 'FAILED_PERMANENT';
}
