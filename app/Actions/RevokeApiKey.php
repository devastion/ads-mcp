<?php

namespace App\Actions;

use App\Models\ApiKey;

class RevokeApiKey
{
    /**
     * Revoke a key. A key that is already revoked keeps its first revocation time.
     */
    public function handle(ApiKey $key): ApiKey
    {
        if (! $key->isRevoked()) {
            $key->forceFill(['revoked_at' => now()])->save();
        }

        return $key;
    }
}
