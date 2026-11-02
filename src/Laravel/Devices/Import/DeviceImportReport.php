<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Devices\Import;

/**
 * Result of registerMany() / import() / onboardMany(): one entry per row, in input order.
 * Never contains OTPs.
 *
 * Row status: created | existing | failed | onboarded | awaiting_compliance_checks | onboarding_failed | valid
 */
final class DeviceImportReport
{
    /** @var list<array{row: int, status: string, device_id: ?int, name: ?string, errors: list<string>}> */
    private array $rows = [];

    /** @param list<string> $errors */
    public function add(int $row, string $status, ?int $deviceId = null, ?string $name = null, array $errors = []): void
    {
        $this->rows[] = ['row' => $row, 'status' => $status, 'device_id' => $deviceId, 'name' => $name, 'errors' => $errors];
    }

    /** Replaces the status of a row already in the report (e.g. created → onboarded). */
    public function update(int $row, string $status, array $errors = []): void
    {
        foreach ($this->rows as $i => $entry) {
            if ($entry['row'] === $row) {
                $this->rows[$i]['status'] = $status;
                $this->rows[$i]['errors'] = array_merge($entry['errors'], $errors);
            }
        }
    }

    /** @return list<array{row: int, status: string, device_id: ?int, name: ?string, errors: list<string>}> */
    public function rows(): array
    {
        return $this->rows;
    }

    /** @return array<string, int> status => count */
    public function counts(): array
    {
        $counts = [];
        foreach ($this->rows as $row) {
            $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
        }

        return $counts;
    }

    public function count(string $status): int
    {
        return $this->counts()[$status] ?? 0;
    }

    public function hasFailures(): bool
    {
        foreach ($this->rows as $row) {
            if (in_array($row['status'], ['failed', 'onboarding_failed'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<int> device IDs registered or found */
    public function deviceIds(): array
    {
        return array_values(array_filter(array_map(static fn ($r) => $r['device_id'], $this->rows)));
    }

    /** @return array{total: int, counts: array<string, int>, rows: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return ['total' => count($this->rows), 'counts' => $this->counts(), 'rows' => $this->rows];
    }
}
