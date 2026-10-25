<?php

use App\Actions\IssueApiKey;
use App\Enums\Scope;
use App\Models\ApiKey;
use Illuminate\Support\Facades\DB;

it('stores only the hash and the visible prefix of a new key', function () {
    $issued = app(IssueApiKey::class)->handle('reviewer');

    expect($issued->secret)->toMatch('/^ghk_[A-Za-z0-9]{40}$/')
        ->and($issued->apiKey->prefix)->toBe(substr($issued->secret, 0, 12))
        ->and($issued->apiKey->key_hash)->toBe(hash('sha256', $issued->secret));

    $stored = json_encode(DB::table('api_keys')->get(), JSON_THROW_ON_ERROR);

    expect($stored)->not->toContain($issued->secret);
});

it('finds a key by its secret and not by a wrong one', function () {
    $issued = app(IssueApiKey::class)->handle('reviewer');

    expect(ApiKey::findBySecret($issued->secret))->toBeInstanceOf(ApiKey::class)
        ->and(ApiKey::findBySecret($issued->secret.'x'))->toBeNull()
        ->and(ApiKey::findBySecret(''))->toBeNull();
});

it('returns the existing key when issuing a known secret again', function () {
    $secret = 'ghk_'.str_repeat('b', 40);

    $first = app(IssueApiKey::class)->handle('dev', $secret);
    $second = app(IssueApiKey::class)->handle('dev', $secret);

    expect($second->apiKey->is($first->apiKey))->toBeTrue()
        ->and(ApiKey::count())->toBe(1);
});

it('stores the scopes it is given and defaults to search:read', function () {
    $default = app(IssueApiKey::class)->handle('default');
    $managing = app(IssueApiKey::class)->handle('managing', null, [Scope::SearchRead, Scope::KeysManage]);

    expect($default->apiKey->fresh()->scopes)->toBe(['search:read'])
        ->and($managing->apiKey->fresh()->scopes)->toBe(['search:read', 'keys:manage']);
});
