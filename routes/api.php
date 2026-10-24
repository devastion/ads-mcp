<?php

use App\Http\Controllers\V0\AdController;
use App\Http\Middleware\AuthenticateV0ApiKey;
use Illuminate\Support\Facades\Route;

Route::get('v0/ads', AdController::class)
    ->middleware(AuthenticateV0ApiKey::class)
    ->name('api.v0.ads');
