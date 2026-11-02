<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models\Concerns;

use CodingTriplets\Zatca\Laravel\Database\Models\AppendOnlyViolation;

/**
 * Regulatory history rows are inserted, never updated or deleted through Eloquent (rule R14).
 *
 * A model may list columns that are legitimately written after insert (e.g. an issuance's status)
 * in a public `$appendOnlyMutable` array; everything else is frozen once persisted.
 * Query-builder writes bypass model events, so repositories must not issue them for these tables;
 * strict deployments can additionally add DB triggers / revoke UPDATE+DELETE grants.
 *
 * Tables that only carry created_at declare `const UPDATED_AT = null;` on the model.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(static function ($model): void {
            $allowed = property_exists($model, 'appendOnlyMutable') ? $model->appendOnlyMutable : [];
            $dirty = array_values(array_diff(array_keys($model->getDirty()), $allowed, ['updated_at']));

            if ($dirty !== []) {
                throw AppendOnlyViolation::update($model::class, $dirty);
            }
        });

        static::deleting(static function ($model): void {
            throw AppendOnlyViolation::delete($model::class);
        });
    }
}
