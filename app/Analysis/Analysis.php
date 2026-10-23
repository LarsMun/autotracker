<?php

namespace App\Analysis;

use App\Models\Listing;
use Illuminate\Support\Carbon;

/**
 * The numbers for one selection: how it stands now, and its price per day.
 */
class Analysis
{
    /**
     * @return array{count: int, avg: ?int, median: ?int, min: ?int, max: ?int, avg_km: ?int, avg_days_online: ?int}
     */
    public function now(ListingFilter $filter): array
    {
        $cars = $filter->current()->get(['listings.prijs', 'listings.kilometerstand', 'listings.datum_geplaatst', 'listings.first_seen']);
        $prices = $cars->pluck('prijs')->filter(fn ($p) => $p !== null)->values()->all();
        $kms = $cars->pluck('kilometerstand')->filter(fn ($k) => $k !== null);
        $today = today();

        // Online since: Gaspedaal's own date where known, else when we first saw it.
        $days = $cars->map(fn (Listing $l) => (int) ($l->datum_geplaatst ?? $l->first_seen)->copy()->startOfDay()->diffInDays($today));

        return [
            'count' => $cars->count(),
            'avg' => $this->round($prices === [] ? null : array_sum($prices) / count($prices)),
            'median' => $this->median($prices),
            'min' => $prices === [] ? null : min($prices),
            'max' => $prices === [] ? null : max($prices),
            'avg_km' => $this->round($kms->avg()),
            'avg_days_online' => $this->round($days->avg()),
        ];
    }

    /**
     * Per day: the mean and median price of the selected cars online that day, and how many.
     *
     * @return list<array{date: string, n: int, avg: int, median: int}>
     */
    public function daily(ListingFilter $filter): array
    {
        $byDay = [];

        foreach ($filter->observations()->select(['observations.date', 'observations.prijs'])->orderBy('observations.date')->cursor() as $row) {
            $byDay[Carbon::parse($row->date)->toDateString()][] = (int) $row->prijs;
        }

        $series = [];

        foreach ($byDay as $date => $prices) {
            $series[] = [
                'date' => $date,
                'n' => count($prices),
                'avg' => $this->round(array_sum($prices) / count($prices)),
                'median' => $this->median($prices),
            ];
        }

        return $series;
    }

    /**
     * The headline of a saved filter: now, and how the median moved since the first day.
     *
     * @param  list<array{date: string, n: int, avg: int, median: int}>  $daily
     */
    public function change(array $daily): ?array
    {
        if (count($daily) < 2) {
            return null;
        }

        $first = $daily[0];
        $last = $daily[count($daily) - 1];

        return [
            'since' => $first['date'],
            'median' => $last['median'] - $first['median'],
            'median_pct' => $first['median'] ? round(($last['median'] - $first['median']) / $first['median'] * 100, 1) : null,
            'avg' => $last['avg'] - $first['avg'],
        ];
    }

    /**
     * @param  list<int>  $values
     */
    private function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 ? $values[$mid] : $this->round(($values[$mid - 1] + $values[$mid]) / 2);
    }

    private function round(float|int|null $value): ?int
    {
        return $value === null ? null : (int) round($value);
    }
}
