<?php

namespace App\Deals;

use Illuminate\Support\Carbon;

/**
 * Since when a car is online. Gaspedaal's datumGeplaatst is wrong for some cars: ads from the
 * past days with dates of two years ago (seemingly an earlier ad or the dealer's stock date).
 * Ad ids are handed out in order, so cars with neighbouring ids were placed around the same
 * time: a date far from its neighbours' is not used, nor one after our own first sighting (the
 * ad was placed again). Then first_seen it is.
 */
final class OnlineSince
{
    /** Neighbours on each side, by ad id. */
    public const int WINDOW = 60;

    /** Allowed difference from the neighbours' median, in days: the larger of these. */
    public const int MAX_DAYS = 60;

    public const float MAX_SHARE = 0.5;

    /**
     * @param  list<array{id: int, advertentie_id: int, datum_geplaatst: ?string, first_seen: string}>  $cars
     * @return array<int, array{datum: string, bron: string}> listing id => date and its source
     */
    public static function determine(array $cars, Carbon $today): array
    {
        usort($cars, fn ($a, $b) => $a['advertentie_id'] <=> $b['advertentie_id']);

        $days = array_map(fn ($c) => $c['datum_geplaatst'] ? (int) Carbon::parse($c['datum_geplaatst'])->startOfDay()->diffInDays($today) : null, $cars);
        $result = [];
        $count = count($cars);

        foreach ($cars as $i => $car) {
            $reliable = false;

            if ($days[$i] !== null && Carbon::parse($car['datum_geplaatst'])->toDateString() <= $car['first_seen']) {
                $neighbours = array_values(array_filter(
                    array_slice($days, max(0, $i - self::WINDOW), min($count, $i + self::WINDOW + 1) - max(0, $i - self::WINDOW)),
                    fn ($d) => $d !== null,
                ));

                // Too few cars to judge by neighbours: trust the date.
                if (count($neighbours) < 2 * self::WINDOW / 3) {
                    $reliable = true;
                } else {
                    sort($neighbours);
                    $expected = $neighbours[intdiv(count($neighbours), 2)];
                    $reliable = abs($days[$i] - $expected) <= max(self::MAX_DAYS, self::MAX_SHARE * $expected);
                }
            }

            $result[$car['id']] = $reliable
                ? ['datum' => Carbon::parse($car['datum_geplaatst'])->toDateString(), 'bron' => 'gaspedaal']
                : ['datum' => $car['first_seen'], 'bron' => 'first_seen'];
        }

        return $result;
    }
}
