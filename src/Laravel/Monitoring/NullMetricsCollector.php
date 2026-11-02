<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Monitoring;

use CodingTriplets\Zatca\Core\Contracts\Monitoring\MetricsCollector;

final class NullMetricsCollector implements MetricsCollector
{
    public function increment(string $name, array $labels = [], float $by = 1.0): void
    {
    }

    public function observe(string $name, float $value, array $labels = []): void
    {
    }

    public function gauge(string $name, float $value, array $labels = []): void
    {
    }
}
