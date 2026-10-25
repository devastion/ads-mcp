<?php

namespace App\Actions;

use App\DTO\IssuedApiKey;
use App\Enums\Scope;
use App\Models\ApiKey;
use Illuminate\Support\Str;

class IssueApiKey
{
    /**
     * Issue a key, or return the existing one when the secret is already known.
     *
     * @param  list<Scope>  $scopes
     */
    public function handle(string $name, ?string $secret = null, array $scopes = [Scope::SearchRead]): IssuedApiKey
    {
        $secret ??= 'ghk_'.Str::random(40);

        $apiKey = ApiKey::query()->firstOrCreate(
            ['key_hash' => ApiKey::hashSecret($secret)],
            [
                'name' => $name,
                'prefix' => substr($secret, 0, 12),
                'scopes' => array_map(fn (Scope $scope): string => $scope->value, $scopes),
            ],
        );

        return new IssuedApiKey($apiKey, $secret);
    }
}
