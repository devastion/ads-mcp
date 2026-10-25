<?php

namespace App\Console\Commands;

use App\Actions\IssueApiKey;
use App\Enums\Scope;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('api-key:create {name : A label for the key} {--scope=* : Scope to grant (search:read, keys:manage); defaults to search:read}')]
#[Description('Create an API key and print its secret once')]
class CreateApiKey extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(IssueApiKey $issueApiKey): int
    {
        $names = array_map(strval(...), (array) $this->option('scope'));
        $scopes = [];

        foreach ($names === [] ? [Scope::SearchRead->value] : $names as $name) {
            $scope = Scope::tryFrom($name);

            if ($scope === null) {
                $this->error('Unknown scope "'.$name.'". Valid scopes: '.implode(', ', array_column(Scope::cases(), 'value')));

                return self::FAILURE;
            }

            $scopes[] = $scope;
        }

        $issued = $issueApiKey->handle((string) $this->argument('name'), null, $scopes);

        $this->info('API key created. Copy it now: it cannot be shown again.');
        $this->line($issued->secret);

        return self::SUCCESS;
    }
}
