@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $tone = match (true) {
        in_array($value, ['REPORTED', 'CLEARED', 'COMPLETED', 'ACTIVE', 'accepted'], true) => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200',
        in_array($value, ['REPORTED_WITH_WARNINGS', 'CLEARED_WITH_WARNINGS', 'accepted_with_warnings', 'RETRY_PENDING', 'OUTCOME_UNKNOWN', 'CLEARANCE_DEACTIVATED'], true) => 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200',
        in_array($value, ['REJECTED', 'FAILED', 'VALIDATION_FAILED', 'NOT_REPORTED', 'NOT_CLEARED', 'rejected', 'ONBOARDING_FAILED', 'EXPIRED'], true) => 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200',
        $value === 'MANUAL_REVIEW' => 'bg-violet-100 text-violet-800 dark:bg-violet-900/50 dark:text-violet-200',
        in_array($value, ['CANCELLED', 'ABANDONED', 'superseded', 'NOT_APPLICABLE'], true) => 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
        default => 'bg-sky-100 text-sky-800 dark:bg-sky-900/50 dark:text-sky-200',
    };
@endphp
<span class="inline-flex whitespace-nowrap rounded px-1.5 py-0.5 text-xs font-medium {{ $tone }}">{{ str_replace('_', ' ', $value) }}</span>
