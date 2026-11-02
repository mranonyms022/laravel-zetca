<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers;

use CodingTriplets\Zatca\Core\Domain\Enums\ErrorCategory;
use CodingTriplets\Zatca\Core\Domain\Enums\Severity;
use CodingTriplets\Zatca\Laravel\Dashboard\Abilities;
use CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers\Concerns\DashboardHelpers;
use CodingTriplets\Zatca\Laravel\Database\Models\ErrorGroup;
use CodingTriplets\Zatca\Laravel\Database\Models\Warning;
use CodingTriplets\Zatca\Laravel\Database\Models\ZatcaError;
use CodingTriplets\Zatca\Laravel\Processing\ErrorResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Errors, error groups, warnings; operators can mark errors resolved (acknowledge) with a note. */
final class ErrorController
{
    use DashboardHelpers;

    public function index(Request $request): View
    {
        $this->authorizeAbility($request, Abilities::ERRORS_VIEW);
        $state = in_array($request->query('state'), ['open', 'resolved', 'all'], true) ? (string) $request->query('state') : 'open';

        $errors = ZatcaError::query()
            ->with(['invoice:id,public_id,invoice_number'])
            ->when($state === 'open', static fn ($q) => $q->where('is_resolved', false))
            ->when($state === 'resolved', static fn ($q) => $q->where('is_resolved', true))
            ->when(ErrorCategory::tryFrom((string) $request->query('category')) !== null, static fn ($q) => $q->where('category', (string) $request->query('category')))
            ->when(Severity::tryFrom((string) $request->query('severity')) !== null, static fn ($q) => $q->where('severity', (string) $request->query('severity')))
            ->when(in_array($request->query('workflow'), ['b2b', 'b2c'], true), static fn ($q) => $q->where('workflow', (string) $request->query('workflow')))
            ->when(($code = trim((string) $request->query('code', ''))) !== '', static fn ($q) => $q->where('code', $code))
            ->when(ctype_digit((string) $request->query('group', '')), static fn ($q) => $q->where('group_id', (int) $request->query('group')))
            ->orderByDesc('id')
            ->simplePaginate(50)->withQueryString();

        return view('zatca::dashboard.errors.index', [
            'errors_' => $errors,
            'state' => $state,
            'filters' => $request->only(['category', 'severity', 'workflow', 'code', 'group']),
        ]);
    }

    public function groups(Request $request): View
    {
        $this->authorizeAbility($request, Abilities::ERRORS_VIEW);
        $groups = ErrorGroup::query()->orderByDesc('last_seen_at')->paginate(50);
        // live open counts (the stored open_count can lag behind resolutions made elsewhere)
        $open = ZatcaError::query()->whereIn('group_id', $groups->pluck('id'))->where('is_resolved', false)
            ->selectRaw('group_id, COUNT(*) as aggregate')->groupBy('group_id')->pluck('aggregate', 'group_id');

        return view('zatca::dashboard.errors.groups', ['groups' => $groups, 'open' => $open]);
    }

    public function warnings(Request $request): View
    {
        $this->authorizeAbility($request, Abilities::ERRORS_VIEW);

        return view('zatca::dashboard.errors.warnings', [
            'warnings' => Warning::query()->with(['invoice:id,public_id,invoice_number'])->orderByDesc('id')->simplePaginate(50),
        ]);
    }

    public function resolve(Request $request, int $error, ErrorResolver $resolver): RedirectResponse
    {
        $this->authorizeAbility($request, Abilities::ERRORS_RESOLVE);
        $error = ZatcaError::query()->findOrFail($error);
        try {
            $resolver->resolve($error, (string) $request->input('note', ''), $this->actorId($request));
        } catch (\InvalidArgumentException $e) {
            return back()->with('zatca_error', $e->getMessage());
        }

        return back()->with('zatca_status', "Error #{$error->id} marked as resolved.");
    }

    public function resolveGroup(Request $request, int $group, ErrorResolver $resolver): RedirectResponse
    {
        $this->authorizeAbility($request, Abilities::ERRORS_RESOLVE);
        $group = ErrorGroup::query()->findOrFail($group);
        try {
            $count = $resolver->resolveGroup($group, (string) $request->input('note', ''), $this->actorId($request));
        } catch (\InvalidArgumentException $e) {
            return back()->with('zatca_error', $e->getMessage());
        }

        return back()->with('zatca_status', "{$count} error(s) of group {$group->code} marked as resolved.");
    }
}
