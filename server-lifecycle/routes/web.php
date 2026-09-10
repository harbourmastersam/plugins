<?php

use HarbourmasterSam\ServerLifecycle\Http\Controllers\ArchiveDownloadController;
use HarbourmasterSam\ServerLifecycle\Http\Controllers\FinalArchiveDownloadController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->get('/server-lifecycle/archives/{archive}/download', ArchiveDownloadController::class)->name('server-lifecycle.archives.download');
Route::middleware(['web', 'signed'])->get('/server-lifecycle/final-delivery/{archive}', FinalArchiveDownloadController::class)->name('server-lifecycle.archives.final-download');
