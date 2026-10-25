<?php

use App\Enums\Scope;
use App\Models\ApiKey;

it('defaults a new key to the search:read scope only', function () {
    $key = ApiKey::factory()->create()->fresh();

    expect($key->scopes)->toBe(['search:read'])
        ->and($key->hasScope(Scope::SearchRead))->toBeTrue()
        ->and($key->hasScope(Scope::KeysManage))->toBeFalse();
});

it('reports the scopes it was given', function () {
    $key = ApiKey::factory()->create(['scopes' => ['keys:manage']])->fresh();

    expect($key->hasScope(Scope::KeysManage))->toBeTrue()
        ->and($key->hasScope(Scope::SearchRead))->toBeFalse();
});

it('is revoked exactly when revoked_at is set', function () {
    $key = ApiKey::factory()->create();

    expect($key->isRevoked())->toBeFalse();

    $key->forceFill(['revoked_at' => now()])->save();

    expect($key->fresh()->isRevoked())->toBeTrue();
});
