<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Dashboard\Queries;

use CodingTriplets\Zatca\Core\Invoice\Shared\Calculation\Amount;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Database\Models\Invoice;
use CodingTriplets\Zatca\Laravel\Database\Models\ZatcaError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Report figures from the invoices table for a date range (invoice_date, inclusive). Grouped counts and sums
 * on indexed columns; the range is capped at MAX_DAYS. Tenant scope applies through the models.
 */
final class ReportQuery
{
    public const MAX_DAYS = 366;

    /** Final ZATCA acceptance (with or without warnings: the warning is a separate status dimension). */
    public const ACCEPTED = ['REPORTED', 'CLEARED'];

    public const PROBLEM = ['REJECTED', 'FAILED', 'VALIDATION_FAILED', 'MANUAL_REVIEW'];

    public const CLOSED = ['CANCELLED', 'ABANDONED'];

    public readonly Carbon $from;

    public readonly Carbon $to;

    /**
     * @param array{from?: ?string, to?: ?string, workflow?: ?string, company?: int|string|null, device?: int|string|null} $filters
     */
    public function __construct(private readonly array $filters = [])
    {
        $to = self::date($filters['to'] ?? null) ?? now()->startOfDay();
        $from = self::date($filters['from'] ?? null) ?? $to->copy()->subDays(29);
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            $from = $to->copy()->subDays(self::MAX_DAYS - 1);
        }
        $this->from = $from;
        $this->to = $to;
    }

    public function workflow(): ?string
    {
        $workflow = $this->filters['workflow'] ?? null;

        return in_array($workflow, ['b2b', 'b2c'], true) ? $workflow : null;
    }

    /** @return Builder<Invoice> */
    public function invoices(): Builder
    {
        $company = $this->filters['company'] ?? null;
        $device = $this->filters['device'] ?? null;

        return Invoice::query()
            // half-open range: also correct where a DATE is stored as "Y-m-d 00:00:00" (SQLite)
            ->where('invoice_date', '>=', $this->from->toDateString())
            ->where('invoice_date', '<', $this->to->copy()->addDay()->toDateString())
            ->when($this->workflow() !== null, fn ($q) => $q->where('workflow', $this->workflow()))
            ->when(is_numeric($company), static fn ($q) => $q->where('company_id', (int) $company))
            ->when(is_numeric($device), static fn ($q) => $q->where('device_id', (int) $device));
    }

    /**
     * One entry per day of the range (days without documents included) per workflow.
     *
     * @return array<string, list<array{date: string, accepted: int, pending: int, problem: int, closed: int}>> workflow => days
     */
    public function daily(): array
    {
        $rows = $this->invoices()
            ->selectRaw('invoice_date, workflow, processing_status, COUNT(*) as aggregate')
            ->groupBy('invoice_date', 'workflow', 'processing_status')
            ->toBase()->get();

        $empty = [];
        for ($day = $this->from->copy(); $day->lessThanOrEqualTo($this->to); $day->addDay()) {
            $empty[$day->toDateString()] = ['date' => $day->toDateString(), 'accepted' => 0, 'pending' => 0, 'problem' => 0, 'closed' => 0];
        }
        $out = [];
        foreach ($this->workflow() !== null ? [$this->workflow()] : ['b2b', 'b2c'] as $workflow) {
            $out[$workflow] = $empty;
        }
        foreach ($rows as $row) {
            $date = Carbon::parse((string) $row->invoice_date)->toDateString();
            $workflow = (string) $row->workflow;
            if (! isset($out[$workflow][$date])) {
                continue;
            }
            $out[$workflow][$date][self::bucket((string) $row->processing_status)] += (int) $row->aggregate;
        }

        return array_map('array_values', $out);
    }

    /**
     * Accepted documents only, by workflow and type, plus the net (invoices + debit notes − credit notes).
     *
     * @return array<string, array{rows: array<string, array{count: int, excl: string, vat: string, incl: string}>, net: array{excl: string, vat: string, incl: string}}>
     */
    public function vatSummary(): array
    {
        $rows = $this->invoices()->whereIn('processing_status', self::ACCEPTED)
            ->selectRaw('workflow, document_type, COUNT(*) as n, SUM(total_excl_vat) as excl, SUM(vat_total) as vat, SUM(total_incl_vat) as incl')
            ->groupBy('workflow', 'document_type')
            ->toBase()->get();

        $out = [];
        foreach ($this->workflow() !== null ? [$this->workflow()] : ['b2b', 'b2c'] as $workflow) {
            $out[$workflow] = ['rows' => [], 'net' => ['excl' => '0.00', 'vat' => '0.00', 'incl' => '0.00']];
            foreach (['388', '383', '381'] as $type) {
                $out[$workflow]['rows'][$type] = ['count' => 0, 'excl' => '0.00', 'vat' => '0.00', 'incl' => '0.00'];
            }
        }
        foreach ($rows as $row) {
            $workflow = (string) $row->workflow;
            $type = (string) $row->document_type;
            if (! isset($out[$workflow]['rows'][$type])) {
                continue;
            }
            $out[$workflow]['rows'][$type] = ['count' => (int) $row->n, 'excl' => self::money($row->excl), 'vat' => self::money($row->vat), 'incl' => self::money($row->incl)];
        }
        foreach ($out as $workflow => $data) {
            foreach (['excl', 'vat', 'incl'] as $field) {
                $out[$workflow]['net'][$field] = Amount::of($data['rows']['388'][$field])->plus(Amount::of($data['rows']['383'][$field]))
                    ->minus(Amount::of($data['rows']['381'][$field]))->format(2);
            }
        }

        return $out;
    }

    /** @return list<array{device: string, device_id: int, total: int, accepted: int, problem: int, incl: string}> top devices by volume */
    public function byDevice(int $limit = 20): array
    {
        $accepted = "'" . implode("','", self::ACCEPTED) . "'";
        $problem = "'" . implode("','", self::PROBLEM) . "'";
        $rows = $this->invoices()
            ->selectRaw("device_id, COUNT(*) as total,
                SUM(CASE WHEN processing_status IN ({$accepted}) THEN 1 ELSE 0 END) as accepted,
                SUM(CASE WHEN processing_status IN ({$problem}) THEN 1 ELSE 0 END) as problem,
                SUM(CASE WHEN processing_status IN ({$accepted}) THEN total_incl_vat ELSE 0 END) as incl")
            ->groupBy('device_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->toBase()->get();
        $names = Device::query()->whereIn('id', $rows->pluck('device_id'))->pluck('name', 'id');

        return $rows->map(static fn ($r) => [
            'device' => (string) ($names[$r->device_id] ?? '#' . $r->device_id),
            'device_id' => (int) $r->device_id,
            'total' => (int) $r->total,
            'accepted' => (int) $r->accepted,
            'problem' => (int) $r->problem,
            'incl' => self::money($r->incl),
        ])->all();
    }

    /** @return array<string, int> error category => count (errors raised in the range) */
    public function errorsByCategory(): array
    {
        return ZatcaError::query()
            ->whereBetween('created_at', [$this->from->copy()->startOfDay(), $this->to->copy()->endOfDay()])
            ->when($this->workflow() !== null, fn ($q) => $q->where('workflow', $this->workflow()))
            ->selectRaw('category, COUNT(*) as aggregate')
            ->groupBy('category')
            ->orderByDesc('aggregate')
            ->toBase()->pluck('aggregate', 'category')
            ->map(static fn ($n) => (int) $n)->all();
    }

    public static function bucket(string $status): string
    {
        return match (true) {
            in_array($status, self::ACCEPTED, true) => 'accepted',
            in_array($status, self::PROBLEM, true) => 'problem',
            in_array($status, self::CLOSED, true) => 'closed',
            default => 'pending',
        };
    }

    private static function money(mixed $value): string
    {
        return $value === null || $value === '' ? '0.00' : Amount::of(is_float($value) ? number_format($value, 2, '.', '') : (string) $value)->format(2);
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        try {
            return Carbon::createFromFormat('!Y-m-d', $value) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
