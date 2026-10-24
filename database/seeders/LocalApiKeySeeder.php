<?php

namespace Database\Seeders;

use App\Actions\IssueApiKey;
use Illuminate\Database\Seeder;

class LocalApiKeySeeder extends Seeder
{
    /**
     * A fixed key for local development only. It is documented in README.md and must never be used elsewhere.
     */
    public const string SECRET = 'ghk_LocalDevOnlyNotForProd000000000000000000';

    /**
     * Create the local development key, idempotently.
     */
    public function run(): void
    {
        app(IssueApiKey::class)->handle('Local development', self::SECRET);
    }
}
