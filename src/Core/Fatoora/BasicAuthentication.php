<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Fatoora;

use CodingTriplets\Zatca\Core\Contracts\Fatoora\AuthenticationStrategy;
use CodingTriplets\Zatca\Core\Contracts\Fatoora\CsidCredentials;
use Psr\Http\Message\RequestInterface;

/** Fatoora authentication: HTTP Basic with username = binarySecurityToken, password = secret. */
final class BasicAuthentication implements AuthenticationStrategy
{
    public function authenticate(RequestInterface $request, CsidCredentials $credentials): RequestInterface
    {
        return $request->withHeader(
            'Authorization',
            'Basic ' . base64_encode($credentials->binarySecurityToken . ':' . $credentials->secret()),
        );
    }
}
