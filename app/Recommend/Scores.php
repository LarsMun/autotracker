<?php

namespace App\Recommend;

use App\Deals\Comparables;
use App\Deals\MarketFactors;
use App\Deals\OnlineSince;
use App\Models\Listing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The nightly calculation, for the cars online now: online-since dates, market factors, deal
 * scores, match and total scores, and the ranking of the day.
 */
class Scores
{
    /**
     * @return array{cars: int, factors: int, with_deal: int, passing: int}
     */
    public function run(?Carbon $day = null): array
    {
        $day ??= today();
        $cars = $this->cars();

        $this->onlineSince($cars, $day);
        $factors = $this->factors($cars, $day);
        $deals = $this->deals($cars, $factors, $day);
        $passing = $this->matches($cars, $deals, $day);

        return [
            'cars' => count($cars),
            'factors' => count($factors->factors),
            'with_deal' => count(array_filter($deals, fn ($d) => $d['verwachte_prijs'] !== null)),
            'passing' => $passing,
        ];
    }

    /**
     * Only the match and total scores again, with today's deal scores: after the wensen changed.
     */
    public function rematch(?Carbon $day = null): int
    {
        $day ??= today();

        if (! DB::table('deal_scores')->where('date', $day->toDateString())->exists()) {
            return $this->run($day)['passing'];
        }

        return $this->matches($this->cars(), $this->dealsOn($day->toDateString()), $day);
    }

    /**
     * The ranking these wensen would give, with the latest deal scores, without storing anything.
     *
     * @return array{rows: array<int, array>, passing: int} rows: listing id => match row, best first
     */
    public function preview(array $wensen): array
    {
        $date = DB::table('deal_scores')->max('date');
        $rows = $this->rank($this->cars(), $date ? $this->dealsOn($date) : [], $wensen);
        $ranked = array_filter($rows, fn ($r) => $r['rang'] !== null);
        uasort($ranked, fn ($a, $b) => $a['rang'] <=> $b['rang']);

        return ['rows' => $ranked, 'passing' => count($ranked)];
    }

    /**
     * @return array<int, array> listing id => deal result
     */
    private function dealsOn(string $date): array
    {
        return DB::table('deal_scores')->where('date', $date)
            ->get(['listing_id', 'deal_pct', 'check_dit', 'verwachte_prijs'])
            ->keyBy('listing_id')
            ->map(fn ($d) => ['deal_pct' => $d->deal_pct === null ? null : (float) $d->deal_pct, 'check_dit' => (bool) $d->check_dit, 'verwachte_prijs' => $d->verwachte_prijs])
            ->all();
    }

    /**
     * @return array<int, array> listing id => the fields the scores use
     */
    private function cars(): array
    {
        $cars = [];

        Listing::query()
            ->whereExists(fn ($q) => $q->from('listing_source')->whereColumn('listing_source.listing_id', 'listings.id')->whereNull('listing_source.gone_at'))
            ->select(['id', 'advertentie_id', 'merk', 'model', 'bouwjaar', 'kilometerstand', 'prijs', 'vermogen_kw', 'kleur', 'comfort_niveau', 'sport', 'aanbieder_type', 'garantie', 'datum_geplaatst', 'first_seen'])
            ->chunkById(1000, function ($chunk) use (&$cars) {
                foreach ($chunk as $l) {
                    $cars[$l->id] = [
                        'id' => $l->id,
                        'advertentie_id' => $l->advertentie_id,
                        'merk' => $l->merk,
                        'model' => $l->model,
                        'bouwjaar' => $l->bouwjaar,
                        'kilometerstand' => $l->kilometerstand,
                        'prijs' => $l->prijs,
                        'pk' => $l->pk(),
                        'kleur' => $l->kleur,
                        'comfort_niveau' => $l->comfort_niveau,
                        'sport' => $l->sport,
                        'aanbieder_type' => $l->aanbieder_type,
                        'garantie' => $l->garantie,
                        'datum_geplaatst' => $l->datum_geplaatst?->toDateTimeString(),
                        'first_seen' => $l->first_seen->toDateString(),
                    ];
                }
            });

        return $cars;
    }

