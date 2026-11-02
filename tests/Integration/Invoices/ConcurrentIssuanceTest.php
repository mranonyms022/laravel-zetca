<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Tests\Integration\Invoices;

use CodingTriplets\Zatca\Core\Fatoora\BasicAuthentication;
use CodingTriplets\Zatca\Laravel\Database\Models\ChainEntry;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Fatoora\FatooraClientFactory;
use CodingTriplets\Zatca\Laravel\Processing\ChainVerifier;
use CodingTriplets\Zatca\Tests\Integration\IntegrationTestCase;
use CodingTriplets\Zatca\Tests\Integration\Support\OnboardsDevices;
use CodingTriplets\Zatca\Tests\Support\FakeZatca;

/**
 * Several OS processes issue invoices on the same device at the same time (real MySQL / PostgreSQL row locks).
 * The chain must stay contiguous: no duplicate ICV, no gap, no fork. Skipped on SQLite (single writer).
 */
final class ConcurrentIssuanceTest extends IntegrationTestCase
{
    use OnboardsDevices;

    private const WORKERS = 6;

    private const PER_WORKER = 15;

    private FakeZatca $zatca;

    public function test_parallel_processes_produce_one_contiguous_chain(): void
    {
        if (self::driver() === 'sqlite') {
            self::markTestSkipped('Run with ZATCA_TEST_DB=mysql or pgsql.');
        }

        $storage = sys_get_temp_dir() . '/zatca-conc-' . bin2hex(random_bytes(4));
        config([
            'filesystems.disks.zatca_test' => ['driver' => 'local', 'root' => $storage],
            'zatca.storage.disk' => 'zatca_test',
            'zatca.devices.sandbox_skip_compliance_checks' => true,
        ]);
        $this->zatca = new FakeZatca();
        $this->app->instance(FatooraClientFactory::class, new FatooraClientFactory(new BasicAuthentication(), $this->zatca->stack));
        $device = $this->activeDevice();

        @mkdir($storage, 0777, true);
        $procs = [];
        for ($w = 1; $w <= self::WORKERS; $w++) {
            $cmd = [PHP_BINARY, __DIR__ . '/../Support/issue-worker.php', (string) $device->id, (string) self::PER_WORKER, "W{$w}", $storage];
            $procs[$w] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$w], null, getenv() + ['ZATCA_WORKER_APP_KEY' => (string) config('app.key')]);
            // boot one at a time (testbench's bootstrap cache is not safe for concurrent boots on Windows)
            self::assertSame('READY', trim((string) fgets($pipes[$w][1])), "worker {$w} did not boot");
        }
        touch($storage . '/go');   // release all workers together

        $all = [];
        foreach ($procs as $w => $proc) {
            $out = stream_get_contents($pipes[$w][1]);
            $err = stream_get_contents($pipes[$w][2]);
            $code = proc_close($proc);
            self::assertSame(0, $code, "worker {$w} failed: {$err}");
            $counters = json_decode((string) $out, true);
            self::assertIsArray($counters, "worker {$w} output: " . substr($out . $err, 0, 400));
            self::assertCount(self::PER_WORKER, $counters, "worker {$w}");
            $all = array_merge($all, $counters);
        }

        $total = self::WORKERS * self::PER_WORKER;
        sort($all);
        self::assertSame(range(1, $total), $all, 'every worker got distinct, gap-free counters');
        self::assertSame($total, ChainEntry::query()->where('device_id', $device->id)->count());

        $result = app(ChainVerifier::class)->verify((int) $device->id, 'sandbox', deep: true, record: false);
        self::assertSame([], $result['problems']);
    }
}
