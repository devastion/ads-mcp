<?php

use App\Actions\V1\CursorCodec;
use App\Enums\ApiErrorCode;
use App\Http\Exceptions\V1\ApiErrorException;
use Tests\TestCase;

uses(TestCase::class);

function base64UrlForTest(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

it('round-trips a payload through encode and decode', function () {
    $codec = app(CursorCodec::class);
    $payload = ['v' => 1, 'h' => 'abc', 'c' => 500, 'k' => '2026-10-01', 'i' => 42];

    expect($codec->decode($codec->encode($payload)))->toBe($payload);
});

it('rejects a cursor whose payload was changed', function () {
    $codec = app(CursorCodec::class);
    [, $signature] = explode('.', $codec->encode(['v' => 1, 'h' => 'abc', 'c' => 1, 'k' => 'x', 'i' => 1]));
    $tamperedBody = base64UrlForTest('{"v":1,"h":"abc","c":999,"k":"x","i":1}');

    expect(fn () => $codec->decode($tamperedBody.'.'.$signature))
        ->toThrow(fn (ApiErrorException $e) => $e->code() === ApiErrorCode::InvalidCursor);
});

it('rejects a cursor signed with a different app key', function () {
    $codec = app(CursorCodec::class);
    $cursor = $codec->encode(['v' => 1, 'h' => 'abc', 'c' => 1, 'k' => 'x', 'i' => 1]);

    config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);

    expect(fn () => app(CursorCodec::class)->decode($cursor))
        ->toThrow(fn (ApiErrorException $e) => $e->code() === ApiErrorCode::InvalidCursor);
});

it('rejects malformed cursors', function (string $cursor) {
    expect(fn () => app(CursorCodec::class)->decode($cursor))
        ->toThrow(fn (ApiErrorException $e) => $e->code() === ApiErrorCode::InvalidCursor);
})->with([
    'no separator' => ['not-a-cursor'],
    'too many parts' => ['a.b.c'],
    'empty' => [''],
    'bad base64 body' => ['!!!.'.base64UrlForTest('x')],
    'valid base64 but not json' => [base64UrlForTest('nope').'.'.base64UrlForTest('x')],
]);
