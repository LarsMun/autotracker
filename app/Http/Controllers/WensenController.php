<?php

namespace App\Http\Controllers;

use App\Jobs\RecomputeMatches;
use App\Models\Listing;
use App\Recommend\Scores;
use App\Recommend\Wensen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WensenController extends Controller
{
    /** How many cars the live preview shows. */
    public const int TOP = 5;

    public function edit(): Response
    {
        $wensen = Wensen::get();

        // Every make and model online now, with how many cars each.
        $models = DB::table('listings')
            ->whereExists(fn ($q) => $q->from('listing_source')->whereColumn('listing_source.listing_id', 'listings.id')->whereNull('listing_source.gone_at'))
            ->whereNotNull('merk')
            ->selectRaw('merk, model, count(*) as n')
            ->groupBy('merk', 'model')->orderBy('merk')->orderBy('model')->get()
            ->groupBy('merk')
            ->map(fn ($rows, $merk) => [
                'merk' => $merk,
                'n' => (int) $rows->sum('n'),
                'modellen' => $rows->whereNotNull('model')->map(fn ($r) => ['model' => $r->model, 'n' => (int) $r->n])->values()->all(),
            ])->values();

        $colours = DB::table('listings')
            ->whereExists(fn ($q) => $q->from('listing_source')->whereColumn('listing_source.listing_id', 'listings.id')->whereNull('listing_source.gone_at'))
            ->whereNotNull('kleur')
            ->selectRaw('kleur, count(*) as n')->groupBy('kleur')->orderByDesc('n')->get()
            ->map(fn ($c) => ['kleur' => $c->kleur, 'n' => (int) $c->n]);

        return Inertia::render('Wensen/Edit', [
            'wensen' => $wensen,
            'merken' => $models,
            'kleuren' => $colours,
            'standaard' => Wensen::defaults(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Wensen::save($this->wensen($request));

        RecomputeMatches::start();

        return redirect()->route('voor-jou')->with('flash', 'Wensen opgeslagen; de scores worden bijgewerkt.');
    }

    /**
     * The top 5 these (unsaved) wensen would give, and how each car moved against the saved ranking.
     */
    public function preview(Request $request, Scores $scores): JsonResponse
    {
        $result = $scores->preview($this->wensen($request));
        $top = array_slice($result['rows'], 0, self::TOP, true);

        $day = DB::table('match_scores')->max('date');
        $saved = $day ? DB::table('match_scores')->where('date', $day)->whereIn('listing_id', array_keys($top))->pluck('rang', 'listing_id')->all() : [];
        $listings = Listing::whereKey(array_keys($top))->get()->keyBy('id');

        return response()->json([
            'voldoen' => $result['passing'],
            'top' => collect($top)->map(function ($row, $id) use ($listings, $saved) {
                $was = $saved[$id] ?? null;
                $car = $listings[$id]->summary();

                return [
                    'id' => $id,
                    'rang' => $row['rang'],
                    'cijfer' => $row['cijfer'],
                    // Places up (positive) or down against the saved ranking; null: not in it yet.
                    'verschuiving' => $was === null ? null : $was - $row['rang'],
                    'car' => array_intersect_key($car, array_flip(['title', 'bouwjaar', 'kilometerstand', 'prijs', 'foto'])),
                ];
            })->values(),
        ]);
    }

    private function wensen(Request $request): array
    {
        $data = $request->validate([
            'filters.prijs_min' => ['required', 'integer', 'min:0'],
            'filters.prijs_max' => ['required', 'integer', 'min:1', 'gte:filters.prijs_min'],
            'filters.km_max' => ['required', 'integer', 'min:1'],
            'filters.bouwjaar_min' => ['required', 'integer', 'min:1900'],
            'filters.merken' => ['array'],
            'filters.merken.*' => ['string', 'max:100'],
            'filters.modellen' => ['array'],
            'filters.modellen.*' => ['string', 'max:200'],
            'filters.kleuren' => ['array'],
            'filters.kleuren.*' => ['string', 'max:50'],
            'filters.onbekende_kleur' => ['boolean'],
            'filters.sport' => ['required', Rule::in(Wensen::SPORT)],
            'filters.alleen_merkdealer' => ['boolean'],
            'vakken' => ['required', 'array'],
            'vakken.*.belang' => ['required', 'integer', Rule::in(Wensen::BELANG)],
            // What earns a 10 must lie beyond the 6, on the good side.
            'vakken.deal.tien' => ['required', 'numeric', 'gt:0', 'max:50'],
            'vakken.km.tien' => ['required', 'integer', 'min:0', 'lt:filters.km_max'],
            'vakken.bouwjaar.tien' => ['required', 'integer', 'gt:filters.bouwjaar_min'],
            'vakken.vermogen.min' => ['required', 'integer', 'min:1'],
            'vakken.vermogen.tien' => ['required', 'integer', 'gt:vakken.vermogen.min'],
        ], [
            'vakken.km.tien.lt' => 'Het aantal km voor een 10 moet onder je maximum liggen.',
            'vakken.bouwjaar.tien.gt' => 'Het bouwjaar voor een 10 moet na je minimale bouwjaar liggen.',
            'vakken.vermogen.tien.gt' => 'Het vermogen voor een 10 moet boven het vermogen voor een 6 liggen.',
            'filters.prijs_max.gte' => 'De maximale prijs moet minstens de minimale prijs zijn.',
        ]);

        $wensen = array_replace_recursive(Wensen::defaults(), $data);
        $wensen['vakken'] = array_intersect_key($wensen['vakken'], array_flip(Wensen::VAKKEN));
        $wensen['vakken']['deal']['tien'] = (float) $wensen['vakken']['deal']['tien'];

        // Lists replace, they do not merge by index. A model counts only with its make chosen.
        $f = &$wensen['filters'];
        $f['merken'] = array_values(array_unique($data['filters']['merken'] ?? []));
        $f['modellen'] = array_values(array_filter(array_unique($data['filters']['modellen'] ?? []),
            fn ($m) => str_contains($m, '|') && in_array(explode('|', $m, 2)[0], $f['merken'], true)));
        $f['kleuren'] = array_values(array_unique($data['filters']['kleuren'] ?? []));
        $f['onbekende_kleur'] = (bool) $f['onbekende_kleur'];
        $f['alleen_merkdealer'] = (bool) $f['alleen_merkdealer'];

        return $wensen;
    }
}
