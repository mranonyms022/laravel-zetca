<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Http\Middleware;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP Basic authentication for the package API (`zatca.api.auth` = "basic").
 *
 * Credentials come from `zatca.api.basic.username` / `password` (ZATCA_API_USERNAME / ZATCA_API_PASSWORD).
 * Fails closed: when no credentials are configured every request gets 401. Set `zatca.api.auth` to "none" only
 * when `zatca.api.middleware` already authenticates the caller (Sanctum, Passport, ...).
 */
final class ApiBasicAuth
{
    public function handle(Request $request, \Closure $next): mixed
    {
        if (strtolower((string) config('zatca.api.auth', 'basic')) === 'none') {
            return $next($request);
        }

        $username = (string) config('zatca.api.basic.username', '');
        $password = (string) config('zatca.api.basic.password', '');
        if ($username === '' || $password === '') {
            return $this->refuse('API credentials are not configured (ZATCA_API_USERNAME / ZATCA_API_PASSWORD).');
        }

        [$givenUser, $givenPassword] = $this->credentials($request);

        // Compare both values every time (no early exit) so timing does not reveal which one was wrong.
        $userOk = hash_equals(hash('sha256', $username), hash('sha256', $givenUser));
        $passwordOk = hash_equals(hash('sha256', $password), hash('sha256', $givenPassword));
        if (! ($userOk && $passwordOk)) {
            return $this->refuse('Invalid API credentials.');
        }

        return $next($request);
    }

    /** @return array{0: string, 1: string} */
    private function credentials(Request $request): array
    {
        // PHP fills these from the Authorization header on most SAPIs; parse the header ourselves when it does not
        // (FastCGI without HTTP_AUTHORIZATION passthrough, test requests, ...).
        $user = $request->getUser();
        $password = $request->getPassword();
        if ($user !== null && $user !== '') {
            return [$user, (string) $password];
        }

        $header = (string) $request->headers->get('Authorization', '');
        if (stripos($header, 'basic ') !== 0) {
            return ['', ''];
        }
        $decoded = base64_decode(trim(substr($header, 6)), true);
        if ($decoded === false || ! str_contains($decoded, ':')) {
            return ['', ''];
        }

        return explode(':', $decoded, 2) + ['', ''];
    }

    private function refuse(string $message): JsonResponse
    {
        return new JsonResponse(['status' => false, 'message' => $message], 401, [
            'WWW-Authenticate' => 'Basic realm="ZATCA API", charset="UTF-8"',
        ]);
    }
}
