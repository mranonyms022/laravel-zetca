<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Support;

use CodingTriplets\Zatca\Core\Crypto\Der;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Mocks only the HTTP transport to ZATCA (everything else runs for real).
 * Records every request so tests can assert headers and bodies.
 */
final class FakeZatca
{
    public MockHandler $mock;

    public HandlerStack $stack;

    /** @var list<array{request: RequestInterface}> */
    public array $history = [];

    public function __construct()
    {
        $this->mock = new MockHandler();
        $this->stack = HandlerStack::create($this->mock);
        $this->stack->push(Middleware::history($this->history));
    }

    public static function binarySecurityToken(): string
    {
        $der = Der::pemToDer((string) file_get_contents(__DIR__ . '/../Fixtures/csr/test-certificate.pem'));

        return base64_encode(base64_encode($der));
    }

    public function csidIssued(string $requestId = '1234567890123', string $secret = 'fake-secret-123'): self
    {
        $this->mock->append(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'requestID' => $requestId,
            'dispositionMessage' => 'ISSUED',
            'binarySecurityToken' => self::binarySecurityToken(),
            'secret' => $secret,
            'errors' => null,
        ])));

        return $this;
    }

    /** @param array<string, mixed> $body */
    public function error(int $status, array $body): self
    {
        $this->mock->append(new Response($status, ['Content-Type' => 'application/json'], json_encode($body)));

        return $this;
    }

    /**
     * Compliance + production CSID responses whose certificate is issued (by a throw-away test CA) for the public
     * key in the submitted CSR, so the device can really sign. The production response reuses the CSR of the
     * preceding compliance request (as ZATCA does).
     */
    public function csidForSubmittedCsr(): self
    {
        $csr = null;
        $issue = static function (string $csrPem, int $serial): string {
            static $ca = null;
            if ($ca === null) {
                $caKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
                $caCsr = openssl_csr_new(['commonName' => 'laravel-zatca test CA'], $caKey, ['digest_alg' => 'sha256']);
                $ca = [openssl_csr_sign($caCsr, null, $caKey, 30, ['digest_alg' => 'sha256'], 1), $caKey];
            }
            openssl_x509_export(openssl_csr_sign($csrPem, $ca[0], $ca[1], 30, ['digest_alg' => 'sha256'], $serial), $pem);

            return base64_encode(base64_encode(Der::pemToDer($pem)));
        };
        $respond = static fn (string $id, string $token, string $secret) => new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'requestID' => $id, 'dispositionMessage' => 'ISSUED', 'binarySecurityToken' => $token, 'secret' => $secret, 'errors' => null,
        ]));

        $this->mock->append(
            static function (RequestInterface $request) use (&$csr, $issue, $respond) {
                $csr = base64_decode((string) json_decode((string) $request->getBody(), true)['csr']);

                return $respond('1234567890123', $issue($csr, random_int(1000, 999999)), 'compliance-secret');
            },
            static function () use (&$csr, $issue, $respond) {
                return $respond('30368', $issue((string) $csr, random_int(1000, 999999)), 'production-secret');
            },
        );

        return $this;
    }

    /** @param list<array{code: string, message: string}> $warnings */
    public function reported(array $warnings = []): self
    {
        return $this->submissionResult($warnings === [] ? 200 : 202, 'REPORTED', null, [], $warnings);
    }

    public function cleared(string $clearedXml = '<Invoice>cleared</Invoice>'): self
    {
        return $this->submissionResult(200, null, 'CLEARED', [], [], base64_encode($clearedXml));
    }

    /** @param list<array{code: string, message: string}> $errors */
    public function notReported(array $errors = [['code' => 'BR-KSA-37', 'message' => 'Invalid seller VAT']]): self
    {
        return $this->submissionResult(400, 'NOT_REPORTED', null, $errors);
    }

    /** @param list<array{code: string, message: string}> $errors */
    public function notCleared(array $errors = [['code' => 'BR-KSA-08', 'message' => 'Invalid buyer identification']]): self
    {
        return $this->submissionResult(400, null, 'NOT_CLEARED', $errors);
    }

    private function submissionResult(int $status, ?string $reporting, ?string $clearance, array $errors, array $warnings = [], ?string $cleared = null): self
    {
        $map = static fn (array $items, string $type) => array_map(static fn ($i) => ['type' => $type, 'code' => $i['code'], 'category' => 'BR', 'message' => $i['message'], 'status' => $type], $items);
        $body = array_filter([
            'validationResults' => [
                'infoMessages' => [['type' => 'INFO', 'code' => 'XSD_ZATCA_VALID', 'category' => 'XSD validation', 'message' => 'Complied with UBL 2.1 standards', 'status' => 'PASS']],
                'warningMessages' => $map($warnings, 'WARNING'),
                'errorMessages' => $map($errors, 'ERROR'),
                'status' => $errors !== [] ? 'ERROR' : ($warnings !== [] ? 'WARNING' : 'PASS'),
            ],
            'reportingStatus' => $reporting,
            'clearanceStatus' => $clearance,
            'clearedInvoice' => $cleared,
        ], static fn ($v) => $v !== null);
        $this->mock->append(new Response($status, ['Content-Type' => 'application/json'], json_encode($body)));

        return $this;
    }

    public function request(int $index): RequestInterface
    {
        return $this->history[$index]['request'];
    }
}
