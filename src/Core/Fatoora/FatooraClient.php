<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Fatoora;

use CodingTriplets\Zatca\Core\Contracts\Fatoora\AuthenticationStrategy;
use CodingTriplets\Zatca\Core\Contracts\Fatoora\CsidCredentials;
use CodingTriplets\Zatca\Core\Domain\Exceptions\FatooraApiException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Fatoora (ZATCA e-invoicing gateway) device-credential endpoints.
 *
 *  POST  /compliance           OTP header, {csr}                    -> compliance CSID
 *  POST  /production/csids     Basic(compliance CSID), {compliance_request_id} -> production CSID
 *  PATCH /production/csids     Basic(production CSID), OTP header, {csr}       -> renewed production CSID ⚠
 *
 * The OTP is passed through to ZATCA and never stored or logged.
 * Invoice endpoints (compliance checks, clearance, reporting) are added in M4/M6.
 */
final class FatooraClient
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly AuthenticationStrategy $auth,
        private readonly string $baseUrl,
        private readonly string $apiVersion = 'V2',
        private readonly string $language = 'en',
    ) {
    }

    public function requestComplianceCsid(string $csrPem, #[\SensitiveParameter] string $otp): CsidResponse
    {
        $request = $this->json('POST', '/compliance', ['csr' => base64_encode($csrPem)])
            ->withHeader('OTP', self::otp($otp));

        return $this->csid($request);
    }

    public function requestProductionCsid(string $complianceRequestId, CsidCredentials $compliance): CsidResponse
    {
        $request = $this->json('POST', '/production/csids', ['compliance_request_id' => $complianceRequestId]);

        return $this->csid($this->auth->authenticate($request, $compliance));
    }

    public function renewProductionCsid(string $csrPem, #[\SensitiveParameter] string $otp, CsidCredentials $production): CsidResponse
    {
        $request = $this->json('PATCH', '/production/csids', ['csr' => base64_encode($csrPem)])
            ->withHeader('OTP', self::otp($otp));

        return $this->csid($this->auth->authenticate($request, $production));
    }

    private function csid(RequestInterface $request): CsidResponse
    {
        $response = $this->send($request);
        $status = $response->getStatusCode();
        $body = self::decode($response);

        if ($status !== 200) {
            throw FatooraApiException::rejected($status, self::errors($body));
        }

        return CsidResponse::fromBody($body, $status);
    }

    /** @param array<string, mixed> $payload */
    private function json(string $method, string $path, array $payload): RequestInterface
    {
        return $this->requests->createRequest($method, rtrim($this->baseUrl, '/') . $path)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept-Version', $this->apiVersion)
            ->withHeader('Accept-Language', $this->language)
            ->withBody($this->streams->createStream(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
    }

    private function send(RequestInterface $request): ResponseInterface
    {
        try {
            return $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            // Keep only the first line, without anything that looks like an Authorization or OTP header.
            $reason = strtok($e->getMessage(), "\n") ?: get_class($e);
            $reason = (string) preg_replace('/(authorization|otp)\s*[:=].*$/i', '$1: [redacted]', $reason);

            throw FatooraApiException::transport(mb_substr($reason, 0, 300));
        }
    }

    /** @return array<string, mixed> */
    private static function decode(ResponseInterface $response): array
    {
        $raw = (string) $response->getBody();
        if ($raw === '') {
            return [];
        }

        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['_non_json' => mb_substr(strip_tags($raw), 0, 300)];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Normalises the error shapes ZATCA returns: {"errors": [...]} with strings or {code, message}
     * objects, {"code", "message"}, or validationResults.errorMessages.
     *
     * @param array<string, mixed> $body
     * @return list<array{code: string, message: string}>
     */
    private static function errors(array $body): array
    {
        $items = [];

        foreach ((array) ($body['errors'] ?? []) as $error) {
            $items[] = is_array($error) ? $error : ['message' => (string) $error];
        }
        foreach ((array) ($body['validationResults']['errorMessages'] ?? []) as $error) {
            $items[] = (array) $error;
        }
        if ($items === [] && (isset($body['code']) || isset($body['message']))) {
            $items[] = $body;
        }
        if ($items === [] && isset($body['_non_json'])) {
            $items[] = ['message' => $body['_non_json']];
        }

        return array_map(static fn (array $e) => [
            'code' => (string) ($e['code'] ?? $e['errorCode'] ?? ''),
            'message' => mb_substr((string) ($e['message'] ?? $e['errorMessage'] ?? ''), 0, 500),
        ], $items);
    }

    private static function otp(#[\SensitiveParameter] string $otp): string
    {
        $otp = trim($otp);
        if (preg_match('/^\d{6}$/', $otp) !== 1) {
            throw new \InvalidArgumentException('The OTP must be 6 digits.');
        }

        return $otp;
    }
}
