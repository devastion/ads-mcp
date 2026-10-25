<?php

use App\Enums\Scope;
use App\Http\Controllers\V0\AdController;
use App\Http\Controllers\V1\AdController as V1AdController;
use App\Http\Controllers\V1\AdShowController;
use App\Http\Controllers\V1\NicheController;
use App\Http\Middleware\AuthenticateV0ApiKey;
use App\Http\Middleware\AuthenticateV1ApiKey;
use App\Http\Middleware\MeterApiUsage;
use App\Http\Middleware\RequireScope;
use App\Http\Middleware\ThrottleApiKey;
use Illuminate\Support\Facades\Route;

Route::get('v0/ads', AdController::class)
    ->middleware([AuthenticateV0ApiKey::class, ThrottleApiKey::class, RequireScope::class.':'.Scope::SearchRead->value, MeterApiUsage::class])
    ->name('api.v0.ads');

Route::get('v1/ads', V1AdController::class)
    ->middleware([AuthenticateV1ApiKey::class, ThrottleApiKey::class, RequireScope::class.':'.Scope::SearchRead->value, MeterApiUsage::class])
    ->name('api.v1.ads');

Route::get('v1/ads/{id}', AdShowController::class)
    ->middleware([AuthenticateV1ApiKey::class, ThrottleApiKey::class, RequireScope::class.':'.Scope::SearchRead->value, MeterApiUsage::class])
    ->name('api.v1.ads.show');

Route::get('v1/niches', NicheController::class)
    ->middleware([AuthenticateV1ApiKey::class, ThrottleApiKey::class, RequireScope::class.':'.Scope::SearchRead->value, MeterApiUsage::class])
    ->name('api.v1.niches');
