<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Dashboard;

/** Dashboard abilities (ARCHITECTURE §17). No ability ever reveals private keys, CSID secrets or OTPs. */
final class Abilities
{
    public const DASHBOARD_VIEW = 'zatca.dashboard.view';
    public const B2B_VIEW = 'zatca.b2b.view';
    public const B2B_RETRY = 'zatca.b2b.retry';
    public const B2C_VIEW = 'zatca.b2c.view';
    public const B2C_RETRY = 'zatca.b2c.retry';
    public const INVOICES_EDIT = 'zatca.invoices.edit';
    public const INVOICES_CORRECT = 'zatca.invoices.correct';
    public const INVOICES_REPROCESS = 'zatca.invoices.reprocess';
    public const INVOICES_EXPORT = 'zatca.invoices.export';
    public const DEVICES_VIEW = 'zatca.devices.view';
    public const DEVICES_ONBOARD = 'zatca.devices.onboard';
    public const DEVICES_RENEW = 'zatca.devices.renew';
    public const ERRORS_VIEW = 'zatca.errors.view';
    public const ERRORS_RESOLVE = 'zatca.errors.resolve';
    public const LOGS_VIEW = 'zatca.logs.view';
    public const QUEUE_VIEW = 'zatca.queue.view';
    public const REPORTS_VIEW = 'zatca.reports.view';
    public const AUDIT_VIEW = 'zatca.audit.view';
    public const COMPANIES_VIEW = 'zatca.companies.view';
    public const COMPANIES_MANAGE = 'zatca.companies.manage';
    public const SETTINGS_MANAGE = 'zatca.settings.manage';
    public const CREDENTIALS_MANAGE = 'zatca.credentials.manage';

    /** @return list<string> */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants());
    }

    /** View ability of a workflow ('b2b' | 'b2c'). */
    public static function view(string $workflow): string
    {
        return $workflow === 'b2b' ? self::B2B_VIEW : self::B2C_VIEW;
    }

    public static function retry(string $workflow): string
    {
        return $workflow === 'b2b' ? self::B2B_RETRY : self::B2C_RETRY;
    }
}
