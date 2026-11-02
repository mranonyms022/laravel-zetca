<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Enums;

/**
 * Other buyer ID (BT-46) schemes allowed by BR-KSA-14, in the official precedence order.
 * When several IDs are known, the first available scheme in this order is written.
 */
enum BuyerIdScheme: string
{
    case Tin = 'TIN';            // Tax Identification Number
    case Crn = 'CRN';            // Commercial registration number
    case Mom = 'MOM';            // MOMRAH license
    case Mls = 'MLS';            // MHRSD license
    case SevenHundred = '700';   // 700 Number
    case Sag = 'SAG';            // MISA license
    case Nat = 'NAT';            // National ID
    case Gcc = 'GCC';            // GCC ID
    case Iqa = 'IQA';            // Iqama number
    case Pas = 'PAS';            // Passport ID
    case Oth = 'OTH';            // Other ID

    /** @return list<self> precedence order as listed in BR-KSA-14 */
    public static function precedence(): array
    {
        return [
            self::Tin, self::Crn, self::Mom, self::Mls, self::SevenHundred, self::Sag,
            self::Nat, self::Gcc, self::Iqa, self::Pas, self::Oth,
        ];
    }

    public function rank(): int
    {
        return (int) array_search($this, self::precedence(), true);
    }
}
