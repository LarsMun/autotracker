<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * For deploy/deploy.sh: recreating the queue container cuts off the job it runs, so a deploy waits
 * until there is none.
 */
#[Signature('auto:idle')]
#[Description('Exit 0 when the queue worker runs no job, 2 when it does')]
class Idle extends Command
{
    public const int BUSY = 2;

    public function handle(): int
    {
        // A reserved job is one the worker is running: a scrape, photos or a recalculation.
        $running = DB::table('jobs')->whereNotNull('reserved_at')->pluck('queue');

        if ($running->isEmpty()) {
            $this->info('Idle');

            return self::SUCCESS;
        }

        $this->line('Busy: '.$running->implode(', '));

        return self::BUSY;
    }
}
