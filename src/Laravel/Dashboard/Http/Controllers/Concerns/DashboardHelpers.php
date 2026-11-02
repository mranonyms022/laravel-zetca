<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers\Concerns;

use CodingTriplets\Zatca\Laravel\Dashboard\PermissionResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

trait DashboardHelpers
{
    protected function user(Request $request): ?Authenticatable
    {
        $guard = config('zatca.ui.guard');

        return $request->user($guard ?: null);
    }

    /** Numeric user ID recorded as the actor (null for UUID/string IDs). */
    protected function actorId(Request $request): ?int
    {
        $id = $this->user($request)?->getAuthIdentifier();

        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;
    }

    protected function authorizeAbility(Request $request, string $ability, mixed $subject = null): void
    {
        abort_unless(app(PermissionResolver::class)->allows($this->user($request), $ability, $subject), 403);
    }

    protected function zroute(string $name, mixed $parameters = []): string
    {
        return route((string) config('zatca.ui.route_name_prefix', 'zatca.') . $name, $parameters);
    }
}
