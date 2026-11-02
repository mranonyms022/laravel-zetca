<?php

declare(strict_types=1);

use CodingTriplets\Zatca\Laravel\Dashboard\Abilities;
use CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers\AssetController;
use CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers\DashboardController;
use CodingTriplets\Zatca\Laravel\Dashboard\Http\Controllers\InvoiceController;
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
});
