<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Devices\Import;

use CodingTriplets\Zatca\Core\Domain\Exceptions\InvalidValue;
use Illuminate\Support\Facades\Storage;

/**
 * Streams rows (header => value) from a CSV or Excel file, without loading the file into memory.
 *
 *  - CSV / TXT: built in (UTF-8, BOM stripped, delimiter "," ";" or tab detected from the header line).
 *  - XLSX / ODS: needs `composer require openspout/openspout` (first sheet is read).
 *
 * Source: a local path, or ['disk' => 's3', 'path' => 'imports/devices.xlsx'] (copied to a private temp file
 * that is deleted afterwards). Header names are normalised: "Store ID" → "store_id".
 */
final class SpreadsheetReader
{
    /**
     * @param string|array{disk: string, path: string} $source
     * @return \Generator<int, array<string, string>> row number (1 = first data row) => values
     */
    public function rows(string|array $source): \Generator
    {
        [$path, $temporary] = $this->localPath($source);
        try {
            $extension = strtolower(pathinfo(is_array($source) ? $source['path'] : $source, PATHINFO_EXTENSION));
            yield from match ($extension) {
                'csv', 'txt' => $this->csv($path),
                'xlsx', 'ods' => $this->spreadsheet($path, $extension),
                default => throw InvalidValue::for('file', "type .{$extension} is not supported (use .csv, .xlsx or .ods)"),
            };
        } finally {
            if ($temporary) {
                @unlink($path);
            }
        }
    }

    /** @return \Generator<int, array<string, string>> */
    private function csv(string $path): \Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw InvalidValue::for('file', 'cannot be opened');
        }
        try {
            $first = fgets($handle);
            if ($first === false) {
                return;
            }
            $first = preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first;
            $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
            arsort($counts);
            $delimiter = (string) array_key_first($counts);
            $header = $this->header(str_getcsv(rtrim($first, "\r\n"), $delimiter, '"', '\\'));

            $n = 0;
            while (($cells = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                if ($cells === [null] || $this->blank($cells)) {
                    continue;
                }
                yield ++$n => $this->combine($header, $cells);
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return \Generator<int, array<string, string>> */
    private function spreadsheet(string $path, string $extension): \Generator
    {
        $class = $extension === 'ods' ? 'OpenSpout\\Reader\\ODS\\Reader' : 'OpenSpout\\Reader\\XLSX\\Reader';
        if (! class_exists($class)) {
            throw InvalidValue::for('file', 'Excel files need the openspout/openspout package: composer require openspout/openspout (or upload a .csv file)');
        }

        $reader = new $class();
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $header = null;
                $n = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map(static fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (is_scalar($v) || $v === null ? (string) $v : ''), $row->toArray());
                    if ($header === null) {
                        $header = $this->header($cells);
                        continue;
                    }
                    if ($this->blank($cells)) {
                        continue;
                    }
                    yield ++$n => $this->combine($header, $cells);
                }

                break;   // first sheet only
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @param list<?string> $cells
     * @return list<string>
     */
    private function header(array $cells): array
    {
        return array_map(static fn ($c) => trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) $c))), '_'), $cells);
    }

    /**
     * @param list<string> $header
     * @param list<?string> $cells
     * @return array<string, string>
     */
    private function combine(array $header, array $cells): array
    {
        $row = [];
        foreach ($header as $i => $key) {
            if ($key !== '') {
                $row[$key] = trim((string) ($cells[$i] ?? ''));
            }
        }

        return $row;
    }

    /** @param list<?string> $cells */
    private function blank(array $cells): bool
    {
        foreach ($cells as $c) {
            if (trim((string) $c) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string|array{disk: string, path: string} $source
     * @return array{0: string, 1: bool} local path, is temporary
     */
    private function localPath(string|array $source): array
    {
        if (is_string($source)) {
            if (! is_file($source) || ! is_readable($source)) {
                throw InvalidValue::for('file', "[{$source}] does not exist or is not readable");
            }

            return [$source, false];
        }

        $disk = Storage::disk($source['disk']);
        if (! $disk->exists($source['path'])) {
            throw InvalidValue::for('file', "[{$source['path']}] does not exist on disk [{$source['disk']}]");
        }
        $temp = tempnam(sys_get_temp_dir(), 'zatca-import-');
        if ($temp === false) {
            throw InvalidValue::for('file', 'no temporary file could be created');
        }
        @chmod($temp, 0600);
        $in = $disk->readStream($source['path']);
        $out = fopen($temp, 'wb');
        stream_copy_to_stream($in, $out);
        fclose($out);
        if (is_resource($in)) {
            fclose($in);
        }

        return [$temp, true];
    }
}
