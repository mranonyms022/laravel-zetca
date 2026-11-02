<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Enums;

/** Why a legal issuance (new UUID + ICV) was created for an internal invoice record. */
enum IssuanceReason: string
{
    case Initial = 'initial';
    case Correction = 'correction';
    case ReissueUnchanged = 'reissue_unchanged';
}
