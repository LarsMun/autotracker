<?php

namespace Tests\Unit;

use App\Deals\Comparables;
use App\Deals\MarketFactors;
use App\Deals\OnlineSince;
use App\Deals\Regression;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class DealScoreTest extends TestCase
{
    private Carbon $on;

    protected function setUp(): void
    {
        parent::setUp();
        $this->on = Carbon::create(2026, 10, 16);
    }

    public function test_the_regression_finds_known_factors(): void
    {
        // price = 50000 − 300·months − 0.10·km, exactly.
        $rows = [];

        foreach ([[12, 10000], [24, 30000], [36, 20000], [48, 60000], [18, 45000], [30, 15000]] as [$age, $km]) {
            $rows[] = [$age, $km, 50000 - 300 * $age - 0.10 * $km];
        }

        $fit = Regression::fit($rows);

        $this->assertEqualsWithDelta(50000, $fit['a'], 0.01);
        $this->assertEqualsWithDelta(-300, $fit['b'], 0.0001);
        $this->assertEqualsWithDelta(-0.10, $fit['c'], 0.000001);
        $this->assertEqualsWithDelta(1.0, $fit['r2'], 1e-9);
        $this->assertSame(6, $fit['n']);
    }

    public function test_the_regression_with_noise_stays_close(): void
    {
        mt_srand(7);
        $rows = [];

        for ($i = 0; $i < 400; $i++) {
            $age = mt_rand(6, 72);
            $km = mt_rand(1000, 150000);
            $rows[] = [$age, $km, 60000 - 400 * $age - 0.08 * $km + mt_rand(-1500, 1500)];
        }

        $fit = Regression::fit($rows);

        $this->assertEqualsWithDelta(-400, $fit['b'], 10);
        $this->assertEqualsWithDelta(-0.08, $fit['c'], 0.005);
    }

    public function test_the_regression_cannot_fit_cars_of_one_age(): void
    {
        $this->assertNull(Regression::fit([[24, 10000, 30000], [24, 20000, 29000], [24, 30000, 28000], [24, 40000, 27000]]));
        $this->assertNull(Regression::fit([[24, 10000, 30000], [36, 20000, 29000]]));
    }

    private function cars(string $merk, string $model, int $count, float $b, float $c, int $base = 60000): array
    {
        $cars = [];

        for ($i = 0; $i < $count; $i++) {
            $year = 2020 + $i % 5;
            $km = 10000 + 7000 * $i;
            $age = (2026 - $year) * 12 + 3;
            $cars[] = ['merk' => $merk, 'model' => $model, 'bouwjaar' => $year, 'kilometerstand' => $km, 'prijs' => (int) round($base + $b * $age + $c * $km)];
        }

        return $cars;
    }

    public function test_factors_per_model_else_brand_else_all(): void
    {
        $factors = MarketFactors::fit([
            ...$this->cars('BMW', '3-serie', 20, -300, -0.10),
            ...$this->cars('BMW', 'X3', 5, -500, -0.20),
            ...$this->cars('Volvo', 'V60', 8, -200, -0.05),
            ...$this->cars('Volvo', 'V90', 8, -200, -0.05),
        ], $this->on);

        $this->assertSame('model', $factors->for('BMW', '3-serie')['scope']);
        $this->assertEqualsWithDelta(-300, $factors->for('BMW', '3-serie')['b'], 0.01);
        $this->assertEqualsWithDelta(-0.10, $factors->for('BMW', '3-serie')['c'], 0.0001);
        // 5 X3s: the brand's (25 BMWs).
        $this->assertSame('merk', $factors->for('BMW', 'X3')['scope']);
        // 16 Volvos together: the brand's; a brand with too few: all cars.
        $this->assertSame('merk', $factors->for('Volvo', 'V60')['scope']);
        $this->assertSame('alle', $factors->for('Audi', 'A4')['scope']);
    }

    public function test_factors_where_price_rises_with_km_are_not_used(): void
    {
        $factors = MarketFactors::fit([
            ...$this->cars('Audi', 'A4', 16, -300, +0.20, 120000),
            ...$this->cars('Audi', 'A6', 16, -300, -0.50, 120000),
        ], $this->on);

        // The A4's own factors are refused; the brand's (km price falling overall) are used.
        $this->assertSame('merk', $factors->for('Audi', 'A4')['scope']);
        $this->assertLessThan(0, $factors->for('Audi', 'A4')['c']);
        $this->assertSame('model', $factors->for('Audi', 'A6')['scope']);

        // No usable factors anywhere: none, and so no deal score.
        $this->assertNull(MarketFactors::fit($this->cars('Audi', 'A4', 16, -300, +0.20), $this->on)->for('Audi', 'A4'));
    }

    public function test_a_model_without_spread_in_age_or_km_uses_the_brands_factors(): void
    {
        $bmws = $this->cars('BMW', '3-serie', 20, -300, -0.10);

        // 20 Picantos of one build year with 1.000–1.950 km: age and km tell nothing about price,
        // and a few cents of noise would give wild factors.
        $picantos = [];

        for ($i = 0; $i < 20; $i++) {
            $picantos[] = ['merk' => 'Kia', 'model' => 'Picanto', 'bouwjaar' => 2025, 'kilometerstand' => 1000 + 50 * $i, 'prijs' => 17000 + ($i % 3) * 300];
        }

        $factors = MarketFactors::fit([...$bmws, ...$picantos], $this->on);

        $this->assertArrayNotHasKey('model|Kia|Picanto', $factors->factors);
        $this->assertArrayNotHasKey('merk|Kia', $factors->factors);
        $this->assertNotSame('model', $factors->for('Kia', 'Picanto')['scope'] ?? null);

        // Two build years, but km rising with age in lockstep: b and c cannot be told apart.
        $lockstep = [];

        for ($i = 0; $i < 20; $i++) {
            $year = 2022 + $i % 2;
            $lockstep[] = ['merk' => 'Kia', 'model' => 'Picanto', 'bouwjaar' => $year, 'kilometerstand' => (2026 - $year) * 15000 + $i % 3, 'prijs' => 15000 - (2026 - $year) * 1000 + ($i % 4) * 250];
        }

        $this->assertFalse(MarketFactors::spreads(array_map(fn ($c) => [(2026 - $c['bouwjaar']) * 12, $c['kilometerstand'], $c['prijs']], $lockstep)));
        $this->assertTrue(MarketFactors::spreads(array_map(fn ($c) => [(2026 - $c['bouwjaar']) * 12, $c['kilometerstand'], $c['prijs']], $bmws)));
    }

    public function test_an_extreme_price_outlier_cannot_push_the_factors_past_their_bounds(): void
    {
        $cars = $this->cars('BMW', '3-serie', 20, -300, -0.10);
        // A typo: 40 million for the oldest car with the most km.
        $cars[19]['prijs'] = 40_000_000;

        $f = MarketFactors::fit($cars, $this->on)->for('BMW', '3-serie');

        // Refused (price rising with age or km) or within the bounds.
        $this->assertTrue($f === null || ($f['b'] >= MarketFactors::B_MIN && $f['b'] <= 0 && $f['c'] >= MarketFactors::C_MIN && $f['c'] <= 0));

        // A fall far too steep (€ 5.000 a month, as a few mispriced cars can give): cut off.
        $f = MarketFactors::fit($this->cars('BMW', '3-serie', 20, -5000, -0.10, 1_000_000), $this->on)->for('BMW', '3-serie');

        $this->assertTrue($f['begrensd']);
        $this->assertSame(MarketFactors::B_MIN, $f['b']);
        $this->assertLessThan(MarketFactors::B_MIN, $f['fit_b']);
        $this->assertGreaterThanOrEqual(MarketFactors::C_MIN, $f['c']);
        $this->assertLessThanOrEqual(0, $f['c']);
    }

    public function test_an_implausible_expected_price_gives_no_deal_score(): void
    {
        // A steep km factor and a car with 6.000–14.000 km more than its comparables: corrected
        // prices far below zero (the median: 30.000 − 9 × 10.000).
        $car = $this->other(1, 2023, 40000, 184, 2, 30000);
        $same = array_map(fn ($i) => $this->other($i, 2023, 26000 + 2000 * ($i - 2), 184, 2, 30000), range(2, 6));
        $factors = ['b' => -1000.0, 'c' => -9.0, 'scope' => 'model'];

        $deal = Comparables::score($car, $same, $factors, $this->on);

        $this->assertTrue($deal['onbetrouwbaar']);
        $this->assertNull($deal['verwachte_prijs']);
        $this->assertNull($deal['deal_pct']);
        $this->assertSame(5, $deal['vergelijkbaar_n']);
        $this->assertSame(0, $deal['verruiming']);
        $this->assertFalse($deal['check_dit']);

        // Above three times the asking prices is refused too.
        // (30.000 + 5 × 24.900 = 154.500 against 30.000 asked).
        $deal = Comparables::score(['kilometerstand' => 100] + $car, array_map(fn ($o) => ['kilometerstand' => 25000] + $o, $same), ['c' => -5.0] + $factors, $this->on);
        $this->assertTrue($deal['onbetrouwbaar']);

        // Within the band: a normal score.
        $this->assertFalse(Comparables::score($car, $same, ['b' => -300.0, 'c' => -0.10, 'scope' => 'model'], $this->on)['onbetrouwbaar']);
    }

    public function test_correction_to_the_target_cars_age_and_km(): void
    {
        // A car one year older with 20.000 km more is worth more at the target's age and km.
        $factors = ['b' => -300.0, 'c' => -0.10];
        $this->assertSame(35600, Comparables::correct(30000, 36, 50000, 24, 30000, $factors));
        // And the other way round.
        $this->assertSame(24400, Comparables::correct(30000, 24, 30000, 36, 50000, $factors));
    }

    private function other(int $id, int $year, int $km, int $pk, int $comfort, int $price): array
    {
        return ['id' => $id, 'bouwjaar' => $year, 'kilometerstand' => $km, 'pk' => $pk, 'comfort_niveau' => $comfort, 'prijs' => $price];
    }

    public function test_comparable_cars_and_the_expected_price(): void
    {
        $car = $this->other(1, 2023, 40000, 184, 2, 30000);
        $same = [
            $car,
            $this->other(2, 2023, 40000, 184, 2, 32000),
            $this->other(3, 2023, 40000, 190, 3, 33000),
            $this->other(4, 2022, 50000, 170, 1, 31000),
            $this->other(5, 2024, 30000, 184, 2, 34000),
            $this->other(6, 2023, 45000, 200, 2, 32500),
            $this->other(7, 2020, 40000, 184, 2, 20000), // too old
            $this->other(8, 2023, 90000, 184, 2, 25000), // too many km
        ];
        $factors = ['b' => -300.0, 'c' => -0.10, 'scope' => 'model'];

        $deal = Comparables::score($car, $same, $factors, $this->on);

        $this->assertSame(0, $deal['verruiming']);
        $this->assertSame(5, $deal['vergelijkbaar_n']);
        $this->assertEqualsCanonicalizing([2, 3, 4, 5, 6], array_column($deal['vergelijkbaar'], 'id'));
        // Corrected: 32000, 33000, 31000+3600+1000=35600, 34000−3600−1000=29400, 32500+500=33000 → median 33000.
        $this->assertSame(33000, $deal['verwachte_prijs']);
        $this->assertSame(3000, $deal['deal_eur']);
        $this->assertEqualsWithDelta(9.09, $deal['deal_pct'], 0.01);
        $this->assertFalse($deal['check_dit']);
    }

    public function test_too_few_comparables_widens_step_by_step(): void
    {
        $car = $this->other(1, 2023, 40000, 184, 2, 30000);
        $factors = ['b' => -300.0, 'c' => -0.10, 'scope' => 'model'];

        // Four strict matches plus one two years older: step 1 (year ±2) gets five.
        $same = [
            $this->other(2, 2023, 40000, 184, 2, 32000),
            $this->other(3, 2023, 41000, 184, 2, 32000),
            $this->other(4, 2023, 42000, 184, 2, 32000),
            $this->other(5, 2023, 43000, 184, 2, 32000),
            $this->other(6, 2021, 40000, 184, 2, 25000),
        ];
        $this->assertSame(1, Comparables::score($car, $same, $factors, $this->on)['verruiming']);

        // Only with comfort ignored: the last step.
        $same = [
            $this->other(2, 2023, 40000, 184, 2, 32000),
            $this->other(3, 2023, 40000, 184, 2, 32000),
            $this->other(4, 2022, 60000, 220, 3, 30000),
            $this->other(5, 2022, 60000, 184, 3, 30000),
            ['comfort_niveau' => null] + $this->other(6, 2023, 40000, 184, 0, 31000),
        ];
        $deal = Comparables::score($car, $same, $factors, $this->on);
        $this->assertSame(4, $deal['verruiming']);
        $this->assertSame(5, $deal['vergelijkbaar_n']);

        // Three after all steps: still a score, from the widest step.
        $deal = Comparables::score($car, array_slice($same, 0, 3), $factors, $this->on);
        $this->assertSame(4, $deal['verruiming']);
        $this->assertSame(3, $deal['vergelijkbaar_n']);

        // Two: no deal score.
        $deal = Comparables::score($car, array_slice($same, 0, 2), $factors, $this->on);
        $this->assertNull($deal['verruiming']);
        $this->assertNull($deal['verwachte_prijs']);
        $this->assertSame(2, $deal['vergelijkbaar_n']);
    }

    public function test_more_than_15_percent_below_expectation_is_check_dit(): void
    {
        $car = $this->other(1, 2023, 40000, 184, 2, 26000);
        $same = array_map(fn ($i) => $this->other($i, 2023, 40000, 184, 2, 32000), range(2, 6));
        $factors = ['b' => -300.0, 'c' => -0.10, 'scope' => 'model'];

        $deal = Comparables::score($car, $same, $factors, $this->on);
        $this->assertEqualsWithDelta(18.75, $deal['deal_pct'], 0.01);
        $this->assertTrue($deal['check_dit']);

        $car['prijs'] = 28000; // 12.5% below
        $this->assertFalse(Comparables::score($car, $same, $factors, $this->on)['check_dit']);
    }

    public function test_a_placement_date_far_from_its_neighbours_is_not_used(): void
    {
        $today = Carbon::create(2026, 10, 16);
        $cars = [];

        // 200 ads, ids in order, placed 1 day apart going back.
        for ($i = 0; $i < 200; $i++) {
            $cars[] = ['id' => $i + 1, 'advertentie_id' => 1000 + $i, 'datum_geplaatst' => $today->copy()->subDays(200 - $i)->toDateTimeString(), 'first_seen' => '2026-10-16'];
        }

        // A recent ad id claiming two years; and one placed after we first saw it.
        $cars[190]['datum_geplaatst'] = '2024-07-04 05:12:49';
        $cars[100]['first_seen'] = '2026-01-01';

        $since = OnlineSince::determine($cars, $today);

        $this->assertSame('gaspedaal', $since[50]['bron']);
        $this->assertSame($today->copy()->subDays(151)->toDateString(), $since[50]['datum']);
        $this->assertSame(['datum' => '2026-10-16', 'bron' => 'first_seen'], $since[191]);
        $this->assertSame(['datum' => '2026-01-01', 'bron' => 'first_seen'], $since[101]);
    }
}
