<?php

use App\Http\Middleware\RequireTwoFactorAuthentication;
use GreyHarbour\DatabaseViewer\Http\ViewerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'auth.session', RequireTwoFactorAuthentication::class])
    ->prefix('database-viewer')->name('database-viewer.')->group(function (): void {
        Route::get('/assets/{asset}', [ViewerController::class, 'asset'])
            ->where('asset', '(bridge|viewer)\.mjs')->name('asset');
        Route::get('/servers/{server}/databases/{database}', [ViewerController::class, 'show'])
            ->whereNumber('database')->name('show');
        Route::post('/servers/{server}/databases/{database}/query', [ViewerController::class, 'query'])
            ->whereNumber('database')->middleware('throttle:30,1')->name('query');
        Route::post('/servers/{server}/databases/{database}/ai', [ViewerController::class, 'ai'])
            ->whereNumber('database')->middleware('throttle:10,1')->name('ai');
        Route::post('/servers/{server}/databases/{database}/session/extend', [ViewerController::class, 'extendSession'])
            ->whereNumber('database')->middleware('throttle:30,1')->name('session.extend');
        Route::post('/servers/{server}/databases/{database}/session/close', [ViewerController::class, 'closeSession'])
            ->whereNumber('database')->middleware('throttle:30,1')->name('session.close');
    });
