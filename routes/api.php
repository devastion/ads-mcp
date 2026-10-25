<?php

use App\Http\Controllers\V0\AdController;
use App\Http\Controllers\V1\AdController as V1AdController;
use App\Http\Middleware\AuthenticateV0ApiKey;
use App\Http\Middleware\AuthenticateV1ApiKey;
use Illuminate\Support\Facades\Route;

Route::get('v0/ads', AdController::class)
    ->middleware(AuthenticateV0ApiKey::class)
    ->name('api.v0.ads');

Route::get('v1/ads', V1AdController::class)
    ->middleware(AuthenticateV1ApiKey::class)
    ->name('api.v1.ads');
