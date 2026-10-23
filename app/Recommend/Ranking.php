<?php

namespace App\Recommend;

use App\Models\Listing;
use Illuminate\Support\Facades\DB;

/**
 * The "Voor jou" list: the cars of the latest day with cijfers, through the filters, best first.
 * The cijfers are stored per day; the filters are applied here, on every request, so changing them
 * needs no new calculation.
 */
final class Ranking
{
    /** The latest day with cijfers; null before the first calculation. */
    public static function day(): ?string
    {
        return DB::table('match_scores')->max('date');
    }

    /**
     * Every car with a match_scores row on $day, with what the filters, the order and the list need.
     *
     * @return array<int, array> listing id => car
     */
    public static function cars(string $day): array
    {
        return DB::table('match_scores as m')
            ->join('listings as l', 'l.id', '=', 'm.listing_id')
            ->leftJoin('deal_scores as d', fn ($j) => $j->on('d.listing_id', '=', 'm.listing_id')->on('d.date', '=', 'm.date'))
            ->where('m.date', $day)
            ->get([
                'm.listing_id', 'm.cijfer', 'm.deelscores',
                'l.merk', 'l.model', 'l.prijs', 'l.kilometerstand', 'l.bouwjaar', 'l.kleur', 'l.sport', 'l.comfort_niveau', 'l.vermogen_kw', 'l.aanbieder_type', 'l.garantie',
                'd.verwachte_prijs', 'd.deal_eur', 'd.deal_pct', 'd.vergelijkbaar_n', 'd.check_dit', 'd.onbetrouwbaar',
            ])
            ->mapWithKeys(fn ($r) => [$r->listing_id => [
                'id' => $r->listing_id,
                'cijfer' => $r->cijfer === null ? null : (float) $r->cijfer,
                'cijfers' => json_decode($r->deelscores ?? '{}', true) ?? [],
                'merk' => $r->merk,
                'model' => $r->model,
                'prijs' => $r->prijs,
                'kilometerstand' => $r->kilometerstand,
                'bouwjaar' => $r->bouwjaar,
                'kleur' => $r->kleur,
                'sport' => (bool) $r->sport,
                'comfort_niveau' => $r->comfort_niveau === null ? null : (int) $r->comfort_niveau,
                'pk' => Listing::pkFrom($r->vermogen_kw),
                'aanbieder_type' => $r->aanbieder_type,
                'garantie' => $r->garantie,
                'deal' => $r->verwachte_prijs === null && $r->vergelijkbaar_n === null ? null : (object) [
                    'verwachte_prijs' => $r->verwachte_prijs,
                    'deal_eur' => $r->deal_eur,
                    'deal_pct' => $r->deal_pct,
                    'vergelijkbaar_n' => $r->vergelijkbaar_n,
                    'check_dit' => (bool) $r->check_dit,
                    'onbetrouwbaar' => (bool) $r->onbetrouwbaar,
                ],
            ]])
            ->all();
    }

    /**
     * The cars that pass the filters, best first: the highest cijfer (none goes last), then the
     * cheapest.
     *
     * @param  array<int, array>  $cars  listing id => car with its cijfer
     * @return list<int> listing ids
     */
    public static function rank(array $cars, array $wensen): array
    {
        $scorer = new MatchScorer($wensen);
        $passing = array_filter($cars, fn ($car) => $scorer->rejections($car) === []);

        uasort($passing, fn ($a, $b) => [$b['cijfer'] ?? -1, $a['prijs'] ?? PHP_INT_MAX, $a['id']] <=> [$a['cijfer'] ?? -1, $b['prijs'] ?? PHP_INT_MAX, $b['id']]);

        return array_keys($passing);
    }

    /**
     * What the filters can choose from: the values the cars have, with how many cars each.
     *
     * @param  array<int, array>  $cars
     */
    public static function options(array $cars): array
    {
        $count = fn (string $field) => collect($cars)->pluck($field)->filter(fn ($v) => $v !== null)->countBy()->sortKeys()
            ->map(fn ($n, $value) => ['value' => $value, 'n' => $n])->values()->all();

        return [
            'merken' => collect($cars)->filter(fn ($c) => $c['merk'] !== null)->groupBy('merk')->sortKeys()
                ->map(fn ($rows, $merk) => [
                    'merk' => $merk,
                    'n' => $rows->count(),
                    'modellen' => $rows->filter(fn ($c) => $c['model'] !== null)->countBy('model')->sortKeys()
                        ->map(fn ($n, $model) => ['model' => $model, 'n' => $n])->values()->all(),
                ])->values()->all(),
            'bouwjaren' => $count('bouwjaar'),
            'km' => collect($cars)->filter(fn ($c) => $c['kilometerstand'] !== null)->countBy(fn ($c) => MatchScorer::kmStep($c['kilometerstand']))->sortKeys()
                ->map(fn ($n, $value) => ['value' => $value, 'n' => $n])->values()->all(),
            'luxe' => $count('comfort_niveau'),
            'kleuren' => $count('kleur'),
        ];
    }
}
