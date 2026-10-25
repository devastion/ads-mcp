<?php

use App\Actions\IssueApiKey;
use App\Actions\RevokeApiKey;
use Illuminate\Support\Carbon;

it('revokes a key and keeps the first revocation time when revoked again', function () {
    $key = app(IssueApiKey::class)->handle('doomed')->apiKey;

    $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));
    app(RevokeApiKey::class)->handle($key);

    $this->travelTo(Carbon::parse('2026-10-26 12:00:00'));
    $again = app(RevokeApiKey::class)->handle($key->fresh());

    expect($again->isRevoked())->toBeTrue()
        ->and($again->fresh()->revoked_at->toDateTimeString())->toBe('2026-10-25 12:00:00');
});
