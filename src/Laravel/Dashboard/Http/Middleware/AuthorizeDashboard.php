<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Dashboard\Http\Middleware;

use CodingTriplets\Zatca\Laravel\Dashboard\PermissionResolver;
use Closure;
use Illuminate\Http\Request;

/** `zatca.can:{ability}` — 403 unless the resolver allows the ability. */
final class AuthorizeDashboard
{
    public function __construct(private readonly PermissionResolver $permissions)
    {
    }

    public function handle(Request $request, Closure $next, string $ability): mixed
    {
        $guard = config('zatca.ui.guard');
        $user = $request->user($guard ?: null);

        abort_unless($this->permissions->allows($user, $ability), 403);

        return $next($request);
    }
}
