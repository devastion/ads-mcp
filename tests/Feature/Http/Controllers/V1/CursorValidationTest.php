<?php

use App\Actions\V1\CursorCodec;

function base64UrlEncodeForCursorTest(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

it('returns invalid_cursor for a malformed cursor', function () {
    v1Search('cursor=not-a-cursor')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_cursor')
        ->assertJsonPath('error.retryable', false);
});

it('returns invalid_cursor for a cursor whose payload was tampered with', function () {
    $codec = app(CursorCodec::class);
    $fingerprint = hash('sha256', json_encode(['q' => null, 'niche' => [], 'format' => null, 'status' => null, 'started_after' => null, 'sort' => 'newest'], JSON_THROW_ON_ERROR));
    [, $signature] = explode('.', $codec->encode(['v' => 1, 'h' => $fingerprint, 'c' => 1, 'k' => 'x', 'i' => 1]));
    $tampered = base64UrlEncodeForCursorTest(json_encode(['v' => 1, 'h' => $fingerprint, 'c' => 999, 'k' => 'x', 'i' => 1], JSON_THROW_ON_ERROR)).'.'.$signature;

    v1Search('cursor='.urlencode($tampered))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_cursor')
        ->assertJsonPath('error.retryable', false);
});

it('returns cursor_mismatch for a cursor issued for a different filter or sort', function () {
    $codec = app(CursorCodec::class);
    $cursor = $codec->encode(['v' => 1, 'h' => hash('sha256', 'another query'), 'c' => 1, 'k' => 'x', 'i' => 1]);

    v1Search('q=serum&sort=newest&cursor='.urlencode($cursor))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'cursor_mismatch')
        ->assertJsonPath('error.retryable', false)
        ->assertJsonPath('error.next_step', 'Request a fresh cursor and try again.');
});
