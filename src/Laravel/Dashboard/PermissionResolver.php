<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Dashboard;

use Illuminate\Contracts\Auth\Authenticatable;

/** Decides whether a user may use a dashboard ability. Bind your own in zatca.authorization.resolver. */
interface PermissionResolver
{
    public function allows(?Authenticatable $user, string $ability, mixed $subject = null): bool;
}
