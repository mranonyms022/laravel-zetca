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

    public function request(int $index): RequestInterface
    {
        return $this->history[$index]['request'];
    }
}
