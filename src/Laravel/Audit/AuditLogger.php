<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Audit;

use CodingTriplets\Zatca\Core\Contracts\Locks\AdvisoryLock;
use CodingTriplets\Zatca\Core\Contracts\Persistence\TransactionManager;
use CodingTriplets\Zatca\Laravel\Database\Models\AuditLog;

/**
 * Append-only, hash-chained audit trail: row_hash = sha256(prev_hash | canonical row data).
 * Tampering with or deleting a row breaks the chain (checked by verifyChain()).
 * Callers must pass only non-sensitive data in $changes; known secret keys are redacted anyway.
 */
final class AuditLogger
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    private const SENSITIVE_KEYS = ['otp', 'secret', 'private_key', 'encrypted_private_key', 'encrypted_secret', 'password', 'token', 'binary_security_token'];

    public function __construct(
        private readonly AdvisoryLock $lock,
        private readonly TransactionManager $transactions,
    ) {
    }

    /** @param array<string, mixed> $changes */
    public function record(string $action, string $subjectType, ?int $subjectId, array $changes = [], ?int $actorId = null, string $actorType = 'system'): AuditLog
    {
        $request = function_exists('request') && app()->bound('request') ? request() : null;

        $row = [
            'actor_id' => $actorId,
            'actor_type' => $actorId !== null && $actorType === 'system' ? 'user' : $actorType,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'changes' => self::redact($changes),
            'ip' => app()->runningInConsole() ? null : $request?->ip(),
            'user_agent' => app()->runningInConsole() ? null : mb_substr((string) $request?->userAgent(), 0, 512),
        ];

        // The chain is global: serialize appends so two writers never share a prev_hash.
        return $this->lock->withLock('audit-chain', 10, 10, fn () => $this->transactions->run(function () use ($row): AuditLog {
            $prev = AuditLog::query()->withoutGlobalScopes()->orderByDesc('id')->value('row_hash') ?? self::GENESIS;
            $createdAt = now()->utc()->format('Y-m-d H:i:s');

            return AuditLog::create($row + [
                'prev_hash' => $prev,
                'row_hash' => self::hash($prev, $row, $createdAt),
                'created_at' => $createdAt,
            ]);
        }));
    }

    /** @return array{valid: bool, checked: int, broken_at: ?int} */
    public function verifyChain(): array
    {
        $prev = self::GENESIS;
        $checked = 0;

        foreach (AuditLog::query()->withoutGlobalScopes()->orderBy('id')->cursor() as $log) {
            $row = [
                'actor_id' => $log->actor_id !== null ? (int) $log->actor_id : null,
                'actor_type' => $log->actor_type,
                'action' => $log->action,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id !== null ? (int) $log->subject_id : null,
                'changes' => $log->changes ?? [],
                'ip' => $log->ip,
                'user_agent' => $log->user_agent,
            ];
            $createdAt = $log->getRawOriginal('created_at');
            $createdAt = substr((string) $createdAt, 0, 19);

            if ($log->prev_hash !== $prev || $log->row_hash !== self::hash($prev, $row, $createdAt)) {
                return ['valid' => false, 'checked' => $checked, 'broken_at' => (int) $log->id];
            }

            $prev = $log->row_hash;
            $checked++;
        }

        return ['valid' => true, 'checked' => $checked, 'broken_at' => null];
    }

    /** @param array<string, mixed> $row */
    private static function hash(string $prev, array $row, string $createdAt): string
    {
        $canonical = [
            $prev,
            (string) ($row['actor_id'] ?? ''),
            (string) $row['actor_type'],
            (string) $row['action'],
            (string) $row['subject_type'],
            (string) ($row['subject_id'] ?? ''),
            json_encode(self::sortKeys($row['changes'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            (string) ($row['ip'] ?? ''),
            (string) ($row['user_agent'] ?? ''),
            $createdAt,
        ];

        return hash('sha256', implode("\x1F", $canonical));
    }

    /** @param array<mixed> $data @return array<mixed> */
    private static function sortKeys(array $data): array
    {
        ksort($data);
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = self::sortKeys($v);
            }
        }

        return $data;
    }

    /** @param array<mixed> $data @return array<mixed> */
    private static function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), self::SENSITIVE_KEYS, true)) {
                $data[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $data[$k] = self::redact($v);
            } elseif ($v instanceof \BackedEnum) {
                $data[$k] = $v->value;
            } elseif ($v instanceof \DateTimeInterface) {
                $data[$k] = $v->format(DATE_ATOM);
            }
        }

        return $data;
    }
}
