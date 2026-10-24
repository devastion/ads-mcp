<?php

namespace App\Console\Commands;

use App\Actions\IssueApiKey;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('api-key:create {name : A label for the key}')]
#[Description('Create an API key and print its secret once')]
class CreateApiKey extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(IssueApiKey $issueApiKey): int
    {
        $issued = $issueApiKey->handle((string) $this->argument('name'));

        $this->info('API key created. Copy it now: it cannot be shown again.');
        $this->line($issued->secret);

        return self::SUCCESS;
    }
}
