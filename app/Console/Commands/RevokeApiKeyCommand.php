<?php

namespace App\Console\Commands;

use App\Actions\RevokeApiKey;
use App\Models\ApiKey;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('api-key:revoke {prefix : The visible prefix of the key}')]
#[Description('Revoke an API key so its next call fails')]
class RevokeApiKeyCommand extends Command
{
    public function handle(RevokeApiKey $revokeApiKey): int
    {
        $key = ApiKey::query()->where('prefix', (string) $this->argument('prefix'))->first();

        if ($key === null) {
            $this->error('No API key has that prefix.');

            return self::FAILURE;
        }

        $revokeApiKey->handle($key);
        $this->info('API key '.$key->prefix.' is revoked.');

        return self::SUCCESS;
    }
}
