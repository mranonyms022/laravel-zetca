<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Tenancy;

use CodingTriplets\Zatca\Core\Contracts\Tenancy\TenantResolver;

final class NullTenantResolver implements TenantResolver
{
    public function current(): ?string
    {
        return null;
    }
}