    private function onlineSince(array $cars, Carbon $day): void
    {
        $groups = [];

        foreach (OnlineSince::determine(array_values($cars), $day) as $id => $since) {
            $groups[$since['datum'].'|'.$since['bron']][] = $id;
        }

        foreach ($groups as $key => $ids) {
            [$date, $source] = explode('|', $key);

            foreach (array_chunk($ids, 1000) as $chunk) {
                Listing::whereKey($chunk)->update(['online_sinds' => $date, 'online_sinds_bron' => $source]);
            }
        }
    }

    /**
     * What the deal_scores columns hold; a value outside it is a calculation gone wrong.
     */
    private const array COLUMN_RANGES = [
        'vraagprijs' => [0, 4294967295],
        'verwachte_prijs' => [1, 4294967295],
        'deal_eur' => [-2147483648, 2147483647],
        'deal_pct' => [-9999.99, 9999.99],
        'vergelijkbaar_n' => [0, 65535],
    ];

    private function factors(array $cars, Carbon $day): MarketFactors
    {
        $factors = MarketFactors::fit($cars, $day);

        foreach ($factors->factors as $f) {
            if ($f['begrensd']) {
                Log::warning('Scores: correction factors cut off to the plausible range', [
                    'scope' => $f['scope'], 'merk' => $f['merk'], 'model' => $f['model'], 'n' => $f['n'],
                    'fit_b_per_maand' => $f['fit_b'], 'fit_c_per_km' => $f['fit_c'], 'b_per_maand' => $f['b'], 'c_per_km' => $f['c'],
                ]);
            }
        }

        DB::transaction(function () use ($factors, $day) {
            DB::table('model_factors')->delete();

            foreach (array_chunk(array_values($factors->factors), 200) as $chunk) {
                DB::table('model_factors')->insert(array_map(fn ($f) => [
                    'scope' => $f['scope'],
                    'merk' => $f['merk'],
                    'model' => $f['model'],
                    'n' => $f['n'],
                    'a' => $f['a'],
                    'b_per_maand' => $f['b'],
                    'c_per_km' => $f['c'],
                    'r2' => $f['r2'],
                    'computed_on' => $day->toDateString(),
                ], $chunk));
            }
        });

        return $factors;
    }

    /**
     * @return array<int, array> listing id => deal result
     */
    private function deals(array $cars, MarketFactors $factors, Carbon $day): array
    {
        $byModel = [];

        foreach ($cars as $car) {
            $byModel["{$car['merk']}|{$car['model']}"][] = $car;
        }

        $deals = [];
        $rows = [];

        foreach ($cars as $id => $car) {
            // One car that cannot be scored must not stop the others: it gets no deal score.
            try {
                $deal = Comparables::score($car, $byModel["{$car['merk']}|{$car['model']}"], $factors->for($car['merk'], $car['model']), $day);
            } catch (Throwable $e) {
                Log::warning('Scores: no deal score for a car, the calculation failed', ['listing_id' => $id, 'error' => $e->getMessage()]);

                continue;
            }

            if ($bad = $this->outOfRange($car, $deal)) {
                Log::warning('Scores: deal score out of range, stored as onvoldoende betrouwbaar', [
                    'listing_id' => $id, 'merk' => $car['merk'], 'model' => $car['model'], 'prijs' => $car['prijs'], 'factor_scope' => $deal['factor_scope'],
                ] + $bad);
                $deal = ['verwachte_prijs' => null, 'deal_eur' => null, 'deal_pct' => null, 'check_dit' => false, 'onbetrouwbaar' => true] + $deal;
            }

            $deals[$id] = $deal;
            $rows[] = [
                'listing_id' => $id,
                'date' => $day->toDateString(),
                'vraagprijs' => $car['prijs'],
                'verwachte_prijs' => $deal['verwachte_prijs'],
                'deal_eur' => $deal['deal_eur'],
                'deal_pct' => $deal['deal_pct'],
                'vergelijkbaar_n' => $deal['vergelijkbaar_n'],
                'verruiming' => $deal['verruiming'],
                'vergelijkbaar' => json_encode($deal['vergelijkbaar']),
                'factor_scope' => $deal['factor_scope'],
                'check_dit' => $deal['check_dit'],
                'onbetrouwbaar' => $deal['onbetrouwbaar'],
            ];
        }

        DB::transaction(function () use ($rows, $day) {
            DB::table('deal_scores')->where('date', $day->toDateString())->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('deal_scores')->insert($chunk);
            }
        });

