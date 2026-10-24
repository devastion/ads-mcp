<?php

namespace App\Console\Commands;

use App\Models\Ad;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ads:seed-if-empty')]
#[Description('Seed the deterministic ads only when the ads table is empty')]
class SeedAdsIfEmpty extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (Ad::query()->exists()) {
            $this->info('Ads already seeded; leaving existing data untouched.');

            return self::SUCCESS;
        }

        $this->call('db:seed', ['--force' => true]);

        return self::SUCCESS;
    }
}
