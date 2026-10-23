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

        // Every model online now, in the order of preference, the rest after.
        $models = DB::table('listings')
            ->whereExists(fn ($q) => $q->from('listing_source')->whereColumn('listing_source.listing_id', 'listings.id')->whereNull('listing_source.gone_at'))
            ->selectRaw('merk, model, count(*) as n')
            ->groupBy('merk', 'model')->orderBy('merk')->orderBy('model')->get()
            ->map(fn ($m) => ['key' => "{$m->merk}|{$m->model}", 'naam' => "{$m->merk} {$m->model}", 'n' => (int) $m->n]);

        $order = array_flip($wensen['modellen']);
        $models = $models->sortBy(fn ($m) => [isset($order[$m['key']]) ? 0 : 1, $order[$m['key']] ?? 0, $m['naam']])->values();

        $colours = DB::table('listings')
            ->whereExists(fn ($q) => $q->from('listing_source')->whereColumn('listing_source.listing_id', 'listings.id')->whereNull('listing_source.gone_at'))
            ->whereNotNull('kleur')
            ->selectRaw('kleur, count(*) as n')->groupBy('kleur')->orderByDesc('n')->get()
            ->map(fn ($c) => ['kleur' => $c->kleur, 'n' => (int) $c->n]);

        return Inertia::render('Wensen/Edit', [
            'wensen' => $wensen,
            'modellen' => $models,
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
                    'totaalscore' => $row['totaalscore'],
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
            'eisen.prijs_max' => ['required', 'integer', 'min:1'],
            'eisen.km_max' => ['required', 'integer', 'min:1'],
            'eisen.km_ruimer' => ['boolean'],
            'eisen.bouwjaar_min' => ['required', 'integer', 'min:1'],
            'eisen.bouwjaar_ruimer' => ['boolean'],
            'eisen.uitsluiten' => ['array'],
            'eisen.uitsluiten.*' => ['string'],
            'eisen.kleuren_uitsluiten' => ['array'],
            'eisen.kleuren_uitsluiten.*' => ['string', 'max:50'],
            'eisen.alleen_merkdealer' => ['boolean'],
            'gewichten' => ['required', 'array'],
            'gewichten.*' => ['integer', Rule::in(Wensen::BELANG)],
            'comfort_doel' => ['required', 'integer', 'min:1', 'max:3'],
            'sport' => ['required', Rule::in(['ja', 'nee'])],
            'pk_min' => ['required', 'integer', 'min:0'],
            'modellen' => ['array'],
            'modellen.*' => ['string'],
            'kleuren' => ['array'],
            'kleuren.*' => ['string', 'max:50'],
            'w' => ['required', 'numeric', Rule::in(Wensen::DEAL)],
        ]);

        $wensen = array_replace_recursive(Wensen::defaults(), $data);
        // Lists replace, they do not merge by index.
        $wensen['eisen']['uitsluiten'] = array_values($data['eisen']['uitsluiten'] ?? []);
        $wensen['modellen'] = array_values($data['modellen'] ?? []);
        $wensen['eisen']['kleuren_uitsluiten'] = array_values($data['eisen']['kleuren_uitsluiten'] ?? []);
        // Excluded wins over preferred.
        $wensen['kleuren'] = array_values(array_diff($data['kleuren'] ?? [], $wensen['eisen']['kleuren_uitsluiten']));
        $wensen['gewichten'] = array_intersect_key($wensen['gewichten'], array_flip(Wensen::SOFT));
        $wensen['w'] = (float) $wensen['w'];

        return $wensen;
    }
}
