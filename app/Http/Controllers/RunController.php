<?php

namespace App\Http\Controllers;

use App\Models\ScrapePage;
use App\Models\ScrapeRun;
use Inertia\Inertia;
use Inertia\Response;

class RunController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Runs/Index', [
            'runs' => ScrapeRun::with('source')->latest('id')->paginate(50)
                ->through(fn (ScrapeRun $r) => $r->summary()),
        ]);
    }

    public function show(ScrapeRun $run): Response
    {
        $run->load('source');

        return Inertia::render('Runs/Show', [
            'run' => $run->summary() + ['url' => $run->url],
            'pages' => $run->pages()->orderBy('id')->get()->map(fn (ScrapePage $p) => [
                'page_number' => $p->page_number,
                'facet' => $p->facet,
                'http_status' => $p->http_status,
                'bytes' => $p->bytes,
                'path' => $p->path,
                'occasions_count' => $p->occasions_count,
                'ld_matches' => $p->ld_matches,
                'attempts' => $p->attempts,
                'error' => $p->error,
                'fetched_at' => $p->fetched_at?->toIso8601String(),
            ]),
        ]);
    }
}
