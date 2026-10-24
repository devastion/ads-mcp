<?php

namespace App\Models;

use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'prefix', 'key_hash'])]
class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory;

    public static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public static function findBySecret(string $secret): ?self
    {
        return self::query()->where('key_hash', self::hashSecret($secret))->first();
    }
}
