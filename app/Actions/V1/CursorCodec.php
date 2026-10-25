<?php

namespace App\Actions\V1;

use App\Enums\ApiErrorCode;
use App\Http\Exceptions\V1\ApiErrorException;

class CursorCodec
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->base64UrlEncode($json).'.'.$this->base64UrlEncode($this->sign($json));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiErrorException
     */
    public function decode(string $cursor): array
    {
        $parts = explode('.', $cursor);

        if (count($parts) !== 2) {
            throw $this->invalid();
        }

        [$body, $signature] = $parts;
        $json = $this->base64UrlDecode($body);
        $mac = $this->base64UrlDecode($signature);

        if ($json === null || $mac === null || ! hash_equals($this->sign($json), $mac)) {
            throw $this->invalid();
        }

        $payload = json_decode($json, true);

        if (! is_array($payload) || ($payload['v'] ?? null) !== 1 || ! is_string($payload['h'] ?? null)) {
            throw $this->invalid();
        }

        return $payload;
    }

    private function sign(string $json): string
    {
        $key = hash_hkdf('sha256', (string) config('app.key'), 0, 'v1-cursor');

        return hash_hmac('sha256', $json, $key, true);
    }

    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $encoded): ?string
    {
        $padded = str_pad(strtr($encoded, '-_', '+/'), strlen($encoded) + (4 - strlen($encoded) % 4) % 4, '=');

        return base64_decode($padded, true) ?: null;
    }

    private function invalid(): ApiErrorException
    {
        return new ApiErrorException(ApiErrorCode::InvalidCursor, 'The cursor is not valid.');
    }
}
