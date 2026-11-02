<?php

declare(strict_types=1);

// Child process for the concurrency test: issues N B2C invoices on one device and prints the counters as JSON.
// argv: device id, count, invoice-number prefix, artifact storage root

use CodingTriplets\Zatca\Laravel\ZatcaManager;
use CodingTriplets\Zatca\Laravel\ZatcaServiceProvider;
use CodingTriplets\Zatca\Tests\Integration\IntegrationTestCase;
use Orchestra\Testbench\Foundation\Application;

require __DIR__ . '/../../../vendor/autoload.php';

[, $deviceId, $count, $prefix, $storage] = $argv;

$app = Application::create();
(static function ($app) use ($storage): void {
    $app['config']->set('database.default', 'testing');
    $app['config']->set('database.connections.testing', IntegrationTestCase::connectionConfig());
    $app['config']->set('filesystems.disks.zatca_test', ['driver' => 'local', 'root' => $storage]);
    $app['config']->set('zatca.storage.disk', 'zatca_test');
    $app['config']->set('app.key', (string) getenv('ZATCA_WORKER_APP_KEY'));
})($app);
$app->register(ZatcaServiceProvider::class);

// Barrier: report ready, then wait for the "go" file so all workers issue at the same time.
fwrite(STDOUT, "READY
");
fflush(STDOUT);
$deadline = microtime(true) + 60;
while (! is_file($storage . '/go') && microtime(true) < $deadline) {
    usleep(2000);
}

$counters = [];
for ($i = 1; $i <= (int) $count; $i++) {
    $result = $app->make(ZatcaManager::class)->b2c()->issue([
        'invoice_number' => "{$prefix}-{$i}",
        'lines' => [['name' => 'Tea', 'quantity' => '1', 'unit_price' => (string) (10 + $i) . '.00', 'vat_category' => 'S', 'vat_rate' => '15']],
    ], ['device' => (int) $deviceId])->toArray();
    if (($result['status'] ?? false) !== true || ! isset($result['counter'])) {
        fwrite(STDERR, json_encode($result) . "\n");
        exit(1);
    }
    $counters[] = $result['counter'];
}

echo json_encode($counters);
