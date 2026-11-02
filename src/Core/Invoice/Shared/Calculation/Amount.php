<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Core\Invoice\Shared\Calculation;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Exact decimal arithmetic for invoice amounts (no floats). Thin wrapper over brick/math that works
 * with every brick/math version Laravel 9–13 may install (rounding-mode constant names changed in 1.0).
 */
final class Amount
{
    private function __construct(private readonly BigDecimal $value)
    {
    }

    public static function of(string|int|self $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return new self(BigDecimal::of(is_int($value) ? (string) $value : trim($value)));
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero());
    }

    public static function isNumeric(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }
        if (is_float($value)) {
            return is_finite($value);
        }

        return is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', trim($value)) === 1;
    }

    /** Accepts JSON numbers too (floats are converted via their shortest exact decimal string). */
    public static function fromInput(mixed $value): self
    {
        if (is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        return self::of((string) $value);
    }

    public function plus(self $other): self
    {
        return new self($this->value->plus($other->value));
    }

    public function minus(self $other): self
    {
        return new self($this->value->minus($other->value));
    }

    public function times(self|string $other): self
    {
        return new self($this->value->multipliedBy($other instanceof self ? $other->value : BigDecimal::of($other)));
    }

    /** Rounds half-up (ZATCA amounts: 2 decimals). */
    public function round(int $scale = 2): self
    {
        return new self($this->value->toScale($scale, self::halfUp()));
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    public function compare(self $other): int
    {
        return $this->value->compareTo($other->value);
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function scale(): int
    {
        return $this->value->getScale();
    }

    /** Fixed-scale string, e.g. "231.15". */
    public function format(int $scale = 2): string
    {
        return (string) $this->value->toScale($scale, self::halfUp());
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }

    private static function halfUp(): mixed
    {
        static $mode = null;

        return $mode ??= defined(RoundingMode::class . '::HALF_UP')
            ? constant(RoundingMode::class . '::HALF_UP')
            : constant(RoundingMode::class . '::HalfUp');
    }
}
