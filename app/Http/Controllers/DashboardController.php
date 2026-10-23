<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Models\Observation;
use App\Models\ScrapeRun;
use App\Models\Source;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $sources = Source::with('lastRun.source')->orderByDesc('active')->orderBy('name')->get();

        return Inertia::render('Dashboard', [
            'sources' => $sources->map(fn (Source $s) => SourceStats::for($s)),
            'totals' => [
                'listings' => Listing::count(),
                // Cars a source still follows; unlinked cars (after a URL change) are not counted.
                'current' => DB::table('listing_source')->whereNull('gone_at')->distinct()->count('listing_id'),
                'observations' => Observation::count(),
                'days' => Observation::distinct()->count('date'),
            ],
            'runs' => ScrapeRun::with('source')->latest('id')->limit(12)->get()->map->summary(),
        ]);
    }
}
