<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default environment
    |--------------------------------------------------------------------------
    | sandbox (developer portal) | simulation | production.
    | Each device also stores its own environment; counters/chains never mix.
    */
    'environment' => env('ZATCA_ENVIRONMENT', 'sandbox'),

    'endpoints' => [
        'sandbox' => env('ZATCA_SANDBOX_URL', 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal'),
        'simulation' => env('ZATCA_SIMULATION_URL', 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation'),
        'production' => env('ZATCA_PRODUCTION_URL', 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core'),
    ],

    /*
    | Timezone used to render IssueDate / IssueTime (stored values are UTC).
    */
    'timezone' => env('ZATCA_TIMEZONE', 'Asia/Riyadh'),

    'database' => [
        'connection' => env('ZATCA_DB_CONNECTION'),   // null = default connection
        'table_prefix' => env('ZATCA_TABLE_PREFIX', 'zatca_'),
        // Set false only for partitioned MySQL deployments (MySQL forbids FKs on partitioned tables).
        'foreign_keys' => env('ZATCA_DB_FOREIGN_KEYS', true),
        // Maximum accepted invoice JSON payload size in bytes.
        'max_payload_bytes' => 1024 * 1024,
        // Store calculated lines + VAT breakdown per issuance (zatca_invoice_lines / zatca_invoice_vat_breakdown).
        'store_lines' => env('ZATCA_STORE_LINES', true),
    ],

    'tenancy' => [
        'mode' => env('ZATCA_TENANCY_MODE', 'none'),  // none | column | database
        // Class implementing Core\Contracts\Tenancy\TenantResolver (null = no tenant).
        'resolver' => null,
    ],

    'security' => [
        // Dedicated master key for credential envelopes. Falls back to APP_KEY with a boot warning.
        'encryption_key' => env('ZATCA_ENCRYPTION_KEY'),
        // Old master keys still needed to decrypt existing envelopes after a key rotation.
        'previous_encryption_keys' => array_filter(explode(',', (string) env('ZATCA_PREVIOUS_ENCRYPTION_KEYS', ''))),
        // Class implementing Core\Contracts\Security\KeyManagementService (null = local key).
        'kms' => null,
        'verify_tls' => env('ZATCA_VERIFY_TLS', true),
        'ca_bundle' => env('ZATCA_CA_BUNDLE'),
    ],

    'csr' => [
        // Curve per ARCHITECTURE 2.1 (secp256k1 per ZATCA SDK; PDF profile mentions P-256, to be confirmed).
        'curve' => 'secp256k1',
        'solution_provider' => env('ZATCA_SOLUTION_PROVIDER', 'CodingTriplets'),
        'solution_model' => env('ZATCA_SOLUTION_MODEL', 'laravel-zatca'),
        // Attribute for the EGS serial in the CSR SAN: "surname" (2.5.4.4, what OpenSSL's "SN" means and
        // what ZATCA has accepted from OpenSSL-based integrations) or "serialNumber" (2.5.4.5).
        'serial_attribute' => 'surname',
    ],

    'storage' => [
        // Where signed / cleared XML is kept:
        //  database   (default) in the artifact row, gzip-compressed and base64-encoded (about 60-70% smaller than the
        //             raw XML for typical invoices). No file
        //             operations inside the issuance transaction, works on any number of servers, rolled back with
        //             the transaction and backed up with the database.
        //  filesystem on `disk` below (content-addressed files). Must be a PRIVATE disk, shared (e.g. s3) when
        //             running several app servers.
        // Existing rows are always read from where they were written, so switching is safe.
        'driver' => env('ZATCA_ARTIFACT_DRIVER', 'database'),
        'disk' => env('ZATCA_ARTIFACT_DISK', 'local'),
        'root' => 'zatca',
        // Encrypt stored XML with the credential vault (both drivers).
        'encrypt_local' => env('ZATCA_ENCRYPT_ARTIFACTS', false),
    ],

    /*
    | Cache store for state every worker must share: the ZATCA circuit breaker, the optional request-rate ceiling and
    | the optional advisory chain lock. null = the default store. With several servers it MUST be a shared store
    | (redis, memcached, database), never "file" or "array".
    */
    'cache_store' => env('ZATCA_CACHE_STORE'),

    'http' => [
        'timeout' => 30,
        'connect_timeout' => 10,
        // Optional ceiling on ZATCA submissions per second, per environment, shared by all workers on all servers
        // (through zatca.cache_store). 0 = no package ceiling: throughput is set by the number of submission
        // workers, and ZATCA's own 429 answers open the circuit breaker below.
        'max_rps' => env('ZATCA_MAX_RPS', 0),
        'max_in_flight_per_device' => env('ZATCA_MAX_IN_FLIGHT_PER_DEVICE', 2),
        // Per-environment circuit breaker. Opens after `failures` consecutive failures where ZATCA is unavailable
        // (connection failure, timeout, 5xx), or at once on 429 (for at least ZATCA's Retry-After). While open,
        // nothing is sent and no attempts are used. The open period doubles on every re-open, from open_seconds up to
        // max_open_seconds; then one probe request decides (a probe that never reports back is replaced after
        // probe_seconds).
        'circuit' => ['failures' => 5, 'open_seconds' => 30, 'max_open_seconds' => 600, 'probe_seconds' => 120],
    ],

    'outbox' => [
        // Register `zatca:outbox:relay` (every minute), `zatca:outbox:prune` and `zatca:chain:verify` (daily).
        // For low latency also run `php artisan zatca:outbox:relay --daemon` under a process supervisor.
        'schedule' => true,
        'max_attempts' => 10,          // handler errors before a message is dead-lettered
        'lease_seconds' => 600,        // a dispatched message is re-dispatched if its job has not finished by then
        // With the "sync" queue driver, jobs would run inside the request. By default they are left to the relay.
        'dispatch_on_sync_driver' => false,
    ],

    'queues' => [
        'connection' => env('ZATCA_QUEUE_CONNECTION'),  // null = default
        'names' => [
            'b2b_generation' => 'zatca-b2b-generation',
            'b2b_clearance' => 'zatca-b2b-clearance',
            'b2b_retry' => 'zatca-b2b-retry',
            'b2c_generation' => 'zatca-b2c-generation',
            'b2c_reporting' => 'zatca-b2c-reporting',
            'b2c_retry' => 'zatca-b2c-retry',
            'device_onboarding' => 'zatca-device-onboarding',
            'device_renewal' => 'zatca-device-renewal',
            'outbox' => 'zatca-outbox',
            'maintenance' => 'zatca-maintenance',
        ],
        // Outbox relay stops feeding a queue above this many pending jobs (backpressure).
        'high_water_mark' => 5000,
    ],

    'retry' => [
        'b2b' => ['max_attempts' => 10, 'backoff_seconds' => [60, 120, 300, 900, 1800, 3600, 7200, 14400, 28800, 43200]],
        'b2c' => ['max_attempts' => 10, 'backoff_seconds' => [30, 60, 120, 300, 600, 1200, 1800, 3600, 7200, 10800]],
        // max_attempts / b2c_deadline_margin_hours apply to unknown outcomes (e.g. 200 without a readable body).
        // B2C: last such attempt must happen at least this many hours before the reporting deadline.
        'b2c_deadline_margin_hours' => 4,
        // ZATCA unavailable (connection failure, timeout, 5xx, 429): these tries do NOT use up max_attempts. They go
        // on until the window closes: B2C → the reporting deadline (zatca.b2c.reporting_deadline_hours after
        // issuance); B2B → b2b_window_hours after the first attempt. Gaps follow backoff_seconds, capped at
        // max_delay_seconds so the backlog is sent soon after ZATCA is back.
        'outage' => ['b2b_window_hours' => 24, 'max_delay_seconds' => 900],
    ],

    'b2c' => [
        // Reporting window after issuance (hours): "within 24 hours" (Detailed Guidelines v1.0 6.5).
        'reporting_deadline_hours' => 24,
        // InvoiceReportingDeadlineAtRisk fires once per threshold (hours left) for documents not yet reported.
        // 12 = half-way through the 24 h window: listen to this event to page a person during a ZATCA outage.
        'deadline_alert_hours' => [12, 4, 1],
        // queue: issue + return QR/counter immediately, report on the zatca-b2c-reporting queue.
        // sync:  also report to ZATCA inside the request (slower; low volume only).
        'reporting_mode' => env('ZATCA_B2C_REPORTING_MODE', 'queue'),
        // Missing line VAT category: infer "S" only for 15% (recorded as a warning); 0% is always rejected.
        'infer_standard_vat_category' => true,
    ],

    'api' => [
        // Optional HTTP endpoints (POST {prefix}/b2c/invoices, POST {prefix}/b2b/invoices, GET {prefix}/invoices/{id}).
        'enabled' => env('ZATCA_API_ENABLED', false),
        'prefix' => env('ZATCA_API_PREFIX', 'api/zatca'),
        'middleware' => ['api'],
        // basic: HTTP Basic with the credentials below (fails closed when they are empty).
        // none:  no package auth; only when zatca.api.middleware authenticates callers itself (e.g. auth:sanctum).
        'auth' => env('ZATCA_API_AUTH', 'basic'),
        'basic' => [
            'username' => env('ZATCA_API_USERNAME'),
            'password' => env('ZATCA_API_PASSWORD'),
        ],
        'batch_max' => 500,   // reserved: batch endpoint not implemented yet
    ],

    'performance' => [
        // Keep decrypted signing keys in worker memory (Octane/RoadRunner/Swoole) for this many seconds.
        // Never written to cache, logs or jobs. 0 = decrypt on every issuance.
        'signing_key_memory_ttl' => env('ZATCA_SIGNING_KEY_TTL', 300),
        // Load shedding: answer 429/503 + Retry-After instead of letting requests pile up.
        'max_in_flight_issuance' => env('ZATCA_MAX_IN_FLIGHT_ISSUANCE', 200),
        'max_in_flight_per_device' => 8,   // reserved: not enforced yet
        'counter_lock_wait_ms' => 2000,
        // Extra cache lock around issuance (Redis recommended). Correctness never depends on it: the counter row
        // lock + compare-and-set + ledger unique keys do. Off by default (the DB row lock queues waiters efficiently).
        'advisory_chain_lock' => false,
        'shed_when_outbox_lag_seconds' => 300,   // reserved: not enforced yet
    ],

    'b2b' => [
        // BR-KSA-81 is a warning in ZATCA; the package rejects locally by default before any ICV is used.
        'require_buyer_identification' => true,
        // ZATCA answers 303 when clearance is switched off: "report" the same signed document through the
        // reporting API, or "manual_review" (an operator retries, which then reports). ⚠ Pending Q-Z8.
        'on_clearance_deactivated' => 'report',
    ],

    'correction' => [
        // Rejected B2B reissue: keep the original invoice number (IRN)? Pending Q-Z2.
        'b2b_keep_invoice_number' => true,
        // Rejected B2B reissue: issue date becomes the reissue date. Pending confirmation.
        'b2b_update_issue_date' => true,
        // Rejected B2C reissue keeps IRN and original issue date. Pending Q-Z3.
        'b2c_keep_invoice_number' => true,
        'b2c_keep_issue_date' => true,
        // Require a credit note for rejected invoices? Pending Q-Z4 (default: no).
        'credit_note_for_rejected' => false,
        // Four-eyes: approver must differ from the corrector.
        'four_eyes' => false,
    ],

    'devices' => [
        'renewal_warning_days' => [30, 14, 7, 1],
        // Register the daily expiry check with the Laravel scheduler.
        'schedule_expiry_check' => true,
        // Developer-portal sandbox only: request the production CSID without running compliance checks
        // (the developer portal issues it anyway). Never applies to simulation or production.
        'sandbox_skip_compliance_checks' => env('ZATCA_SANDBOX_SKIP_COMPLIANCE_CHECKS', false),
    ],

    'retention' => [
        'years' => 6,                // confirm with the customer's tax advisor (Q-Z15)
        'outbox_processed_days' => 14,
    ],

    'ui' => [
        'enabled' => env('ZATCA_UI_ENABLED', true),
        'prefix' => env('ZATCA_UI_PREFIX', 'admin/zatca'),
        'domain' => env('ZATCA_UI_DOMAIN'),
        'middleware' => ['web', 'auth'],
        'guard' => env('ZATCA_UI_GUARD'),
        'route_name_prefix' => 'zatca.',
        'theme' => 'light',          // light | dark | auto
    ],

    'authorization' => [
        // Class implementing the package PermissionResolver (null = Gate / $user->can()).
        'resolver' => null,
    ],
];
