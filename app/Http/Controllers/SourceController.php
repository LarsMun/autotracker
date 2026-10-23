<?php

namespace App\Http\Controllers;

use App\Analysis\ListingFilter;
use App\Gaspedaal\Client;
use App\Gaspedaal\FetchException;
use App\Gaspedaal\ParseException;
use App\Gaspedaal\Recorder;
use App\Jobs\ComputeScores;
use App\Jobs\ScrapeSource;
use App\Models\Listing;
use App\Models\ScrapeRun;
use App\Models\Source;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class SourceController extends Controller
{
    /**
     * Page 1 of a search, straight from Gaspedaal: what this source would collect.
     */
    public function preview(Request $request, Client $client, Recorder $recorder): JsonResponse
    {
        $request->validate(['url' => ['required', 'string', 'max:1000']]);
        $url = $this->normalize($request->string('url'));

        // Someone is waiting: one attempt, no retries with long pauses.
        config(['gaspedaal.attempts' => 1]);

        try {
            $result = $client->fetchPage($url, 1);
        } catch (FetchException|ParseException $e) {
            return response()->json(['message' => "Ophalen mislukt: {$e->getMessage()}"], 422);
        }

        $page = $result['page'];
        $cars = array_map(function (array $o) use ($recorder, $page) {
            $a = $recorder->attributes($o, $page->ldItems[(int) $o['advertentieId']] ?? null);

            return [
                'advertentie_id' => $a['advertentie_id'],
                'title' => trim("{$a['merk']} {$a['model']}"),
                'uitvoering' => $a['uitvoering'],
                'bouwjaar' => $a['bouwjaar'],
                'kilometerstand' => $a['kilometerstand'],
                'prijs' => $a['prijs'],
                'pk' => $a['vermogen_kw'] ? (int) round($a['vermogen_kw'] * 1.36) : null,
                'brandstof' => $a['brandstof'],
                'plaats' => $a['plaats'],
                'foto' => $a['foto_url'],
                'known' => false,
            ];
        }, $page->occasions);

        $known = Listing::whereIn('advertentie_id', array_column($cars, 'advertentie_id'))->pluck('advertentie_id')->flip();

        foreach ($cars as &$car) {
            $car['known'] = isset($known[$car['advertentie_id']]);
        }

        // Gaspedaal silently widens a search with an unknown model to the whole brand; this shows it.
        $models = collect($cars)->countBy('title')->sortDesc()->all();

        return response()->json([
            'url' => $url,
            'total' => $page->totalOccasions,
            'pages' => $page->numberOfPages,
            'sort' => $page->sort,
            'models' => $models,
            'cars' => $cars,
            'existing' => Source::where('url', $url)->first(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:1000'],
            'run_now' => ['boolean'],
        ]);

        $source = Source::create([
            'name' => $data['name'],
            'url' => $this->normalize($data['url']),
            'active' => true,
        ]);

        if ($request->boolean('run_now')) {
            ScrapeSource::dispatch($source->id, 'manual');
        }

        return redirect()->route('sources.show', $source)
            ->with('flash', $request->boolean('run_now') ? 'Bron toegevoegd; de eerste run staat in de wachtrij.' : 'Bron toegevoegd; hij draait mee in de volgende dagelijkse run.');
    }

    public function show(Request $request, Source $source): Response
    {
        $source->load('lastRun.source');
        $filter = $request->query('filter', 'current');
        $sort = $request->query('sort', 'prijs');
        $search = trim((string) $request->query('q', ''));
        $day = $source->lastRun?->run_date?->toDateString();
        $goneDay = $source->lastSuccessfulRun()?->run_date?->toDateString();

        $query = $source->listings()->select('listings.*');

        match ($filter) {
            'gone' => $query->whereNotNull('listing_source.gone_at'),
            'new' => $query->where('listing_source.first_seen', $day ?? '0000-00-00'),
            'gone_today' => $query->where('listing_source.gone_at', $goneDay ?? '0000-00-00'),
            'all' => null,
            default => $query->whereNull('listing_source.gone_at'),
        };

        // Comfort and sport, the same way as on the analysis page.
        $uitrusting = ListingFilter::from($request->only(['comfort', 'sport']));

        if ($comfort = $uitrusting->values['comfort'] ?? null) {
            $query->whereIn('listings.comfort_niveau', array_map('intval', explode(',', $comfort)));
        }

        if ($sport = $uitrusting->values['sport'] ?? null) {
            $query->where('listings.sport', $sport === 'ja');
        }

        if ($search !== '') {
            $query->where(fn ($q) => $q->where('uitvoering', 'like', "%{$search}%")
                ->orWhere('plaats', 'like', "%{$search}%")
                ->orWhere('model', 'like', "%{$search}%")
                ->orWhere('advertentie_id', $search));
        }

        [$column, $direction] = match ($sort) {
            '-prijs' => ['prijs', 'desc'],
            'km' => ['kilometerstand', 'asc'],
            '-km' => ['kilometerstand', 'desc'],
            'bouwjaar' => ['bouwjaar', 'asc'],
            '-bouwjaar' => ['bouwjaar', 'desc'],
            'first_seen' => ['listing_source.first_seen', 'asc'],
            '-first_seen' => ['listing_source.first_seen', 'desc'],
            default => ['prijs', 'asc'],
        };

        $listings = $query->orderBy($column, $direction)->orderBy('listings.id')->paginate(50)->withQueryString();

        return Inertia::render('Sources/Show', [
            'source' => SourceStats::for($source),
            'listings' => $listings->through(fn (Listing $l) => $l->summary() + [
                'source_first_seen' => $l->pivot->first_seen,
                'source_gone_at' => $l->pivot->gone_at,
            ]),
            'runs' => $source->runs()->with('source')->latest('id')->limit(15)->get()->map->summary(),
            'filters' => ['filter' => $filter, 'sort' => $sort, 'q' => $search, 'comfort' => $uitrusting->values['comfort'] ?? '', 'sport' => $uitrusting->values['sport'] ?? ''],
        ]);
    }

    public function update(Request $request, Source $source): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'url' => ['sometimes', 'required', 'string', 'max:1000'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['url'])) {
            $data['url'] = $this->normalize($data['url']);

            if ($data['url'] !== $source->url) {
                $data['rebase_pending'] = true;
            }
        }

        $source->update($data);

        return back()->with('flash', ! empty($data['rebase_pending'])
            ? 'Bron bijgewerkt. De volgende volledige run ontkoppelt de auto\'s die niet meer binnen de zoekopdracht vallen; die tellen niet als verdwenen.'
            : 'Bron bijgewerkt.');
    }

    public function destroy(Source $source): RedirectResponse
    {
        $name = $source->name;
        $source->delete();

        return redirect()->route('sources.index')->with('flash', "Bron \"{$name}\" verwijderd. De auto's en hun historie blijven bewaard.");
    }

    public function run(Source $source): RedirectResponse
    {
        if ($this->busy($source)) {
            return back()->with('flash', 'Deze bron draait al.');
        }

        ScrapeSource::dispatch($source->id, 'manual');

        return back()->with('flash', "Run van \"{$source->name}\" staat in de wachtrij.");
    }

    public function runAll(): RedirectResponse
    {
        $sources = Source::where('active', true)->orderBy('id')->get();

        foreach ($sources->values() as $i => $source) {
            $gap = $i === 0 ? 0 : random_int(config('gaspedaal.source_gap_min'), config('gaspedaal.source_gap_max'));
            ScrapeSource::dispatch($source->id, 'manual', $gap);
        }

        ComputeScores::dispatch();

        return back()->with('flash', "{$sources->count()} bronnen staan in de wachtrij; ze draaien na elkaar, daarna worden de scores berekend.");
    }

    private function busy(Source $source): bool
    {
        return $source->runs()->where('status', ScrapeRun::RUNNING)->exists();
    }

    private function normalize(string $url): string
    {
        try {
            return Client::normalizeUrl($url);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }
    }
}
