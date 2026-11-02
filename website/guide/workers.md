# Queues, workers and scheduler

ZATCA calls never run inside your web request (unless you choose B2C `sync` mode). They run on queue
workers.

## Queues

| Queue | Used for |
|---|---|
| `zatca-b2c-reporting` | Reporting simplified invoices |
| `zatca-b2c-retry` | Retries of B2C reporting |
| `zatca-b2b-generation` | Signing standard invoices received with `b2b()->submit()` |
| `zatca-b2b-clearance` | Clearing standard invoices |
| `zatca-b2b-retry` | Retries of B2B clearance |

Names are configurable in `zatca.queues.names`; the connection in `zatca.queues.connection`.

## Workers

Simple setup (one worker process, all ZATCA queues):

```bash
php artisan queue:work --queue=zatca-b2c-reporting,zatca-b2b-clearance,zatca-b2b-generation,zatca-b2c-retry,zatca-b2b-retry
```

Production setup with Supervisor:

```ini
[program:zatca-worker]
command=php /var/www/app/artisan queue:work redis --queue=zatca-b2c-reporting,zatca-b2b-clearance,zatca-b2b-generation,zatca-b2c-retry,zatca-b2b-retry --sleep=1 --tries=1 --max-time=3600
numprocs=4
autostart=true
autorestart=true
stopwaitsecs=120

[program:zatca-relay]
command=php /var/www/app/artisan zatca:outbox:relay --daemon --max-time=3600
numprocs=1
autostart=true
autorestart=true
```

- `--tries=1`: the package handles retries itself (with backoff and deadlines). Do not let Laravel
  retry ZATCA jobs.
- The **relay daemon** is optional but recommended. Without it, messages whose immediate push failed are
  picked up by the scheduler within a minute.

### Horizon

```php
// config/horizon.php
'zatca' => [
    'connection' => 'redis',
    'queue' => ['zatca-b2c-reporting', 'zatca-b2b-clearance', 'zatca-b2b-generation', 'zatca-b2c-retry', 'zatca-b2b-retry'],
    'balance' => 'auto',
    'maxProcesses' => 8,
    'tries' => 1,
    'timeout' => 120,
],
```

## Scheduler

These tasks are registered automatically (Laravel 9–13). Only the cron entry for `schedule:run` is needed.

| Command | When | Purpose |
|---|---|---|
| `zatca:outbox:relay` | every minute | Push submissions whose immediate dispatch failed; delayed retries |
| `zatca:deadlines:check` | every 10 minutes | B2C reporting deadline alerts and breaches |
| `zatca:reconcile` | every 15 minutes | Re-queue work whose queue message was lost |
| `zatca:chain:verify` | daily 02:45 | Verify every device's hash chain |
| `zatca:devices:check-expiry` | daily 03:15 | Certificate expiry warnings |
| `zatca:outbox:prune` | daily 03:30 | Delete processed outbox rows |

Disable with `zatca.outbox.schedule = false` (outbox, deadlines, reconcile, chain) or
`zatca.devices.schedule_expiry_check = false` if you schedule them yourself.

## Retries

Temporary failures (no connection, timeout, HTTP 429 or 5xx) are retried automatically with the **same
signed document**:

| Workflow | Default schedule (seconds, randomised 50–100 %) | Attempts |
|---|---|---|
| B2B | 60, 120, 300, 900, 1800, 3600, 7200, 14400, 28800, 43200 | 10 |
| B2C | 30, 60, 120, 300, 600, 1200, 1800, 3600, 7200, 10800 | 10 |

For B2C the schedule is compressed so that attempts finish at least `b2c_deadline_margin_hours` (default 4)
before the 24-hour deadline. After the last attempt the invoice becomes `FAILED`; an operator can retry it
(see [Corrections](./corrections#a-retry-the-submission)).

When ZATCA keeps failing, a **circuit breaker** pauses submissions for that environment (5 consecutive
transport failures → 60 seconds), instead of hammering an unavailable service.

## Testing locally without workers

With `QUEUE_CONNECTION=sync`, the package does **not** run ZATCA jobs inside your request. Run the
relay to process them:

```bash
php artisan zatca:outbox:relay
```
