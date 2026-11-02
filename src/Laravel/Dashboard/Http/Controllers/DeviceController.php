<?php

declare(strict_types=1);

namespace CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers;

use CodingTriplets\Zatca\Core\Domain\Exceptions\ZatcaException;
use CodingTriplets\Zatca\Laravel\Dashboard\Abilities;
use CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers\Concerns\DashboardHelpers;
use CodingTriplets\Zatca\Laravel\Database\Models\Branch;
use CodingTriplets\Zatca\Laravel\Database\Models\Company;
use CodingTriplets\Zatca\Laravel\Database\Models\Device;
use CodingTriplets\Zatca\Laravel\Devices\CertificateRenewalService;
use CodingTriplets\Zatca\Laravel\Devices\DeviceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Devices: list, detail, register one, import many (CSV/Excel), onboard / resume / renew.
 * OTPs are taken from the request, passed straight to the service and never flashed, stored or logged.
 */
final class DeviceController
{
    use DashboardHelpers;

    public const IMPORT_MAX_KB = 5120;

    public function index(Request $request): View
    {
        $this->authorizeAbility($request, Abilities::DEVICES_VIEW);
        $status = (string) $request->query('status', '');
        $q = trim((string) $request->query('q', ''));

        $devices = Device::query()
            ->with(['company:id,name_en', 'branch:id,name,code'])
            ->when($status !== '', static fn ($query) => $query->where('lifecycle_status', $status))
            ->when(($env = (string) $request->query('environment', '')) !== '', static fn ($query) => $query->where('environment', $env))
            ->when($q !== '', static fn ($query) => $query->where(static fn ($w) => $w
                ->where('name', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%')
                ->when(\Illuminate\Support\Str::isUuid($q), static fn ($u) => $u->orWhere('device_uuid', $q))   // uuid column (PostgreSQL)
                ->orWhere('serial_number', $q)))
            ->orderBy('name')
            ->paginate(50)->withQueryString();

        return view('zatca::dashboard.devices.index', [
            'devices' => $devices,
            'filters' => $request->only(['status', 'environment', 'q']),
        ]);
    }

    public function show(Request $request, int $device): View
    {
        $this->authorizeAbility($request, Abilities::DEVICES_VIEW);
        $device = Device::query()->with(['company:id,name_en', 'branch:id,name,code', 'vatRegistration:id,vat_number,legal_name'])->findOrFail($device);

        return view('zatca::dashboard.devices.show', [
            'device' => $device,
            // explicit columns: the encrypted secret and the certificate body are never loaded
            'certificates' => $device->certificates()->orderByDesc('id')
                ->get(['id', 'environment', 'type', 'version', 'serial', 'fingerprint', 'valid_from', 'valid_until', 'status', 'activated_at', 'superseded_at']),
            'onboardings' => $device->onboardings()->orderByDesc('id')->limit(20)
                ->get(['id', 'environment', 'status', 'current_step', 'compliance_checks', 'error', 'created_at', 'completed_at']),
            'renewals' => $device->renewals()->orderByDesc('id')->limit(20)
                ->get(['id', 'environment', 'status', 'error', 'created_at', 'completed_at']),
            'counters' => $device->counters()->get(['environment', 'last_icv', 'updated_at']),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorizeAbility($request, Abilities::DEVICES_ONBOARD);

        return view('zatca::dashboard.devices.create', [
            'companies' => Company::query()->orderBy('name_en')->get(['id', 'name_en']),
            'stores' => Branch::query()->orderBy('name')->get(['id', 'company_id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, DeviceService $devices): RedirectResponse
    {
        $this->authorizeAbility($request, Abilities::DEVICES_ONBOARD);
        $row = $request->only(['company_id', 'store_id', 'name', 'invoice_types', 'industry', 'location', 'environment', 'serial', 'vat_number']);

        try {
            $device = $devices->register($row, $this->actorId($request), ['ids' => 'package']);
        } catch (ZatcaException|\InvalidArgumentException|\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return back()->withInput()->with('zatca_error', $e->getMessage());
        }

        return redirect($this->zroute('devices.show', $device->id))->with('zatca_status', "Device {$device->name} registered. Onboard it with an OTP from the Fatoora portal.");
    }

    public function importForm(Request $request): View
    {
        $this->authorizeAbility($request, Abilities::DEVICES_ONBOARD);

        return view('zatca::dashboard.devices.import', ['report' => null]);
    }

    public function import(Request $request, DeviceService $devices): View|RedirectResponse
    {
        $this->authorizeAbility($request, Abilities::DEVICES_ONBOARD);
        $file = $request->file('file');
        $extension = strtolower((string) $file?->getClientOriginalExtension());
        if ($file === null || ! $file->isValid() || ! in_array($extension, ['csv', 'txt', 'xlsx', 'ods'], true)) {
            return back()->with('zatca_error', 'Choose a .csv, .xlsx or .ods file.');
        }
        if ($file->getSize() > self::IMPORT_MAX_KB * 1024) {
            return back()->with('zatca_error', 'The file is larger than ' . (self::IMPORT_MAX_KB / 1024) . ' MB.');
        }
        $ids = in_array($request->input('ids'), ['auto', 'external', 'package'], true) ? (string) $request->input('ids') : 'auto';
        $validateOnly = $request->boolean('validate_only');

        // The reader picks the format from the extension: copy the upload to a private temp file with it.
        $temp = tempnam(sys_get_temp_dir(), 'zatca-upload-');
        $path = $temp . '.' . $extension;
        try {
            rename($temp, $path);
            @chmod($path, 0600);
            copy($file->getRealPath(), $path);
            $report = $validateOnly
                ? $devices->validateMany($path, ['ids' => $ids])
                : $devices->import($path, ['ids' => $ids, 'onboard' => $request->boolean('onboard'), 'actor_id' => $this->actorId($request)]);
        } catch (ZatcaException $e) {
            return back()->with('zatca_error', $e->getMessage());
        } finally {
            @unlink($path);
            @unlink($file->getRealPath());   // the upload may contain OTPs: do not leave it in the temp directory
        }

        return view('zatca::dashboard.devices.import', ['report' => $report, 'validateOnly' => $validateOnly]);
    }

    public function onboard(Request $request, int $device, DeviceService $devices): RedirectResponse
    {
        $this->authorizeAbility($request, Abilities::DEVICES_ONBOARD);
        $device = Device::query()->findOrFail($device);
        $otp = trim((string) $request->input('otp', ''));
        if (preg_match('/^\d{6}$/', $otp) !== 1) {
            return back()->with('zatca_error', 'The OTP has 6 digits.');   // never withInput(): the OTP must not be flashed
        }

        try {
            $result = $devices->onboard($device, $otp, $this->actorId($request));
        } catch (ZatcaException $e) {
            return back()->with('zatca_error', $e->getMessage());
        } finally {
            $otp = '';
        }

        return $this->onboardingResult($device, (string) $result->status, (string) ($result->error ?? ''));
    }

    public function resume(Request $request, int $device, DeviceService $devices): RedirectResponse
    {
        $this->authorizeAbility($request, Abilities::DEVICES_ONBOARD);
        $device = Device::query()->findOrFail($device);

        try {
            $result = $devices->resume($device, $this->actorId($request));
        } catch (ZatcaException $e) {
            return back()->with('zatca_error', $e->getMessage());
        }

        return $this->onboardingResult($device, (string) $result->status, (string) ($result->error ?? ''));
    }

    public function renew(Request $request, int $device, CertificateRenewalService $renewals): RedirectResponse
    {
        $this->authorizeAbility($request, Abilities::DEVICES_RENEW);
        $device = Device::query()->findOrFail($device);
        $otp = trim((string) $request->input('otp', ''));
        if (preg_match('/^\d{6}$/', $otp) !== 1) {
            return back()->with('zatca_error', 'The OTP has 6 digits.');
        }

        try {
            $renewal = $renewals->renew($device, $otp, $this->actorId($request));
        } catch (ZatcaException $e) {
            return back()->with('zatca_error', $e->getMessage());
        } finally {
            $otp = '';
        }

        $ok = in_array((string) $renewal->status, ['COMPLETED', 'completed'], true);

        return redirect($this->zroute('devices.show', $device->id))
            ->with($ok ? 'zatca_status' : 'zatca_error', $ok ? 'Certificate renewed.' : 'Renewal failed: ' . ($renewal->error ?? (string) $renewal->status));
    }

    private function onboardingResult(Device $device, string $status, string $error): RedirectResponse
    {
        [$key, $message] = match ($status) {
            'COMPLETED' => ['zatca_status', "Device {$device->name} is active."],
            'AWAITING_COMPLIANCE_CHECKS' => ['zatca_error', 'Compliance checks failed: ' . $error . ' Fix the cause, then resume (no new OTP needed).'],
            default => ['zatca_error', 'Onboarding failed: ' . ($error !== '' ? $error : $status)],
        };

        return redirect($this->zroute('devices.show', $device->id))->with($key, $message);
    }
}
