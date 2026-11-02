<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Tenancy;

use CodingTriplets\Zatca\Core\Contracts\Tenancy\TenantResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Applied only in "column" tenancy mode when a tenant is active.
 * In "database" mode isolation comes from the tenant connection; in "none" mode nothing is filtered.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (config('zatca.tenancy.mode') !== 'column') {
            return;
        }

        $tenant = app(TenantResolver::class)->current();

        if ($tenant !== null) {
            $builder->where($model->qualifyColumn('tenant_id'), $tenant);
        }
    }
}
