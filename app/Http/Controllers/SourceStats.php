<?php

namespace App\Http\Controllers;

use App\Models\ScrapeRun;
use App\Models\Source;
use Illuminate\Support\Facades\DB;

/**
 * Counts per source: cars it has now, and what changed in its latest run.
 */
class SourceStats
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Source $source): array
    {
        $last = $source->lastRun;
        $lastSuccess = $source->lastSuccessfulRun();
        $links = DB::table('listing_source')->where('source_id', $source->id);
        $day = $last?->run_date?->toDateString();

        return [
            'id' => $source->id,
            'name' => $source->name,
            'url' => $source->url,
            'active' => $source->active,
            'current' => (clone $links)->whereNull('gone_at')->count(),
            'ever' => (clone $links)->count(),
            // Relative to the latest run: "since yesterday" when it runs daily.
            'new' => $day ? (clone $links)->where('first_seen', $day)->count() : 0,
            'gone' => $lastSuccess ? (clone $links)->where('gone_at', $lastSuccess->run_date->toDateString())->count() : 0,
            'last_run' => $last?->summary(),
            'last_success_at' => $lastSuccess?->finished_at?->toIso8601String(),
        ];
    }
}
