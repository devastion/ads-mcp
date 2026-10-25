<?php

use App\Http\Controllers\Developer\DeveloperController;
use App\Http\Middleware\EnsureLocalEnvironment;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('welcome', ['isLocal' => app()->isLocal()]))->name('home');

Route::middleware(EnsureLocalEnvironment::class)->prefix('developer')->group(function (): void {
    Route::get('/', [DeveloperController::class, 'index'])->name('developer.index');
});
