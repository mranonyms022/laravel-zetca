<?php

declare(strict_types=1);

use CodingTriplets\Zatca\Laravel\Http\Controllers\InvoiceApiController;
use CodingTriplets\Zatca\Laravel\Http\Middleware\ShedLoad;
use Illuminate\Support\Facades\Route;

// Optional (zatca.api.enabled). Protect it with your own auth middleware in zatca.api.middleware.
Route::prefix((string) config('zatca.api.prefix', 'api/zatca'))
    ->middleware(array_merge((array) config('zatca.api.middleware', ['api']), [ShedLoad::class]))
    ->group(static function (): void {
        Route::post('b2c/invoices', [InvoiceApiController::class, 'b2c'])->name('zatca.api.b2c');
        Route::post('b2b/invoices', [InvoiceApiController::class, 'b2b'])->name('zatca.api.b2b');
        Route::get('invoices/{publicId}', [InvoiceApiController::class, 'show'])->name('zatca.api.show');
        Route::post('invoices/{publicId}/credit-note', [InvoiceApiController::class, 'creditNote'])->name('zatca.api.credit_note');
        Route::post('invoices/{publicId}/debit-note', [InvoiceApiController::class, 'debitNote'])->name('zatca.api.debit_note');
        Route::post('invoices/{publicId}/cancel', [InvoiceApiController::class, 'cancel'])->name('zatca.api.cancel');
    });
