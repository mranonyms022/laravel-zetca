<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models\Concerns;

use CodingTriplets\Zatca\Laravel\Tenancy\TenantScope;
use CodingTriplets\Zatca\Core\Contracts\Tenancy\TenantResolver;

/**
 * Column-mode tenancy: scopes queries to the resolved tenant and stamps tenant_id on create.
 * tenant_id '' means "no tenant" (see ZatcaMigration conventions).
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(static function ($model): void {
            if ((string) $model->getAttribute('tenant_id') !== '') {
                return;
            }

            $tenant = app(TenantResolver::class)->current();
            $model->setAttribute('tenant_id', $tenant ?? '');
        });
    }
}
