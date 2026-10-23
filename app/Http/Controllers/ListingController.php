<?php

namespace App\Http\Controllers;

use App\Gaspedaal\Client;
use App\Models\Listing;
use App\Models\Observation;
use App\Recommend\Wensen;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ListingController extends Controller
{
    public function show(Listing $listing): Response
    {
        $observations = $listing->observations()->orderBy('date')->get(['date', 'prijs', 'kilometerstand', 'snapshot_id']);

        // Only the days on which something changed, plus the first and the last.
        $changes = [];
        $previous = null;

        foreach ($observations as $i => $o) {
            $isLast = $i === $observations->count() - 1;

            if ($previous === null || $o->prijs !== $previous->prijs || $o->kilometerstand !== $previous->kilometerstand || $isLast) {
                $changes[] = [
                    'date' => $o->date->toDateString(),
                    'prijs' => $o->prijs,
                    'kilometerstand' => $o->kilometerstand,
                    'prijs_delta' => $previous && $o->prijs !== null && $previous->prijs !== null ? $o->prijs - $previous->prijs : null,
                    'km_delta' => $previous && $o->kilometerstand !== null && $previous->kilometerstand !== null ? $o->kilometerstand - $previous->kilometerstand : null,
                ];
            }

            $previous = $o;
        }

        $latest = $listing->snapshots()->orderByDesc('last_seen')->orderByDesc('id')->first();

        return Inertia::render('Listings/Show', [
            'listing' => $listing->summary() + [
                'carrosserie' => $listing->carrosserie,
                'kleur' => $listing->kleur,
                'deuren' => $listing->deuren,
                'motorinhoud' => $listing->motorinhoud,
                'vermogen_kw' => $listing->vermogen_kw,
                'aanbieder' => $listing->aanbieder,
                'dealer_slug' => $listing->dealer_slug,
                'importeur_portaal' => $listing->importeur_portaal,
                'kenmerken_op' => $listing->kenmerken_op?->toDateString(),
                'uitvoeringslijn' => $listing->uitvoeringslijn,
                'uitrusting_opties' => $listing->uitrusting_opties ?? [],
                'provincie' => $listing->provincie,
                'datum_geplaatst' => $listing->datum_geplaatst?->toIso8601String(),
                'foto_remote' => $listing->foto_url,
            ],
            'sources' => $listing->sources->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'first_seen' => $s->pivot->first_seen,
                'last_seen' => $s->pivot->last_seen,
                'gone_at' => $s->pivot->gone_at,
            ]),
            'history' => $observations->map(fn (Observation $o) => [
                'date' => $o->date->toDateString(),
                'prijs' => $o->prijs,
                'kilometerstand' => $o->kilometerstand,
            ]),
            'changes' => array_reverse($changes),
            'portalen' => $this->portals($listing, $latest?->data['portalen'] ?? []),
            'raw' => $latest?->data,
            'snapshot_count' => $listing->snapshots()->count(),
            'score' => $this->score($listing),
        ]);
    }

    /**
     * @return list<array{naam: string, type: ?string, url: ?string}>
     */
    private function portals(Listing $listing, array $portals): array
    {
        // The sort of the search the car came from, as the site passes it along.
        parse_str((string) parse_url((string) $listing->sources->first()?->url, PHP_URL_QUERY), $query);
        $sort = is_string($query['srt'] ?? null) ? $query['srt'] : 'df-a';

        return array_map(fn (array $p) => [
            'naam' => (string) ($p['portaalBeschrijving'] ?? 'Advertentie'),
            'type' => $p['portaalType'] ?? null,
            'url' => Client::portalUrl($p, count($portals), $sort),
        ], $portals);
    }

    /**
     * "Waarom deze score": the latest deal and match scores, with the comparable cars.
     */
    private function score(Listing $listing): ?array
    {
        $match = DB::table('match_scores')->where('listing_id', $listing->id)->orderByDesc('date')->first();
        $deal = DB::table('deal_scores')->where('listing_id', $listing->id)->orderByDesc('date')->first();

        if ($match === null && $deal === null) {
            return null;
        }

        $comparables = collect(json_decode($deal->vergelijkbaar ?? '[]', true));
        $others = Listing::whereKey($comparables->pluck('id'))->get()->keyBy('id');
        $wensen = Wensen::get();

        return [
            'date' => $match->date ?? $deal->date,
            'door_eisen' => (bool) ($match->door_eisen ?? false),
            'afgewezen' => json_decode($match->afgewezen ?? '[]', true),
            'rang' => $match->rang ?? null,
            'matchscore' => $match ? (float) $match->matchscore : null,
            'dealscore_score' => $match ? (float) $match->dealscore_score : null,
            'totaalscore' => $match ? (float) $match->totaalscore : null,
            'w' => (float) $wensen['w'],
            'deelscores' => collect(json_decode($match->deelscores ?? '{}', true))
                ->map(fn ($score, $wish) => ['wens' => $wish, 'score' => $score, 'gewicht' => (int) (Wensen::weights($wensen)[$wish] ?? 0)])
                ->values(),
            'deal' => $deal ? [
                'verwachte_prijs' => $deal->verwachte_prijs,
                'deal_eur' => $deal->deal_eur,
                'deal_pct' => $deal->deal_pct === null ? null : (float) $deal->deal_pct,
                'vergelijkbaar_n' => $deal->vergelijkbaar_n,
                'verruiming' => $deal->verruiming,
                'factor_scope' => $deal->factor_scope,
                'check_dit' => (bool) $deal->check_dit,
                'onbetrouwbaar' => (bool) $deal->onbetrouwbaar,
            ] : null,
            'vergelijkbaar' => $comparables->map(fn ($c) => [
                'id' => $c['id'],
                'title' => $others->get($c['id'])?->title(),
                'bouwjaar' => $others->get($c['id'])?->bouwjaar,
                'kilometerstand' => $others->get($c['id'])?->kilometerstand,
                'pk' => $others->get($c['id'])?->pk(),
                'comfort_niveau' => $others->get($c['id'])?->comfort_niveau,
                'prijs' => $c['prijs'],
                'gecorrigeerd' => $c['gecorrigeerd'],
            ])->sortBy('gecorrigeerd')->values(),
            'online_sinds' => $listing->online_sinds?->toDateString(),
            'online_sinds_bron' => $listing->online_sinds_bron,
        ];
    }
}
