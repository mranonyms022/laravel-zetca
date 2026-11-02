<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel;

use CodingTriplets\Zatca\Core\Contracts\Device\ComplianceCheckRunner;
use CodingTriplets\Zatca\Core\Contracts\Events\EventDispatcher;
use CodingTriplets\Zatca\Core\Contracts\Fatoora\AuthenticationStrategy;
use CodingTriplets\Zatca\Core\Contracts\Locks\AdvisoryLock;
use CodingTriplets\Zatca\Core\Contracts\Monitoring\MetricsCollector;
use CodingTriplets\Zatca\Core\Contracts\Persistence\TransactionManager;
use CodingTriplets\Zatca\Core\Contracts\Security\CredentialVault;
use CodingTriplets\Zatca\Core\Contracts\Security\KeyManagementService;
use CodingTriplets\Zatca\Core\Contracts\Tenancy\TenantResolver;
use CodingTriplets\Zatca\Core\Device\CsrGenerator;
use CodingTriplets\Zatca\Core\Domain\Enums\Environment;
use CodingTriplets\Zatca\Core\Domain\Enums\TenancyMode;
use CodingTriplets\Zatca\Core\Domain\Exceptions\ConfigurationException;
use CodingTriplets\Zatca\Core\Fatoora\BasicAuthentication;
use CodingTriplets\Zatca\Core\Security\EnvelopeCredentialVault;
use CodingTriplets\Zatca\Core\Security\LocalKeyManagementService;
use CodingTriplets\Zatca\Laravel\Audit\AuditLogger;
use CodingTriplets\Zatca\Laravel\Console;
use CodingTriplets\Zatca\Laravel\Database\EloquentTransactionManager;
use CodingTriplets\Zatca\Laravel\Devices\CertificateRenewalService;
use CodingTriplets\Zatca\Laravel\Devices\DeviceOnboardingService;
use CodingTriplets\Zatca\Laravel\Fatoora\FatooraClientFactory;
use CodingTriplets\Zatca\Laravel\Database\Schema\Tables;
use CodingTriplets\Zatca\Laravel\Events\LaravelEventDispatcher;
use CodingTriplets\Zatca\Laravel\Locks\CacheAdvisoryLock;
use CodingTriplets\Zatca\Laravel\Monitoring\NullMetricsCollector;
use CodingTriplets\Zatca\Laravel\Tenancy\NullTenantResolver;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Registration uses only APIs available from Laravel 9 through 13 (no bootstrap/app.php hooks,
 * no Schedule facade, no ShouldDispatchAfterCommit) — see ARCHITECTURE 5.3.
 */
