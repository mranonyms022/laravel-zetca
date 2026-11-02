<?php

declare(strict_types=1);

use CodingTriplets\Zatca\Laravel\Dashboard\Abilities;
use CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers\AssetController;
use CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers\CompanyController;
use CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers\DashboardController;
use CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers\DeviceController;
use CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers\ErrorController;
use CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers\InvoiceController;
use CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers\OperationsController;
use Illuminate\Support\Facades\Route;

// Dashboard (zatca.ui.enabled). Your authentication comes from zatca.ui.middleware; every route also checks a
// zatca.* ability (see ARCHITECTURE §17 / the "Dashboard" docs page).
$group = Route::prefix((string) config('zatca.ui.prefix', 'admin/zatca'))
    ->name((string) config('zatca.ui.route_name_prefix', 'zatca.'))
    ->middleware((array) config('zatca.ui.middleware', ['web', 'auth']));
if (($domain = config('zatca.ui.domain')) !== null && $domain !== '') {
    $group->domain((string) $domain);
}

$group->group(static function (): void {
    // Static CSS only; no authentication needed and nothing sensitive.
    Route::get('assets/{file}', AssetController::class)->name('asset')->withoutMiddleware(['auth'])->where('file', '[a-z0-9.-]+');

    Route::get('/', DashboardController::class)->name('home')->middleware('zatca.can:' . Abilities::DASHBOARD_VIEW);

    Route::get('invoices/{workflow}', [InvoiceController::class, 'index'])->name('invoices.index')
        ->where('workflow', 'b2b|b2c')
        ->middleware('zatca.can:' . Abilities::DASHBOARD_VIEW);
    Route::get('invoice/{publicId}', [InvoiceController::class, 'show'])->name('invoices.show')
        ->where('publicId', '[0-9A-Za-z]{26}');
    Route::post('invoice/{publicId}/{action}', [InvoiceController::class, 'action'])->name('invoices.action')
        ->where(['publicId' => '[0-9A-Za-z]{26}', 'action' => '[a-z]+']);
    Route::get('invoice/{publicId}/artifact/{artifactId}', [InvoiceController::class, 'artifact'])->name('invoices.artifact')
        ->where(['publicId' => '[0-9A-Za-z]{26}', 'artifactId' => '[0-9]+']);

    // Devices (abilities are checked in the controllers: view / onboard / renew)
    Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::get('devices/new', [DeviceController::class, 'create'])->name('devices.create');
    Route::post('devices', [DeviceController::class, 'store'])->name('devices.store');
    Route::get('devices/import', [DeviceController::class, 'importForm'])->name('devices.import');
    Route::post('devices/import', [DeviceController::class, 'import'])->name('devices.import.run');
    Route::get('devices/{device}', [DeviceController::class, 'show'])->name('devices.show')->whereNumber('device');
    Route::post('devices/{device}/onboard', [DeviceController::class, 'onboard'])->name('devices.onboard')->whereNumber('device');
    Route::post('devices/{device}/resume', [DeviceController::class, 'resume'])->name('devices.resume')->whereNumber('device');
    Route::post('devices/{device}/renew', [DeviceController::class, 'renew'])->name('devices.renew')->whereNumber('device');

    Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');
    Route::post('companies/{company}/stores', [CompanyController::class, 'storeStore'])->name('companies.stores.store')->whereNumber('company');

    Route::get('errors', [ErrorController::class, 'index'])->name('errors.index');
    Route::get('errors/groups', [ErrorController::class, 'groups'])->name('errors.groups');
    Route::get('errors/warnings', [ErrorController::class, 'warnings'])->name('errors.warnings');
    Route::post('errors/{error}/resolve', [ErrorController::class, 'resolve'])->name('errors.resolve')->whereNumber('error');
    Route::post('errors/groups/{group}/resolve', [ErrorController::class, 'resolveGroup'])->name('errors.groups.resolve')->whereNumber('group');

    Route::get('queue', [OperationsController::class, 'queue'])->name('queue');
    Route::get('audit', [OperationsController::class, 'audit'])->name('audit');
    Route::post('audit/verify', [OperationsController::class, 'verifyAudit'])->name('audit.verify');
    Route::get('settings', [OperationsController::class, 'settings'])->name('settings');
});
