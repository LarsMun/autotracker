<?php

namespace App\Deals;

use Carbon\CarbonInterface;

/**
 * The deal score of one car: what comparable cars online now cost, corrected to this car's km
 * and age, against its asking price.
 */
final class Comparables
{
    /**
     * Criteria per widening step: year ±, km ±, pk ± (share), comfort ± (null = ignored). Each step
     * widens one more; the first that finds enough cars is used.
     */
    public const array STEPS = [
        ['jaar' => 1, 'km' => 15000, 'pk' => 0.15, 'comfort' => 1],
        ['jaar' => 2, 'km' => 15000, 'pk' => 0.15, 'comfort' => 1],
        ['jaar' => 2, 'km' => 25000, 'pk' => 0.15, 'comfort' => 1],
        ['jaar' => 2, 'km' => 25000, 'pk' => 0.25, 'comfort' => 1],
        ['jaar' => 2, 'km' => 25000, 'pk' => 0.25, 'comfort' => null],
    ];

    /** Enough to stop widening. */
    public const int ENOUGH = 5;

    /** Fewer than this even after widening: no deal score. */
    public const int MINIMUM = 3;

    /** Cheaper than expected by more than this (%): "Check dit" (damage, import, a typo?). */
    public const float CHECK_PCT = 15.0;

    /**
     * The expected price must lie within these shares of the comparable cars' median asking
     * price; outside it the correction has run away and there is no deal score ("onvoldoende
     * betrouwbaar").
     */
    public const float MIN_SHARE = 0.3;

    public const float MAX_SHARE = 3.0;

    /**
     * @param  array{id: int, bouwjaar: ?int, kilometerstand: ?int, pk: ?int, comfort_niveau: ?int, prijs: ?int}  $car
     * @param  list<array{id: int, bouwjaar: ?int, kilometerstand: ?int, pk: ?int, comfort_niveau: ?int, prijs: ?int}>  $sameModel  the other cars of its model online now
     * @param  array{b: float, c: float, scope: string}|null  $factors
     * @return array{verwachte_prijs: ?int, deal_eur: ?int, deal_pct: ?float, vergelijkbaar_n: int, verruiming: ?int, vergelijkbaar: list<array{id: int, prijs: int, gecorrigeerd: int}>, factor_scope: ?string, check_dit: bool, onbetrouwbaar: bool}
     */
    public static function score(array $car, array $sameModel, ?array $factors, CarbonInterface $on): array
    {
        $none = ['verwachte_prijs' => null, 'deal_eur' => null, 'deal_pct' => null, 'vergelijkbaar_n' => 0, 'verruiming' => null, 'vergelijkbaar' => [], 'factor_scope' => $factors['scope'] ?? null, 'check_dit' => false, 'onbetrouwbaar' => false];

        if (! $car['prijs'] || $car['bouwjaar'] === null || $car['kilometerstand'] === null || $factors === null) {
            return $none;
        }

        [$found, $step] = self::find($car, $sameModel);
        $none['vergelijkbaar_n'] = count($found);

        if ($step === null) {
            return $none;
        }

        $age = Age::months($car['bouwjaar'], $on);
        $corrected = [];

        foreach ($found as $other) {
            $corrected[] = [
                'id' => $other['id'],
                'prijs' => $other['prijs'],
                'gecorrigeerd' => self::correct($other['prijs'], Age::months($other['bouwjaar'], $on), $other['kilometerstand'], $age, $car['kilometerstand'], $factors),
            ];
        }

        $expected = self::median(array_column($corrected, 'gecorrigeerd'));
        $asked = self::median(array_column($found, 'prijs'));

        if ($expected < self::MIN_SHARE * $asked || $expected > self::MAX_SHARE * $asked) {
            return ['verruiming' => $step, 'vergelijkbaar' => $corrected, 'onbetrouwbaar' => true] + $none;
        }

        $deal = $expected - $car['prijs'];
        $pct = round($deal / $expected * 100, 2);

        return [
            'verwachte_prijs' => $expected,
            'deal_eur' => $deal,
            'deal_pct' => $pct,
            'vergelijkbaar_n' => count($found),
            'verruiming' => $step,
            'vergelijkbaar' => $corrected,
            'factor_scope' => $factors['scope'],
            'check_dit' => $pct > self::CHECK_PCT,
            'onbetrouwbaar' => false,
        ];
    }

    /**
     * A comparable car's price as if it had the target's age and km.
     *
     * @param  array{b: float, c: float}  $factors
     */
    public static function correct(int $price, int $ageMonths, int $km, int $targetAgeMonths, int $targetKm, array $factors): int
    {
        return (int) round($price + $factors['b'] * ($targetAgeMonths - $ageMonths) + $factors['c'] * ($targetKm - $km));
    }

    /**
     * @return array{0: list<array>, 1: ?int} the comparable cars and the step that found them (null: too few)
     */
    public static function find(array $car, array $sameModel): array
    {
        $found = [];

        foreach (self::STEPS as $step => $c) {
            $found = array_values(array_filter($sameModel, fn (array $o) => self::matches($car, $o, $c)));
            $last = $step === count(self::STEPS) - 1;

            if (count($found) >= self::ENOUGH || ($last && count($found) >= self::MINIMUM)) {
                return [$found, $step];
            }
        }

        return [$found, null];
    }

    private static function matches(array $car, array $o, array $c): bool
    {
        if ($o['id'] === $car['id'] || ! $o['prijs'] || $o['bouwjaar'] === null || $o['kilometerstand'] === null) {
            return false;
        }

        if (abs($o['bouwjaar'] - $car['bouwjaar']) > $c['jaar'] || abs($o['kilometerstand'] - $car['kilometerstand']) > $c['km']) {
            return false;
        }

        // A criterion the target car has no value for does not apply.
        if ($car['pk'] && ($o['pk'] === null || abs($o['pk'] - $car['pk']) > $car['pk'] * $c['pk'])) {
            return false;
        }

        if ($c['comfort'] !== null && $car['comfort_niveau'] && ($o['comfort_niveau'] === null || abs($o['comfort_niveau'] - $car['comfort_niveau']) > $c['comfort'])) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<int>  $values
     */
    private static function median(array $values): int
    {
        sort($values);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 ? $values[$mid] : (int) round(($values[$mid - 1] + $values[$mid]) / 2);
    }
}
