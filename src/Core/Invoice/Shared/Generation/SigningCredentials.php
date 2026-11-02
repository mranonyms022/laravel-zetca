<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\Generation;

use CodingTriplets\Zatca\Core\Crypto\EcPrivateKey;
use CodingTriplets\Zatca\Core\Invoice\Shared\Signing\CertificateInfo;

/** Device certificate and private key used to sign. Short-lived: build it per signing operation. */
final class SigningCredentials
{
    public function __construct(
        public readonly CertificateInfo $certificate,
        public readonly EcPrivateKey $privateKey,
    ) {
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['certificate' => $this->certificate->issuerName . ' #' . $this->certificate->serialNumber, 'privateKey' => '[redacted]'];
    }
}
