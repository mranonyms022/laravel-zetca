<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel;

use CodingTriplets\Zatca\Core\Domain\Enums\Environment;

/**
 * Entry point behind the Zatca facade. Workflow APIs (b2b()/b2c() issue/submit) are added in M5/M6.
 */
final class ZatcaManager
{
    public const VERSION = '0.5.0-dev';

    /** @var (\Closure(mixed $user, string $ability): bool)|null */
    private ?\Closure $authCallback = null;

    public function version(): string
    {
        return self::VERSION;
    }

    public function defaultEnvironment(): Environment
    {
        return Environment::from((string) config('zatca.environment', 'sandbox'));
    }

    /** B2C (simplified invoices, reporting). */
    public function b2c(): \CodingTriplets\Zatca\Laravel\Invoices\InvoiceIssuer
    {
        return app('zatca.b2c');
    }

    /** B2B (standard invoices, clearance). */
    public function b2b(): \CodingTriplets\Zatca\Laravel\Invoices\InvoiceIssuer
    {
        return app('zatca.b2b');
    }

    /** Retry, correct, reissue, approve, credit/debit notes (operations A, B, C). */
    public function invoices(): \CodingTriplets\Zatca\Laravel\Invoices\InvoiceOperations
    {
        return app(\CodingTriplets\Zatca\Laravel\Invoices\InvoiceOperations::class);
    }

    /** Registers the console authorization callback (Horizon-style). */
    public function auth(\Closure $callback): self
    {
        $this->authCallback = $callback;

        return $this;
    }

    public function authCallback(): ?\Closure
    {
        return $this->authCallback;
    }
}
