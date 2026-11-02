<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Enums;

/** UNTDID 1001 codes used by ZATCA (BT-3). */
enum DocumentType: string
{
    case Invoice = '388';
    case CreditNote = '381';
    case DebitNote = '383';

    public function requiresBillingReference(): bool
    {
        return $this !== self::Invoice;
    }

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Tax invoice',
            self::CreditNote => 'Credit note',
            self::DebitNote => 'Debit note',
        };
    }
}
