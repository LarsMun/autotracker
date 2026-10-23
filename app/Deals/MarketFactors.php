<?php

namespace App\Deals;

use Carbon\CarbonInterface;

/**
 * How price falls with age and km: b (€ per month) and c (€ per km) from a regression over the
 * cars online now, per model when it has enough of them, else per brand, else over all cars.
 */
final class MarketFactors
{
    public const int MIN_CARS = 15;

    /**
     * A group's ages and km must vary this much (standard deviation), and not move together more
     * than MAX_CORRELATION, or the regression cannot tell b and c apart and gives wild values
     * (a source of nearly new cars of two build years, say).
     */
    public const float MIN_AGE_SD = 9.0;

    public const float MIN_KM_SD = 10000.0;

    public const float MAX_CORRELATION = 0.9;

    /** The steepest plausible fall: € per month and € per km. Steeper factors are cut off here. */
    public const float B_MIN = -1000.0;

    public const float C_MIN = -0.5;

    /**
     * @param  array<string, array{scope: string, merk: ?string, model: ?string, a: float, b: float, c: float, n: int, r2: float, fit_b: float, fit_c: float, begrensd: bool}>  $factors  keyed "model|merk|model", "merk|merk" and "alle"
     */
    public function __construct(public readonly array $factors) {}

    /**
     * @param  iterable<array{merk: ?string, model: ?string, bouwjaar: ?int, kilometerstand: ?int, prijs: ?int}>  $cars
     */
    public static function fit(iterable $cars, CarbonInterface $on): self
    {
        $groups = ['alle' => []];
        $meta = ['alle' => ['scope' => 'alle', 'merk' => null, 'model' => null]];

        foreach ($cars as $car) {
            if ($car['bouwjaar'] === null || $car['kilometerstand'] === null || ! $car['prijs']) {
                continue;
            }

            $row = [Age::months($car['bouwjaar'], $on), $car['kilometerstand'], $car['prijs']];
            $modelKey = "model|{$car['merk']}|{$car['model']}";
            $brandKey = "merk|{$car['merk']}";

            $groups[$modelKey][] = $row;
            $groups[$brandKey][] = $row;
            $groups['alle'][] = $row;
            $meta[$modelKey] ??= ['scope' => 'model', 'merk' => $car['merk'], 'model' => $car['model']];
            $meta[$brandKey] ??= ['scope' => 'merk', 'merk' => $car['merk'], 'model' => null];
        }

        $factors = [];

        foreach ($groups as $key => $rows) {
            $rows = self::withoutOutliers($rows);

            if (count($rows) < self::MIN_CARS || ! self::spreads($rows)) {
                continue;
            }

            $fit = Regression::fit($rows);

            // Price going up with age or km is not a market, it is noise in a small group.
            if ($fit === null || $fit['b'] > 0 || $fit['c'] > 0) {
                continue;
            }

            // The fitted values stay in fit_b and fit_c, for the log.
            $factors[$key] = [
                'b' => max(self::B_MIN, $fit['b']),
                'c' => max(self::C_MIN, $fit['c']),
                'fit_b' => $fit['b'],
                'fit_c' => $fit['c'],
                'begrensd' => $fit['b'] < self::B_MIN || $fit['c'] < self::C_MIN,
            ] + $meta[$key] + $fit;
        }

        return new self($factors);
    }

    /**
     * Without the cars priced outside the band the expected price must also stay in
     * (Comparables::MIN_SHARE–MAX_SHARE of the group's median): one typo of 40 million would
     * otherwise turn the whole regression around.
     *
     * @param  list<array{0: int, 1: int, 2: int}>  $rows
     * @return list<array{0: int, 1: int, 2: int}>
     */
    public static function withoutOutliers(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $prices = array_column($rows, 2);
        sort($prices);
        $median = $prices[intdiv(count($prices), 2)];

        return array_values(array_filter($rows, fn ($r) => $r[2] >= Comparables::MIN_SHARE * $median && $r[2] <= Comparables::MAX_SHARE * $median));
    }

    /**
     * Whether age and km vary enough, and independently enough, to fit both.
     *
     * @param  list<array{0: int, 1: int, 2: int}>  $rows  [age in months, km, price]
     */
    public static function spreads(array $rows): bool
    {
        $n = count($rows);
        $ages = array_column($rows, 0);
        $kms = array_column($rows, 1);
        $meanAge = array_sum($ages) / $n;
        $meanKm = array_sum($kms) / $n;
        $varAge = $varKm = $cov = 0.0;

        foreach ($rows as [$age, $km]) {
            $varAge += ($age - $meanAge) ** 2;
            $varKm += ($km - $meanKm) ** 2;
            $cov += ($age - $meanAge) * ($km - $meanKm);
        }

        if (sqrt($varAge / $n) < self::MIN_AGE_SD || sqrt($varKm / $n) < self::MIN_KM_SD) {
            return false;
        }

        return abs($cov / sqrt($varAge * $varKm)) <= self::MAX_CORRELATION;
    }

    /**
     * The most specific factors that exist for this car.
     *
     * @return array{scope: string, merk: ?string, model: ?string, a: float, b: float, c: float, n: int, r2: float}|null
     */
    public function for(?string $merk, ?string $model): ?array
    {
        return $this->factors["model|{$merk}|{$model}"] ?? $this->factors["merk|{$merk}"] ?? $this->factors['alle'] ?? null;
    }
}