        return $deals;
    }

    /**
     * @return array<string, int|float> the values that do not fit their column; empty when all do
     */
    private function outOfRange(array $car, array $deal): array
    {
        $values = ['vraagprijs' => $car['prijs']] + array_intersect_key($deal, self::COLUMN_RANGES);

        return array_filter($values, fn ($v, $column) => $v !== null && (! is_finite((float) $v) || $v < self::COLUMN_RANGES[$column][0] || $v > self::COLUMN_RANGES[$column][1]), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @return int how many cars meet the requirements
     */
    private function matches(array $cars, array $deals, Carbon $day): int
    {
        $rows = array_map(fn ($r) => ['date' => $day->toDateString()] + $r, $this->rank($cars, $deals, Wensen::get()));

        DB::transaction(function () use ($rows, $day) {
            DB::table('match_scores')->where('date', $day->toDateString())->delete();

            foreach (array_chunk(array_values($rows), 500) as $chunk) {
                DB::table('match_scores')->insert($chunk);
            }
        });

        return count(array_filter($rows, fn ($r) => $r['door_eisen']));
    }

    /**
     * Match and total scores for every car, and the ranking of the ones that meet the requirements.
     *
     * @return array<int, array> listing id => match_scores row without the date
     */
    private function rank(array $cars, array $deals, array $wensen): array
    {
        // The year score runs from the newest year among the cars that meet the requirements.
        $checker = new MatchScorer($wensen);
        $rejections = [];

        foreach ($cars as $id => $car) {
            try {
                $rejections[$id] = $checker->rejections($car);
            } catch (Throwable $e) {
                Log::warning('Scores: car left out of the ranking, its requirements check failed', ['listing_id' => $id, 'error' => $e->getMessage()]);
            }
        }

        $years = array_filter(array_map(fn ($car) => $car['bouwjaar'], array_intersect_key($cars, array_filter($rejections, fn ($r) => $r === []))));
        $scorer = new MatchScorer($wensen, $years ? max($years) : null);
        $rows = [];

        foreach (array_intersect_key($cars, $rejections) as $id => $car) {
            try {
                $scores = $scorer->scores($car);
                $match = $scorer->match($scores);
                $deal = $deals[$id] ?? ['deal_pct' => null, 'check_dit' => false];
                $dealScore = MatchScorer::dealScore($deal['deal_pct'], (bool) $deal['check_dit']);
                $total = $scorer->total($match, $dealScore);
            } catch (Throwable $e) {
                Log::warning('Scores: car left out of the ranking, its match score failed', ['listing_id' => $id, 'error' => $e->getMessage()]);

                continue;
            }

            $rows[$id] = [
                'listing_id' => $id,
                'door_eisen' => $rejections[$id] === [],
                'afgewezen' => json_encode($rejections[$id]),
                'matchscore' => $match,
                'dealscore_score' => $dealScore,
                'totaalscore' => $total,
                'deelscores' => json_encode($scores),
                'rang' => null,
            ];
        }

        // The ranking: the cars that meet the requirements, best total first.
        $ranked = array_filter($rows, fn ($r) => $r['door_eisen']);
        uasort($ranked, fn ($a, $b) => [$b['totaalscore'], $b['matchscore'], $a['listing_id']] <=> [$a['totaalscore'], $a['matchscore'], $b['listing_id']]);
        $rank = 0;

        foreach (array_keys($ranked) as $id) {
            $rows[$id]['rang'] = ++$rank;
        }

        return $rows;
    }
}