final class ZatcaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/zatca.php', 'zatca');

        $this->app->singleton(ZatcaManager::class);

        $this->app->singleton(TransactionManager::class, static fn (Application $app) => new EloquentTransactionManager($app['db']));
        $this->app->singleton(AdvisoryLock::class, static fn (Application $app) => new CacheAdvisoryLock($app['cache']->store()));
        $this->app->singleton(EventDispatcher::class, static fn (Application $app) => new LaravelEventDispatcher(
            static fn () => $app['events'],
            $app->make(TransactionManager::class),
        ));
        $this->app->singleton(MetricsCollector::class, NullMetricsCollector::class);

        $this->registerSecurity();
        $this->registerDevices();
        $this->registerProcessing();

        $this->app->singleton(TenantResolver::class, static function (Application $app): TenantResolver {
            $class = config('zatca.tenancy.resolver');

            if ($class === null) {
                return new NullTenantResolver();
            }

            $resolver = $app->make($class);
            if (! $resolver instanceof TenantResolver) {
                throw new ConfigurationException("zatca.tenancy.resolver [{$class}] must implement " . TenantResolver::class . '.');
            }

            return $resolver;
        });
    }

    private function registerSecurity(): void
    {
        $this->app->singleton(KeyManagementService::class, static function (Application $app): KeyManagementService {
            $class = config('zatca.security.kms');
            if ($class !== null) {
                $kms = $app->make($class);
                if (! $kms instanceof KeyManagementService) {
                    throw new ConfigurationException("zatca.security.kms [{$class}] must implement " . KeyManagementService::class . '.');
                }

                return $kms;
            }

            $keys = array_values(array_filter(array_merge(
                [config('zatca.security.encryption_key') ?: config('app.key')],
                (array) config('zatca.security.previous_encryption_keys', []),
            )));

            return new LocalKeyManagementService($keys);
        });
        $this->app->singleton(CredentialVault::class, static fn (Application $app) => new EnvelopeCredentialVault($app->make(KeyManagementService::class)));
        $this->app->singleton(AuthenticationStrategy::class, BasicAuthentication::class);
    }

    private function registerDevices(): void
    {
        $this->app->singleton(AuditLogger::class);
        $this->app->singleton(FatooraClientFactory::class, static fn (Application $app) => new FatooraClientFactory($app->make(AuthenticationStrategy::class)));
        $this->app->singleton(CsrGenerator::class, static fn () => new CsrGenerator(
            config('zatca.csr.serial_attribute', 'surname') === 'serialNumber'
                ? CsrGenerator::SERIAL_ATTRIBUTE_SERIAL_NUMBER
                : CsrGenerator::SERIAL_ATTRIBUTE_SURNAME,
        ));
        if (! $this->app->bound(ComplianceCheckRunner::class)) {
            $this->app->bind(ComplianceCheckRunner::class, \CodingTriplets\Zatca\Laravel\Devices\FatooraComplianceCheckRunner::class);
        }
        $this->app->bind(DeviceOnboardingService::class, static fn (Application $app) => new DeviceOnboardingService(
            $app->make(TransactionManager::class),
            $app->make(AdvisoryLock::class),
            $app->make(\CodingTriplets\Zatca\Laravel\Devices\DeviceCredentialStore::class),
            $app->make(FatooraClientFactory::class),
            $app->make(EventDispatcher::class),
            $app->make(AuditLogger::class),
            $app->bound(ComplianceCheckRunner::class) ? $app->make(ComplianceCheckRunner::class) : null,
        ));
        $this->app->bind(CertificateRenewalService::class);
    }

    private function registerProcessing(): void
    {
        $app = $this->app;
        $app->singleton(\CodingTriplets\Zatca\Core\Contracts\Storage\ArtifactStore::class, static fn (Application $app) => new Storage\FilesystemArtifactStore(
            $app['filesystem'],
            $app->make(CredentialVault::class),
            (string) config('zatca.storage.disk', 'local'),
            (string) config('zatca.storage.root', 'zatca'),
            (bool) config('zatca.storage.encrypt_local', false),
        ));
        $app->singleton(Invoices\SigningCredentialProvider::class, static fn (Application $app) => new Invoices\SigningCredentialProvider(
            $app->make(Devices\DeviceCredentialStore::class),
            (int) config('zatca.performance.signing_key_memory_ttl', 300),
        ));
        $app->singleton(Invoices\InvoiceGenerators::class);
        $app->singleton(Invoices\PayloadPreparer::class);
        $app->singleton(Processing\Outbox\OutboxDispatcher::class, static fn (Application $app) => new Processing\Outbox\OutboxDispatcher(
            $app['queue'],
            config('zatca.queues.connection'),
            (int) config('zatca.queues.high_water_mark', 5000),
            (int) config('zatca.outbox.lease_seconds', 600),
        ));
        $app->singleton(Processing\Submission\CircuitBreaker::class, static fn (Application $app) => new Processing\Submission\CircuitBreaker(
            $app['cache']->store(),
            (int) config('zatca.http.circuit.failures', 5),
            (int) config('zatca.http.circuit.open_seconds', 60),
        ));
        $app->bind('zatca.b2c', static fn (Application $app) => new Invoices\InvoiceIssuer(
            \CodingTriplets\Zatca\Core\Domain\Enums\Workflow::B2C,
            $app->make(Invoices\InvoiceIntakeService::class),
            $app->make(Invoices\InvoiceProcessor::class),
            $app->make(Processing\Submission\SubmissionRunner::class),
        ));
        $app->bind('zatca.b2b', static fn (Application $app) => new Invoices\InvoiceIssuer(
            \CodingTriplets\Zatca\Core\Domain\Enums\Workflow::B2B,
            $app->make(Invoices\InvoiceIntakeService::class),
            $app->make(Invoices\InvoiceProcessor::class),
            $app->make(Processing\Submission\SubmissionRunner::class),
        ));
    }

    public function boot(): void
    {
        $this->validateConfiguration();

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\RegisterTaxpayerCommand::class,
                Console\RegisterDeviceCommand::class,
                Console\OnboardDeviceCommand::class,
                Console\RenewDeviceCommand::class,
                Console\DevicesCommand::class,
                Console\CheckCertificateExpiryCommand::class,
                Console\VerifyAuditCommand::class,
                Console\VerifyChainCommand::class,
                Console\OutboxRelayCommand::class,
                Console\OutboxPruneCommand::class,
            ]);

            if (config('zatca.outbox.schedule', true)) {
                $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                    $schedule->command('zatca:outbox:relay')->everyMinute()->withoutOverlapping()->onOneServer();
                    $schedule->command('zatca:outbox:prune')->dailyAt('03:30')->withoutOverlapping()->onOneServer();
                    $schedule->command('zatca:chain:verify')->dailyAt('02:45')->withoutOverlapping()->onOneServer();
                });
            }

            if (config('zatca.devices.schedule_expiry_check', true)) {
                // callAfterResolving works on Laravel 9-13 (no Schedule facade / routes/console.php needed).
                $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                    $schedule->command('zatca:devices:check-expiry')->dailyAt('03:15')->withoutOverlapping()->onOneServer();
                });
            }

            $this->publishes([__DIR__ . '/../../config/zatca.php' => config_path('zatca.php')], 'zatca-config');
            $this->publishes([__DIR__ . '/../../database/migrations' => database_path('migrations')], 'zatca-migrations');
        }

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        if (config('zatca.api.enabled', false)) {
            $this->loadRoutesFrom(__DIR__ . '/../../routes/api.php');
        }

        $this->warnAboutUnsafeSetups();
    }

    private function validateConfiguration(): void
    {
        $environment = (string) config('zatca.environment');
        if (Environment::tryFrom($environment) === null) {
            throw new ConfigurationException("zatca.environment must be sandbox, simulation or production; got [{$environment}].");
        }

        $mode = (string) config('zatca.tenancy.mode');
        if (TenancyMode::tryFrom($mode) === null) {
            throw new ConfigurationException("zatca.tenancy.mode must be none, column or database; got [{$mode}].");
        }

        $prefix = Tables::prefix();
        if (preg_match('/^[a-z][a-z0-9_]{0,15}$/', $prefix) !== 1) {
            throw new ConfigurationException('zatca.database.table_prefix must be lowercase alphanumeric/underscore, max 16 characters.');
        }

        if (Environment::from($environment)->isProduction() && ! config('zatca.security.verify_tls')) {
            throw new ConfigurationException('TLS verification cannot be disabled in the production environment.');
        }
    }

    private function warnAboutUnsafeSetups(): void
    {
        if (Environment::from((string) config('zatca.environment')) !== Environment::Production) {
            return;
        }

        $logger = $this->app->make(LoggerInterface::class);

        if (empty(config('zatca.security.encryption_key'))) {
            $logger->warning('[zatca] ZATCA_ENCRYPTION_KEY is not set; credential envelopes fall back to APP_KEY.');
        }

        // Read the driver from config: opening a connection on every boot would be wasteful.
        $name = Tables::connection() ?? (string) config('database.default');
        if (config("database.connections.{$name}.driver") === 'sqlite') {
            $logger->warning('[zatca] SQLite is supported for development and tests only; it serializes all writers.');
        }
    }
}
