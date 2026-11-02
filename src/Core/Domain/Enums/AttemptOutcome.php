<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Enums;

/** Interpretation of one HTTP exchange with Fatoora. */
enum AttemptOutcome: string
{
    case Accepted = 'accepted';
    case AcceptedWithWarnings = 'accepted_with_warnings';
    case Rejected = 'rejected';
    case Transient = 'transient';
    case Auth = 'auth';
    case Unknown = 'unknown';
    case ClientError = 'client_error';
}
