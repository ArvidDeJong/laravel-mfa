<?php

use Darvis\Mfa\Http\Controllers\LinkController;
use Darvis\Mfa\Support\MfaConfig;
use Illuminate\Support\Facades\Route;

Route::middleware(MfaConfig::linkMiddleware())
    ->prefix(MfaConfig::linkPrefix())
    ->group(function () {
        Route::get('link/{token}', [LinkController::class, 'show'])->name('mfa.link');
        Route::post('link/{token}', [LinkController::class, 'store'])->name('mfa.link.confirm');
    });
