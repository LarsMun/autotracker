<?php

namespace App\Jobs;

use App\Recommend\Scores;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued behind the daily run's sources on the same queue, so it starts when they are done.
 */
class ComputeScores implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 1200;

    public function __construct()
    {
        $this->onQueue('scrape');
    }

    public function handle(Scores $scores): void
    {
        $scores->run();
    }
}
