<?php

use HarbourmasterSam\ServerLifecycle\Http\Controllers\ArchiveDownloadController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'signed'])->get('/server-lifecycle/archives/{archive}/download', ArchiveDownloadController::class)->name('server-lifecycle.archives.download');
