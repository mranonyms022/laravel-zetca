<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Domain\Exceptions;

/** Raised for vault/key/certificate problems. Messages never contain secret material. */
final class CredentialException extends ZatcaException
{
}
