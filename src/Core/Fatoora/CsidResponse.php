<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Fatoora;

use CodingTriplets\Zatca\Core\Domain\Exceptions\FatooraApiException;

/** Result of a CSID request (compliance, production or renewal). The secret is redacted from dumps. */
final class CsidResponse
{
    public function __construct(
        public readonly string $requestId,
        public readonly string $binarySecurityToken,
        #[\SensitiveParameter] private string $secret,
        public readonly ?string $dispositionMessage,
        public readonly int $httpStatus,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromBody(array $body, int $status): self
    {
        foreach (['requestID', 'binarySecurityToken', 'secret'] as $field) {
            if (! isset($body[$field]) || $body[$field] === '') {
                throw FatooraApiException::unexpected($status, "CSID response is missing [{$field}].");
            }
        }

        return new self(
            (string) $body['requestID'],
            (string) $body['binarySecurityToken'],
            (string) $body['secret'],
            isset($body['dispositionMessage']) ? (string) $body['dispositionMessage'] : null,
            $status,
        );
    }

    public function secret(): string
    {
        return $this->secret;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'requestId' => $this->requestId,
            'dispositionMessage' => $this->dispositionMessage,
            'httpStatus' => $this->httpStatus,
            'binarySecurityToken' => '[' . strlen($this->binarySecurityToken) . ' chars]',
            'secret' => '[redacted]',
        ];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('CSID responses must never be serialized.');
    }
}
