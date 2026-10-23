<?php

namespace App\Console\Commands;

use App\Gaspedaal\Scraper;
use App\Jobs\ComputeScores as ComputeScoresJob;
use App\Jobs\ScrapeSource;
use App\Models\Source;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('auto:scrape {source? : a source id; all active sources when left out} {--jitter : start after a random delay (the daily run)} {--sync : run here instead of on the queue}')]
#[Description('Fetch every page of the active Gaspedaal sources and store the cars')]
class ScrapeSources extends Command
{
    public function handle(Scraper $scraper): int
    {
        $sources = $this->argument('source')
            ? Source::whereKey($this->argument('source'))->get()
            : Source::where('active', true)->orderBy('id')->get();

        if ($sources->isEmpty()) {
            $this->warn('No sources to run.');

            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            foreach ($sources as $source) {
                $run = $scraper->run($source, 'manual');
                $this->line("{$source->name}: {$run->status}, {$run->listings_seen}/{$run->total_reported} cars, {$run->new_count} new, {$run->gone_count} gone".($run->error ? " — {$run->error}" : ''));
            }

            return self::SUCCESS;
        }

        // The start time varies a little from day to day.
        $delay = $this->option('jitter') ? random_int(0, config('gaspedaal.jitter_minutes') * 60) : 0;
        $trigger = $this->option('jitter') ? 'schedule' : 'manual';

        foreach ($sources->values() as $i => $source) {
            $gap = $i === 0 ? 0 : random_int(config('gaspedaal.source_gap_min'), config('gaspedaal.source_gap_max'));
            ScrapeSource::dispatch($source->id, $trigger, $gap)->delay(now()->addSeconds($delay));
        }

        // The worker takes jobs in order, so the scores come after every source.
        if (! $this->argument('source')) {
            ComputeScoresJob::dispatch()->delay(now()->addSeconds($delay));
        }

        $this->info(sprintf('%d source(s) queued, starting in %d min.', $sources->count(), intdiv($delay, 60)));

        return self::SUCCESS;
    }
}
