<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Contracts\Tenancy;

interface TenantResolver
{
    /** Current tenant identifier, or null when tenancy is disabled / no tenant is active. */
    public function current(): ?string;
}
