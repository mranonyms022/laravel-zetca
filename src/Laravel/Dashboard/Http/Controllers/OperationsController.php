<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers;

use CodingTriplets\Zatca\Laravel\Audit\AuditLogger;
use CodingTriplets\Zatca\Laravel\Dashboard\Abilities;
use CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers\Concerns\DashboardHelpers;
use CodingTriplets\Zatca\Laravel\Dashboard\Queries\QueueStats;
use CodingTriplets\Zatca\Laravel\Database\Models\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Queue monitor, audit history and the (read-only) settings overview. */
final class OperationsController
{
    use DashboardHelpers;

    /**
     * Configuration shown on the settings page. A whitelist: encryption keys, credentials, CA bundles and
     * anything else not listed here is never displayed.
     */
    private const SETTINGS = [
        'Environment' => ['environment'],
        'Database' => ['database.connection', 'database.table_prefix', 'database.foreign_keys', 'database.store_lines'],
        'Tenancy' => ['tenancy.mode'],
        'Security' => ['security.verify_tls'],
        'Storage' => ['storage.disk'],
        'Queues' => ['queues.connection', 'queues.high_water_mark'],
        'B2C' => ['b2c.reporting_mode', 'b2c.reporting_deadline_hours', 'b2c.infer_standard_vat_category'],
        'B2B' => ['b2b.require_buyer_identification', 'b2b.on_clearance_deactivated'],
        'Corrections' => ['correction.four_eyes'],
        'HTTP API' => ['api.enabled', 'api.prefix'],
        'Dashboard' => ['ui.enabled', 'ui.prefix', 'ui.domain', 'ui.guard', 'ui.theme', 'authorization.resolver'],
    ];

    public function queue(Request $request, QueueStats $stats): View
    {
        $this->authorizeAbility($request, Abilities::QUEUE_VIEW);

        return view('zatca::dashboard.operations.queue', ['stats' => $stats->summary()]);
    }

    public function audit(Request $request): View
    {
        $this->authorizeAbility($request, Abilities::AUDIT_VIEW);
        $action = trim((string) $request->query('action', ''));
        $actor = (string) $request->query('actor', '');

        $logs = AuditLog::query()
            ->when($action !== '', static fn ($q) => $q->where('action', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $action) . '%'))
            ->when(ctype_digit($actor), static fn ($q) => $q->where('actor_id', (int) $actor))
            ->when(in_array($request->query('subject'), ['invoice', 'device', 'error', 'company'], true), static fn ($q) => $q->where('subject_type', (string) $request->query('subject')))
            ->orderByDesc('id')
            ->simplePaginate(50)->withQueryString();

        return view('zatca::dashboard.operations.audit', ['logs' => $logs, 'filters' => $request->only(['action', 'actor', 'subject'])]);
    }

    public function verifyAudit(Request $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorizeAbility($request, Abilities::AUDIT_VIEW);
        $result = $audit->verifyChain();

        return back()->with(
            $result['valid'] ? 'zatca_status' : 'zatca_error',
            $result['valid']
                ? "Audit chain intact ({$result['checked']} entries checked)."
                : "Audit chain broken at entry #{$result['broken_at']} ({$result['checked']} checked). Investigate before trusting the audit history."
        );
    }

    public function settings(Request $request): View
    {
        $this->authorizeAbility($request, Abilities::SETTINGS_MANAGE);
        $sections = [];
        foreach (self::SETTINGS as $section => $keys) {
            foreach ($keys as $key) {
                $value = config('zatca.' . $key);
                $sections[$section][$key] = is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? (string) $value : ($value === null ? '—' : json_encode($value)));
            }
        }

        return view('zatca::dashboard.operations.settings', ['sections' => $sections]);
    }
}
