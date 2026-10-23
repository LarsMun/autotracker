<?php

namespace App\Monitoring;

use App\Models\ScrapeRun;
use App\Models\Source;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Whether data collection is healthy, shown on the dashboard and pushed to Uptime Kuma.
 */
class Monitor
{
    /**
     * @return list<array{source_id: int, source: string, problem: string}>
     */
    public function problems(): array
    {
        $problems = [];
        $staleBefore = now()->subHours(config('gaspedaal.stale_hours'));

        foreach (Source::where('active', true)->with('lastRun')->get() as $source) {
            $last = $source->lastRun;

            if ($last === null) {
                continue; // just added; the next daily run picks it up
            }

            if ($last->status === ScrapeRun::FAILED) {
                $problems[] = $this->problem($source, "Laatste run mislukt: {$last->error}");
            } elseif ($last->status === ScrapeRun::INCOMPLETE) {
                $problems[] = $this->problem($source, "Laatste run onvolledig: {$last->error}");
            } elseif ($last->status === ScrapeRun::SUCCESS && $last->listings_seen === 0) {
                $problems[] = $this->problem($source, "Laatste run leverde 0 auto's op.");
            }

            $lastSuccess = $source->lastSuccessfulRun();

            if ($lastSuccess === null || $lastSuccess->finished_at < $staleBefore) {
                $when = $lastSuccess?->finished_at?->diffForHumans() ?? 'nooit';
                $problems[] = $this->problem($source, "Geen geslaagde run in de laatste ".config('gaspedaal.stale_hours')." uur (laatste: {$when}).");
            }
        }

        return $problems;
    }

    /**
     * Runs that have been "running" for hours died with their worker; they count as failed.
     */
    public function closeAbandonedRuns(): int
    {
        return ScrapeRun::where('status', ScrapeRun::RUNNING)
            ->where('started_at', '<', now()->subHours(2))
            ->update([
                'status' => ScrapeRun::FAILED,
                'error' => 'De run is afgebroken (worker gestopt of time-out).',
                'finished_at' => now(),
            ]);
    }

    /**
     * Pushes the current state to the Uptime Kuma push monitor, when one is configured.
     */
    public function report(): void
    {
        $url = config('gaspedaal.push_url');

        if (! $url) {
            return;
        }

        $problems = $this->problems();
        $message = $problems === []
            ? 'OK'
            : implode(' | ', array_map(fn ($p) => "{$p['source']}: {$p['problem']}", $problems));

        try {
            Http::timeout(10)->get($url, [
                'status' => $problems === [] ? 'up' : 'down',
                'msg' => mb_substr($message, 0, 500),
            ]);
        } catch (Throwable $e) {
            Log::warning("Monitor push failed: {$e->getMessage()}");
        }
    }

    private function problem(Source $source, string $problem): array
    {
        return ['source_id' => $source->id, 'source' => $source->name, 'problem' => $problem];
    }
}
