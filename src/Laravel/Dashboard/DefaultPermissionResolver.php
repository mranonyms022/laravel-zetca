<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Dashboard;

use CodingTriplets\Zatca\Laravel\ZatcaManager;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Resolution order (ARCHITECTURE §17):
 *  1. a Zatca::auth(fn ($user, string $ability, $subject) => bool) callback, if registered;
 *  2. otherwise $user->can($ability) — Laravel Gates or Spatie Laravel Permission;
 *  3. otherwise deny — except in the `local` environment, where a logged-in user may do everything.
 * A guest is always denied.
 */
final class DefaultPermissionResolver implements PermissionResolver
{
    public function __construct(private readonly ZatcaManager $manager)
    {
    }

    public function allows(?Authenticatable $user, string $ability, mixed $subject = null): bool
    {
        if ($user === null) {
            return false;
        }

        $callback = $this->manager->authCallback();
        if ($callback !== null) {
            return (bool) $callback($user, $ability, $subject);
        }

        if ($user instanceof Authorizable && $user->can($ability, $subject === null ? [] : [$subject])) {
            return true;
        }

        return app()->environment('local');
    }
}
