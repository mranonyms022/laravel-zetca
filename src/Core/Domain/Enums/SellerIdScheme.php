<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Enums;

/** Other seller ID (BT-29) schemes allowed by BR-KSA-08, in the official order. */
enum SellerIdScheme: string
{
    case Crn = 'CRN';
    case Mom = 'MOM';
    case Mls = 'MLS';
    case SevenHundred = '700';
    case Sag = 'SAG';
    case Oth = 'OTH';
}
