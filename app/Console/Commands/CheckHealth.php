<?php

namespace App\Console\Commands;

use App\Monitoring\Monitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('auto:health')]
#[Description('Close abandoned runs and push the collection status to the monitor')]
class CheckHealth extends Command
{
    public function handle(Monitor $monitor): int
    {
        if ($closed = $monitor->closeAbandonedRuns()) {
            $this->warn("{$closed} abandoned run(s) marked as failed.");
        }

        $problems = $monitor->problems();

        foreach ($problems as $problem) {
            $this->error("{$problem['source']}: {$problem['problem']}");
        }

        if ($problems === []) {
            $this->info('OK');
        }

        $monitor->report();

        return self::SUCCESS;
    }
}
