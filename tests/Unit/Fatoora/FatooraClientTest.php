<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Unit\Fatoora;

use CodingTriplets\Zatca\Core\Contracts\Fatoora\CsidCredentials;
use CodingTriplets\Zatca\Core\Domain\Exceptions\FatooraApiException;
use CodingTriplets\Zatca\Core\Fatoora\BasicAuthentication;
use CodingTriplets\Zatca\Core\Fatoora\FatooraClient;
use CodingTriplets\Zatca\Tests\Support\FakeZatca;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class FatooraClientTest extends TestCase
{
    private const BASE = 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal';

    public function test_compliance_csid_request_shape(): void
    {
        $zatca = (new FakeZatca())->csidIssued('555');
        $response = self::client($zatca)->requestComplianceCsid("-----BEGIN CERTIFICATE REQUEST-----\nabc\n", '123345');

        $request = $zatca->request(0);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::BASE . '/compliance', (string) $request->getUri());
        self::assertSame('123345', $request->getHeaderLine('OTP'));
        self::assertSame('V2', $request->getHeaderLine('Accept-Version'));
        self::assertFalse($request->hasHeader('Authorization'));
        self::assertSame(
            ['csr' => base64_encode("-----BEGIN CERTIFICATE REQUEST-----\nabc\n")],
            json_decode((string) $request->getBody(), true),
        );
        self::assertSame('555', $response->requestId);
        self::assertSame('fake-secret-123', $response->secret());
    }

    public function test_production_csid_uses_basic_auth_with_compliance_csid(): void
    {
        $zatca = (new FakeZatca())->csidIssued();
        self::client($zatca)->requestProductionCsid('1234567890123', new CsidCredentials(1, 'TOKEN', 'SECRET'));

        $request = $zatca->request(0);
        self::assertSame(self::BASE . '/production/csids', (string) $request->getUri());
        self::assertSame('Basic ' . base64_encode('TOKEN:SECRET'), $request->getHeaderLine('Authorization'));
        self::assertSame(['compliance_request_id' => '1234567890123'], json_decode((string) $request->getBody(), true));
        self::assertFalse($request->hasHeader('OTP'));
    }

    public function test_renewal_is_patch_with_otp_and_production_auth(): void
    {
        $zatca = (new FakeZatca())->csidIssued();
        self::client($zatca)->renewProductionCsid('CSR', '654321', new CsidCredentials(1, 'PTOKEN', 'PSECRET'));

        $request = $zatca->request(0);
        self::assertSame('PATCH', $request->getMethod());
        self::assertSame('654321', $request->getHeaderLine('OTP'));
        self::assertSame('Basic ' . base64_encode('PTOKEN:PSECRET'), $request->getHeaderLine('Authorization'));
    }

    public function test_client_errors_are_not_retryable_and_carry_zatca_codes(): void
    {
        $zatca = (new FakeZatca())->error(400, ['errors' => [['code' => 'Invalid-OTP', 'message' => 'The provided OTP is invalid']]]);

        try {
            self::client($zatca)->requestComplianceCsid('CSR', '111111');
            self::fail('Expected exception');
        } catch (FatooraApiException $e) {
            self::assertFalse($e->retryable);
            self::assertSame(400, $e->httpStatus);
            self::assertSame('Invalid-OTP', $e->errors[0]['code']);
            self::assertStringNotContainsString('111111', $e->getMessage());
        }
    }

    public function test_server_errors_are_retryable(): void
    {
        $zatca = (new FakeZatca())->error(503, ['message' => 'Service unavailable']);

        try {
            self::client($zatca)->requestComplianceCsid('CSR', '123456');
            self::fail('Expected exception');
        } catch (FatooraApiException $e) {
            self::assertTrue($e->retryable);
        }
    }

    public function test_transport_errors_are_retryable_and_redacted(): void
    {
        $zatca = new FakeZatca();
        $zatca->mock->append(new ConnectException('Connection refused; Authorization: Basic c2VjcmV0', new Request('POST', self::BASE)));

        try {
            self::client($zatca)->requestComplianceCsid('CSR', '123456');
            self::fail('Expected exception');
        } catch (FatooraApiException $e) {
            self::assertTrue($e->retryable);
            self::assertStringNotContainsString('c2VjcmV0', $e->getMessage());
        }
    }

    public function test_malformed_otp_is_rejected_before_any_http_call(): void
    {
        $zatca = new FakeZatca();

        try {
            self::client($zatca)->requestComplianceCsid('CSR', '12ab');
            self::fail('Expected exception');
        } catch (\InvalidArgumentException) {
            self::assertCount(0, $zatca->history);
        }
    }

    private static function client(FakeZatca $zatca): FatooraClient
    {
        $factory = new HttpFactory();

        return new FatooraClient(new Client(['handler' => $zatca->stack, 'http_errors' => false]), $factory, $factory, new BasicAuthentication(), self::BASE);
    }
}
