<?php

namespace App\Http\Controllers;

use App\Analysis\Analysis;
use App\Analysis\ListingFilter;
use App\Models\Listing;
use App\Models\SavedFilter;
use App\Models\Source;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AnalysisController extends Controller
{
    /**
     * The sortable columns of the car list: key => columns and their direction for "ascending".
     * Days online ascending means the most recently placed first.
     */
    private const array SORTS = [
        'auto' => ['listings.merk' => 'asc', 'listings.model' => 'asc', 'listings.uitvoering' => 'asc'],
        'comfort' => ['listings.comfort_niveau' => 'asc'],
        'bouwjaar' => ['listings.bouwjaar' => 'asc'],
        'km' => ['listings.kilometerstand' => 'asc'],
        'pk' => ['listings.vermogen_kw' => 'asc'],
        'prijs' => ['listings.prijs' => 'asc'],
        'online' => ['listings.datum_geplaatst' => 'desc'],
        'plaats' => ['listings.plaats' => 'asc'],
    ];

    public function index(Request $request, Analysis $analysis): Response
    {
        $filter = ListingFilter::from($request->query());
        // A column of the list, "-" in front for the other way round.
        $sort = (string) $request->query('sort', 'prijs');
        $key = ltrim($sort, '-');

        if (! array_key_exists($key, self::SORTS)) {
            [$sort, $key] = ['prijs', 'prijs'];
        }

        $descending = str_starts_with($sort, '-');

        $listings = function () use ($filter, $key, $descending) {
            $query = $filter->current();

            foreach (self::SORTS[$key] as $column => $direction) {
                // Unknown values last, whichever way round.
                $query->orderByRaw("{$column} is null");
                $query->orderBy($column, $descending ? ($direction === 'asc' ? 'desc' : 'asc') : $direction);
            }

            return $query->orderBy('listings.id')
                ->paginate(50)->withQueryString()
                ->through(fn (Listing $l) => $l->summary() + [
                    'datum_geplaatst' => $l->datum_geplaatst?->toIso8601String(),
                    'carrosserie' => $l->carrosserie,
                ]);
        };

        $compare = collect(explode(',', (string) $request->query('vergelijk', '')))->filter()->map(fn ($id) => (int) $id)->all();

        // Closures, so a partial reload (one filter changed) computes only what it asks for.
        return Inertia::render('Analysis/Index', [
            'filters' => $filter->values,
            'sort' => $sort,
            'options' => fn () => $this->options(),
            'now' => fn () => $analysis->now($filter),
            'daily' => fn () => $analysis->daily($filter),
            'listings' => $listings,
            'saved' => fn () => SavedFilter::orderBy('name')->get()->map(function (SavedFilter $saved) use ($analysis, $compare) {
                $f = ListingFilter::from($saved->filters);
                $daily = $analysis->daily($f);

                return [
                    'id' => $saved->id,
                    'name' => $saved->name,
                    'filters' => $f->values,
                    'color_slot' => $saved->color_slot,
                    'now' => $analysis->now($f),
                    'change' => $analysis->change($daily),
                    // Only the compared ones carry their series to the page.
                    'daily' => in_array($saved->id, $compare, true) ? $daily : null,
                ];
            }),
            'compare' => $compare,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'filters' => ['present', 'array'],
        ]);

        $filters = ListingFilter::from($data['filters'])->values;

        SavedFilter::create([
            'name' => $data['name'],
            'filters' => $filters,
            'color_slot' => SavedFilter::freeSlot(),
        ]);

        return back()->with('flash', "Filter \"{$data['name']}\" opgeslagen.");
    }

    public function update(Request $request, SavedFilter $savedFilter): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'filters' => ['sometimes', 'array'],
        ]);

        if (array_key_exists('filters', $data)) {
            $data['filters'] = ListingFilter::from($data['filters'])->values;
        }

        $savedFilter->update($data);

        return back()->with('flash', "Filter \"{$savedFilter->name}\" bijgewerkt.");
    }

    public function destroy(SavedFilter $savedFilter): RedirectResponse
    {
        $savedFilter->delete();

        return back()->with('flash', "Filter \"{$savedFilter->name}\" verwijderd.");
    }

    /**
     * What the selects offer: the values that occur among all cars ever seen.
     */
    private function options(): array
    {
        $distinct = fn (string $column) => DB::table('listings')->whereNotNull($column)->distinct()->orderBy($column)->pluck($column);

        return [
            'merken' => $distinct('merk'),
            'modellen' => DB::table('listings')->whereNotNull('model')->select('merk', 'model')->distinct()
                ->orderBy('merk')->orderBy('model')->get()->groupBy('merk')->map->pluck('model'),
            'bronnen' => Source::orderBy('name')->get(['id', 'name']),
            'carrosserieen' => $distinct('carrosserie'),
            'transmissies' => $distinct('transmissie'),
            'kleuren' => $distinct('kleur'),
        ];
    }
}
