<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Contracts\Fatoora;

use Psr\Http\Message\RequestInterface;

/**
 * Applies authentication to an outbound Fatoora request.
 * Default: HTTP Basic base64(binarySecurityToken):secret from the CSID (no OAuth/bearer flow exists in Fatoora).
 * Replaceable for customers routing through an external gateway.
 */
interface AuthenticationStrategy
{
    public function authenticate(RequestInterface $request, CsidCredentials $credentials): RequestInterface;
}
