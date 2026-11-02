<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Database\Models;

use CodingTriplets\Zatca\Core\Domain\Exceptions\ZatcaException;

final class AppendOnlyViolation extends ZatcaException
{
    /** @param list<string> $columns */
    public static function update(string $model, array $columns): self
    {
        return self::withContext(
            "{$model} is append-only; attempted to modify: " . implode(', ', $columns) . '.',
            ['model' => $model, 'columns' => $columns],
        );
    }

    public static function delete(string $model): self
    {
        return self::withContext("{$model} is append-only and cannot be deleted.", ['model' => $model]);
    }
}
