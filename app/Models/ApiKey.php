<?php

namespace App\Models;

use App\Enums\Scope;
use Carbon\CarbonImmutable;
use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property list<string> $scopes
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $last_mcp_request_at
 */
#[Fillable(['name', 'prefix', 'key_hash', 'scopes'])]
class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'revoked_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'last_mcp_request_at' => 'immutable_datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function hasScope(Scope $scope): bool
    {
        return in_array($scope->value, $this->scopes, true);
    }

    public static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public static function findBySecret(string $secret): ?self
    {
        return self::query()->where('key_hash', self::hashSecret($secret))->first();
    }
}
