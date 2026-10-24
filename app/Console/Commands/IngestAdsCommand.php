<?php

namespace App\Console\Commands;

use App\Actions\IngestAds;
use App\Models\Ad;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Random\Randomizer;

#[Signature('ads:ingest {--ticks=0 : Stop after this many ticks; 0 runs until SIGINT or SIGTERM}')]
#[Description('Continuously discover new ads and deactivate running ones, one tick per second')]
class IngestAdsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(IngestAds $ingest): int
    {
        if (Ad::query()->doesntExist()) {
            $this->error('No ads to ingest from. Run php artisan db:seed first.');

            return self::FAILURE;
        }

        $stopRequested = false;
        $this->trap([SIGINT, SIGTERM], function () use (&$stopRequested): void {
            $stopRequested = true;
        });

        $limit = (int) $this->option('ticks');
        $ticks = 0;

        do {
            $result = $ingest->handle(new Randomizer);

            $this->line(sprintf(
                '+%d ads (%d backdated), -%d deactivated',
                $result->inserted,
                $result->backdated,
                $result->deactivated,
            ));

            $ticks++;

            if ($stopRequested || ($limit > 0 && $ticks >= $limit)) {
                break;
            }

            Sleep::for(1)->second();
        } while (true);

        return self::SUCCESS;
    }
}
