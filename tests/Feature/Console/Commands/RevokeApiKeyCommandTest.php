<?php

use App\Actions\IssueApiKey;

it('revokes a key by its prefix', function () {
    $key = app(IssueApiKey::class)->handle('by prefix');

    $this->artisan('api-key:revoke', ['prefix' => $key->apiKey->prefix])->assertSuccessful();

    expect($key->apiKey->fresh()->isRevoked())->toBeTrue();
});

it('fails for an unknown prefix without revoking anything', function () {
    $key = app(IssueApiKey::class)->handle('survivor');

    $this->artisan('api-key:revoke', ['prefix' => 'ghk_nothing00'])
        ->expectsOutputToContain('No API key')
        ->assertFailed();

    expect($key->apiKey->fresh()->isRevoked())->toBeFalse();
});
