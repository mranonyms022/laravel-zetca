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
    ],

    'tenancy' => [
        'mode' => env('ZATCA_TENANCY_MODE', 'none'),  // none | column | database
        // Class implementing Core\Contracts\Tenancy\TenantResolver (null = no tenant).
        'resolver' => null,
    ],

    'security' => [
        // Dedicated master key for credential envelopes. Falls back to APP_KEY with a boot warning.
        'encryption_key' => env('ZATCA_ENCRYPTION_KEY'),
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
    ],

    'storage' => [
        // Must be a PRIVATE disk. Use a shared disk (e.g. s3) when running several app servers.
        'disk' => env('ZATCA_ARTIFACT_DISK', 'local'),
        'root' => 'zatca',
        'encrypt_local' => env('ZATCA_ENCRYPT_ARTIFACTS', false),
    ],

    'http' => [
        'timeout' => 30,
        'connect_timeout' => 10,
        'max_rps' => env('ZATCA_MAX_RPS', 10),
        'max_in_flight_per_device' => env('ZATCA_MAX_IN_FLIGHT_PER_DEVICE', 2),
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
        // B2C: last automatic attempt must happen at least this many hours before the reporting deadline.
        'b2c_deadline_margin_hours' => 4,
    ],

    'b2c' => [
        // Reporting window after issuance (hours): "within 24 hours" (Detailed Guidelines v1.0 6.5).
        'reporting_deadline_hours' => 24,
        // queue: issue + return QR/counter immediately, report on the zatca-b2c-reporting queue.
        // sync:  also report to ZATCA inside the request (slower; low volume only).
        'reporting_mode' => env('ZATCA_B2C_REPORTING_MODE', 'queue'),
        // Missing line VAT category: infer "S" only for 15% (recorded as a warning); 0% is always rejected.
        'infer_standard_vat_category' => true,
    ],

    'api' => [
        // Optional HTTP endpoints (POST {prefix}/b2c/invoices, /b2c/invoices/batch, GET {prefix}/invoices/{id}).
        'enabled' => env('ZATCA_API_ENABLED', false),
        'prefix' => env('ZATCA_API_PREFIX', 'api/zatca'),
        'middleware' => ['api'],
        'batch_max' => 500,
    ],

    'performance' => [
        // Keep decrypted signing keys in worker memory (Octane/RoadRunner/Swoole) for this many seconds.
        // Never written to cache, logs or jobs. 0 = decrypt on every issuance.
        'signing_key_memory_ttl' => env('ZATCA_SIGNING_KEY_TTL', 300),
        // Load shedding: answer 429/503 + Retry-After instead of letting requests pile up.
        'max_in_flight_issuance' => env('ZATCA_MAX_IN_FLIGHT_ISSUANCE', 200),
        'max_in_flight_per_device' => 8,
        'counter_lock_wait_ms' => 2000,
        'shed_when_outbox_lag_seconds' => 300,
    ],

    'b2b' => [
        // BR-KSA-81 is a warning in ZATCA; the package rejects locally by default before any ICV is used.
        'require_buyer_identification' => true,
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
