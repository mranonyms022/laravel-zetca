<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Contracts\Monitoring;

/** Every metric carries a `workflow` label (b2b|b2c) where applicable. */
interface MetricsCollector
{
    /** @param array<string, string> $labels */
    public function increment(string $name, array $labels = [], float $by = 1.0): void;

    /** @param array<string, string> $labels */
    public function observe(string $name, float $value, array $labels = []): void;

    /** @param array<string, string> $labels */
    public function gauge(string $name, float $value, array $labels = []): void;
}
