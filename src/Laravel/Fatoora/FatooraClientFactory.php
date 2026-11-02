<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Fatoora;

use CodingTriplets\Zatca\Core\Contracts\Fatoora\AuthenticationStrategy;
use CodingTriplets\Zatca\Core\Domain\Enums\Environment;
use CodingTriplets\Zatca\Core\Fatoora\FatooraClient;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;

/** Builds a Fatoora client per environment from config (TLS verification always on in production). */
final class FatooraClientFactory
{
    /** @var array<string, FatooraClient> */
    private array $clients = [];

    public function __construct(
        private readonly AuthenticationStrategy $auth,
        private readonly ?HandlerStack $handler = null,
    ) {
    }

    public function for(Environment $environment): FatooraClient
    {
        return $this->clients[$environment->value] ??= $this->make($environment);
    }

    private function make(Environment $environment): FatooraClient
    {
        $verify = (bool) config('zatca.security.verify_tls', true) || $environment->isProduction();
        $bundle = config('zatca.security.ca_bundle');

        $options = [
            'http_errors' => false,
            'timeout' => (float) config('zatca.http.timeout', 30),
            'connect_timeout' => (float) config('zatca.http.connect_timeout', 10),
            'verify' => $verify && is_string($bundle) && $bundle !== '' ? $bundle : $verify,
            'allow_redirects' => false,
        ];
        if ($this->handler !== null) {
            $options['handler'] = $this->handler;
        }

        $factory = new HttpFactory();

        return new FatooraClient(
            new Client($options),
            $factory,
            $factory,
            $this->auth,
            (string) config("zatca.endpoints.{$environment->value}"),
        );
    }
}
