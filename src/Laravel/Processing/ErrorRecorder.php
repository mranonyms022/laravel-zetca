<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Processing;

use CodingTriplets\Zatca\Core\Domain\Enums\ErrorCategory;
use CodingTriplets\Zatca\Core\Domain\Enums\Severity;
use CodingTriplets\Zatca\Laravel\Database\Models\ErrorGroup;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\ZatcaError;
use Illuminate\Support\Facades\Log;

/**
 * Error engine (ARCHITECTURE 12.5): every error is stored with its context and grouped by fingerprint
 * (workflow, category, code, normalized message, source) so recurring problems show up once in the console.
 * Details are sanitized: no credentials, keys or invoice XML.
 */
final class ErrorRecorder
{
    private const SENSITIVE = ['otp', 'secret', 'password', 'token', 'authorization', 'private_key', 'binarysecuritytoken', 'invoice', 'xml', 'signed_xml', 'clearedinvoice'];

    /**
     * @param array{
     *   invoice?: ?Invoice, invoice_id?: ?int, issuance_id?: ?int, device_id?: ?int, company_id?: ?int,
     *   workflow?: ?string, details?: array<string, mixed>, attempt_number?: ?int, submission_attempt_id?: ?int,
     *   tenant_id?: ?string
     * } $context
     */
    public function record(ErrorCategory $category, Severity $severity, ?string $code, string $message, string $source, array $context = []): ZatcaError
    {
        $invoice = $context['invoice'] ?? null;
        $workflow = $context['workflow'] ?? $invoice?->workflow?->value;
        $message = mb_substr($message, 0, 4000);
        $normalized = self::normalize($message);
        $fingerprint = sha1(implode('|', [$workflow, $category->value, (string) $code, $normalized, $source]));
        $tenant = $context['tenant_id'] ?? $invoice?->tenant_id;
        $now = now();

        $tenant = (string) ($tenant ?? app(\CodingTriplets\Zatca\Core\Contracts\Tenancy\TenantResolver::class)->current() ?? '');
        $groups = new ErrorGroup();
        // insertOrIgnore: safe under concurrency and inside PostgreSQL transactions (no aborted transaction).
        $groups->getConnection()->table($groups->getTable())->insertOrIgnore([
            'tenant_id' => $tenant,
            'fingerprint' => $fingerprint,
            'workflow' => $workflow,
            'category' => $category->value,
            'code' => $code !== null ? mb_substr($code, 0, 64) : null,
            'normalized_message' => mb_substr($normalized, 0, 1000),
            'occurrences' => 0,
            'open_count' => 0,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $group = ErrorGroup::query()->withoutGlobalScopes()->where('tenant_id', $tenant)->where('fingerprint', $fingerprint)->firstOrFail();
        ErrorGroup::query()->withoutGlobalScopes()->whereKey($group->id)->update([
            'occurrences' => \Illuminate\Support\Facades\DB::raw('occurrences + 1'),
            'open_count' => \Illuminate\Support\Facades\DB::raw('open_count + 1'),
            'last_seen_at' => $now,
        ]);

        $error = ZatcaError::query()->create(array_filter([
            'tenant_id' => $tenant,
            'invoice_id' => $invoice?->id ?? $context['invoice_id'] ?? null,
            'issuance_id' => $context['issuance_id'] ?? null,
            'device_id' => $context['device_id'] ?? $invoice?->device_id,
            'company_id' => $context['company_id'] ?? $invoice?->company_id,
            'workflow' => $workflow,
            'category' => $category,
            'severity' => $severity,
            'code' => $code !== null ? mb_substr($code, 0, 64) : null,
            'message' => $message,
            'details' => isset($context['details']) ? self::sanitize($context['details']) : null,
            'source' => mb_substr($source, 0, 128),
            'attempt_number' => $context['attempt_number'] ?? null,
            'submission_attempt_id' => $context['submission_attempt_id'] ?? null,
            'group_id' => $group->id,
            'fingerprint' => $fingerprint,
            'is_resolved' => false,
        ], static fn ($v) => $v !== null));

        if ($invoice !== null) {
            Invoice::query()->withoutGlobalScopes()->whereKey($invoice->id)->update([
                'unresolved_error_count' => \Illuminate\Support\Facades\DB::raw('unresolved_error_count + 1'),
                'last_error_code' => $code !== null ? mb_substr($code, 0, 64) : $category->value,
            ]);
        }

        if ($severity === Severity::Critical) {
            Log::critical("[zatca] {$category->value}: {$message}", ['code' => $code, 'source' => $source, 'invoice_id' => $error->invoice_id, 'device_id' => $error->device_id]);
        }

        return $error;
    }

    /** Collapses volatile parts (numbers, UUIDs, hashes) so the same problem groups together. */
    public static function normalize(string $message): string
    {
        $message = (string) preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '{uuid}', $message);
        $message = (string) preg_replace('#[A-Za-z0-9+/]{32,}={0,2}#', '{b64}', $message);
        $message = (string) preg_replace('/\d+(\.\d+)?/', '{n}', $message);

        return trim((string) preg_replace('/\s+/', ' ', $message));
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public static function sanitize(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array(strtolower(str_replace(['-', ' '], '_', $k)), self::SENSITIVE, true)) {
                $data[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $data[$k] = self::sanitize($v);
            } elseif (is_string($v) && strlen($v) > 2000) {
                $data[$k] = substr($v, 0, 2000) . '…';
            }
        }

        return $data;
    }
}
