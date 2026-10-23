<?php

namespace Tests\Unit;

use App\Recommend\MatchScorer;
use App\Recommend\Reasons;
use App\Recommend\Wensen;
use PHPUnit\Framework\TestCase;

class MatchScoreTest extends TestCase
{
    public function test_price(): void
    {
        $this->assertSame(100, MatchScorer::price(30000, 40000));
        $this->assertSame(100, MatchScorer::price(20000, 40000));
        $this->assertSame(50, MatchScorer::price(35000, 40000));
        $this->assertSame(0, MatchScorer::price(40000, 40000));
        $this->assertSame(0, MatchScorer::price(45000, 40000));
    }

    public function test_km(): void
    {
        // Maximum 80.000: 100 at 0 km, straight down to 0 at the maximum, on to -50 at 88.000.
        $this->assertSame(100, MatchScorer::km(0, 80000));
        $this->assertSame(50, MatchScorer::km(40000, 80000));
        $this->assertSame(1, MatchScorer::km(79000, 80000));
        $this->assertSame(0, MatchScorer::km(80000, 80000));
        $this->assertSame(-25, MatchScorer::km(84000, 80000));
        $this->assertSame(-50, MatchScorer::km(88000, 80000));
        $this->assertSame(-50, MatchScorer::km(95000, 80000));
        $this->assertSame(0, MatchScorer::km(null, 80000));
    }

    public function test_year(): void
    {
        // Newest 2025, minimum 2021: 100 for the newest, straight down to 0 at the minimum, a year older -50.
        $this->assertSame(100, MatchScorer::year(2025, 2025, 2021));
        $this->assertSame(75, MatchScorer::year(2024, 2025, 2021));
        $this->assertSame(0, MatchScorer::year(2021, 2025, 2021));
        $this->assertSame(-50, MatchScorer::year(2020, 2025, 2021));
        $this->assertSame(0, MatchScorer::year(null, 2025, 2021));
        // Only the minimum year in the selection: full score.
        $this->assertSame(100, MatchScorer::year(2021, 2021, 2021));
    }

    public function test_the_limits_with_iets_ruimer(): void
    {
        $eisen = ['km_max' => 81000, 'km_ruimer' => false, 'bouwjaar_min' => 2022, 'bouwjaar_ruimer' => false];
        $this->assertSame(81000, MatchScorer::kmLimit($eisen));
        $this->assertSame(2022, MatchScorer::yearLimit($eisen));

        $eisen['km_ruimer'] = $eisen['bouwjaar_ruimer'] = true;
        $this->assertSame(89100, MatchScorer::kmLimit($eisen));
        $this->assertSame(2021, MatchScorer::yearLimit($eisen));
    }

    /**
     * Km and year always count, with weight 2 each; price alone besides them here.
     */
    private function marginScorer(bool $ruimer): MatchScorer
    {
        $wensen = Wensen::defaults();
        $wensen['eisen'] = ['km_max' => 80000, 'km_ruimer' => $ruimer, 'bouwjaar_min' => 2022, 'bouwjaar_ruimer' => $ruimer] + $wensen['eisen'];
        $wensen['gewichten'] = ['prijs' => 1] + array_fill_keys(Wensen::SOFT, 0);

        return new MatchScorer($wensen, 2025);
    }

    private const array MARGIN_CAR = ['merk' => 'Volvo', 'model' => 'V60', 'prijs' => 30000, 'kilometerstand' => 40000, 'bouwjaar' => 2025, 'aanbieder_type' => 'handelaar', 'garantie' => null];

    public function test_a_car_just_within_the_limits(): void
    {
        $scorer = $this->marginScorer(false);
        $car = ['kilometerstand' => 79000, 'bouwjaar' => 2022] + self::MARGIN_CAR;

        $this->assertSame([], $scorer->rejections($car));
        $scores = $scorer->scores($car);
        $this->assertSame(1, $scores['km']);
        $this->assertSame(0, $scores['bouwjaar']);
        // (1 × 100 + 2 × 1 + 2 × 0) / 5
        $this->assertSame(20.4, $scorer->match($scores));
    }

    public function test_a_car_in_the_margin_passes_with_a_negative_score(): void
    {
        $scorer = $this->marginScorer(true);
        $km = ['kilometerstand' => 84000] + self::MARGIN_CAR;
        $year = ['bouwjaar' => 2021] + self::MARGIN_CAR;

        $this->assertSame([], $scorer->rejections($km));
        $this->assertSame(-25, $scorer->scores($km)['km']);
        // (1 × 100 + 2 × -25 + 2 × 100) / 5: pulled down by the margin.
        $this->assertSame(50.0, $scorer->match($scorer->scores($km)));

        $this->assertSame([], $scorer->rejections($year));
        $this->assertSame(-50, $scorer->scores($year)['bouwjaar']);

        // Negative subscores can pull the match below 0, but it stays 0.
        $both = ['prijs' => 40000, 'kilometerstand' => 88000, 'bouwjaar' => 2021] + self::MARGIN_CAR;
        $this->assertSame([], $scorer->rejections($both));
        $this->assertSame(0.0, $scorer->match($scorer->scores($both)));
    }

    public function test_a_car_beyond_the_margin_falls_out(): void
    {
        $scorer = $this->marginScorer(true);

        $this->assertSame(['te veel km'], $scorer->rejections(['kilometerstand' => 88001] + self::MARGIN_CAR));
        $this->assertSame(['te oud'], $scorer->rejections(['bouwjaar' => 2020] + self::MARGIN_CAR));
    }

    public function test_without_iets_ruimer_a_margin_car_falls_out(): void
    {
        $scorer = $this->marginScorer(false);

        $this->assertSame(['te veel km'], $scorer->rejections(['kilometerstand' => 84000] + self::MARGIN_CAR));
        $this->assertSame(['te oud'], $scorer->rejections(['bouwjaar' => 2021] + self::MARGIN_CAR));
    }

    public function test_a_margin_car_gets_a_chip(): void
    {
        $wensen = Wensen::defaults();
        $wensen['eisen']['km_ruimer'] = $wensen['eisen']['bouwjaar_ruimer'] = true;
        $car = ['kilometerstand' => 84000, 'bouwjaar' => 2021, 'comfort_niveau' => null, 'online_sinds' => null] + self::MARGIN_CAR;
        $texts = fn (array $car) => array_column(Reasons::chips($car, null, ['km' => -25, 'bouwjaar' => -50], $wensen), 'tekst');

        $this->assertContains('net boven km-grens', $texts($car));
        $this->assertContains('1 jaar ouder dan gewenst', $texts($car));

        $within = ['kilometerstand' => 80000, 'bouwjaar' => 2022] + $car;
        $this->assertNotContains('net boven km-grens', $texts($within));
        $this->assertNotContains('1 jaar ouder dan gewenst', $texts($within));
    }

    public function test_comfort(): void
    {
        $this->assertSame(100, MatchScorer::comfort(3, 2));
        $this->assertSame(100, MatchScorer::comfort(2, 2));
        $this->assertSame(40, MatchScorer::comfort(1, 2));
        $this->assertSame(0, MatchScorer::comfort(1, 3));
        $this->assertSame(0, MatchScorer::comfort(null, 2));
    }

    public function test_sport(): void
    {
        $this->assertSame(100, MatchScorer::sport(true, 'ja'));
        $this->assertSame(0, MatchScorer::sport(false, 'ja'));
        $this->assertSame(100, MatchScorer::sport(false, 'nee'));
        $this->assertSame(0, MatchScorer::sport(true, 'nee'));
        $this->assertSame(100, MatchScorer::sport(null, 'nee'));
    }

    public function test_brand_dealer(): void
    {
        $scorer = new MatchScorer(Wensen::defaults());
        $car = ['merk' => 'BMW', 'model' => 'X3', 'prijs' => 30000, 'kilometerstand' => 10000, 'bouwjaar' => 2024, 'comfort_niveau' => 2, 'sport' => false, 'pk' => 184];

        $this->assertSame(100, $scorer->scores($car + ['aanbieder_type' => 'merkdealer', 'garantie' => null])['merkdealer']);
        $this->assertSame(100, $scorer->scores($car + ['aanbieder_type' => 'handelaar', 'garantie' => 'MERKGARANTIE'])['merkdealer']);
        $this->assertSame(0, $scorer->scores($car + ['aanbieder_type' => 'handelaar', 'garantie' => 'BOVAG'])['merkdealer']);
    }

    public function test_the_brand_dealer_wish_has_no_weight_when_it_is_a_requirement(): void
    {
        $wensen = Wensen::defaults();
        $wensen['gewichten'] = ['prijs' => 1, 'merkdealer' => 5] + array_fill_keys(Wensen::SOFT, 0);
        $scores = ['prijs' => 80, 'merkdealer' => 100];

        $this->assertSame(96.7, (new MatchScorer($wensen))->match($scores));
        $this->assertSame(5, Wensen::weights($wensen)['merkdealer']);

        $wensen['eisen']['alleen_merkdealer'] = true;
        $this->assertSame(80.0, (new MatchScorer($wensen))->match($scores));
        $this->assertSame(0, Wensen::weights($wensen)['merkdealer']);
        // The chosen importance stays, for when the requirement goes off again.
        $this->assertSame(5, $wensen['gewichten']['merkdealer']);
    }

    public function test_old_weights_and_the_match_share_are_converted(): void
    {
        $this->assertSame([0, 1, 3, 3, 5, 5], array_map(Wensen::belang(...), [0, 1, 2, 3, 4, 5]));

        $this->assertSame(1.0, Wensen::nearestDeal(1.0));
        $this->assertSame(1.0, Wensen::nearestDeal(0.95));
        $this->assertSame(0.85, Wensen::nearestDeal(0.8));
        $this->assertSame(0.7, Wensen::nearestDeal(0.7));
        $this->assertSame(0.7, Wensen::nearestDeal(0.65));
        $this->assertSame(0.5, Wensen::nearestDeal(0.55));
        $this->assertSame(0.5, Wensen::nearestDeal(0.2));

        $old = [
            'eisen' => ['prijs_max' => 40000, 'km_max' => 81000, 'bouwjaar_min' => 2022, 'alleen_merkdealer' => true],
            'gewichten' => ['prijs' => 5, 'km' => 3, 'leeftijd' => 2, 'comfort' => 4, 'sport' => 1, 'merkdealer' => 0, 'pk' => 2, 'model' => 0, 'kleur' => 1],
            'sport' => 'ja',
            'w' => 0.6,
        ];
        $new = Wensen::upgrade($old);

        // Km and age no longer have an importance: they always count.
        $this->assertSame(['prijs' => 5, 'comfort' => 5, 'sport' => 1, 'merkdealer' => 0, 'pk' => 3, 'model' => 0, 'kleur' => 1], $new['gewichten']);
        $this->assertSame(0.7, $new['w']);
        $this->assertArrayNotHasKey('km_doel', $new);
        $this->assertSame(Wensen::VERSIE, $new['versie']);
        // Already converted: left alone.
        $this->assertSame($new, Wensen::upgrade($new));

        // "Maakt niet uit" becomes importance "niet".
        $indifferent = Wensen::upgrade(['sport' => 'maakt_niet_uit', 'gewichten' => ['sport' => 4]]);
        $this->assertSame(0, $indifferent['gewichten']['sport']);
        $this->assertSame('ja', $indifferent['sport']);

        // Version 2: the km and year wishes go.
        $v2 = Wensen::upgrade(['versie' => 2, 'km_doel' => 40000, 'bouwjaar_doel' => 2024, 'gewichten' => ['prijs' => 3, 'km' => 5, 'leeftijd' => 1], 'w' => 0.85]);
        $this->assertSame(['versie' => 3, 'gewichten' => ['prijs' => 3], 'w' => 0.85], $v2);
        $this->assertSame(['km' => 2, 'bouwjaar' => 2, 'prijs' => 3], Wensen::weights($v2 + ['eisen' => []]));
    }

    public function test_power(): void
    {
        $this->assertSame(100, MatchScorer::power(200, 150));
        $this->assertSame(100, MatchScorer::power(150, 150));
        $this->assertSame(50, MatchScorer::power(135, 150));
        $this->assertSame(0, MatchScorer::power(120, 150));
        $this->assertSame(0, MatchScorer::power(100, 150));
        $this->assertSame(0, MatchScorer::power(null, 150));
        $this->assertSame(100, MatchScorer::power(null, 0));
    }

    public function test_model_order(): void
    {
        $order = ['Volvo|V60', 'BMW|3-serie', 'Volvo|XC60'];
        $this->assertSame(100, MatchScorer::model('Volvo|V60', $order));
        $this->assertSame(70, MatchScorer::model('BMW|3-serie', $order));
        $this->assertSame(40, MatchScorer::model('Volvo|XC60', $order));
        $this->assertSame(40, MatchScorer::model('Audi|A4', $order));
        $this->assertSame(100, MatchScorer::model('Audi|A4', []));
        $this->assertSame(100, MatchScorer::model('Volvo|V60', ['Volvo|V60']));
    }

    public function test_colour(): void
    {
        $this->assertSame(100, MatchScorer::colour('ZWART', ['ZWART', 'GRIJS']));
        $this->assertSame(0, MatchScorer::colour('WIT', ['ZWART']));
        $this->assertSame(50, MatchScorer::colour(null, ['ZWART']));
        $this->assertNull(MatchScorer::colour('WIT', []));

        // No preference: the colour leaves the match alone, whatever its weight.
        $wensen = Wensen::defaults();
        $wensen['gewichten'] = ['prijs' => 1, 'kleur' => 5] + array_fill_keys(Wensen::SOFT, 0);
        $this->assertSame(80.0, (new MatchScorer($wensen))->match(['prijs' => 80, 'kleur' => null]));
        $this->assertSame(13.3, (new MatchScorer($wensen))->match(['prijs' => 80, 'kleur' => 0]));
    }

    public function test_the_match_is_the_weighted_average(): void
    {
        $wensen = Wensen::defaults();
        $wensen['gewichten'] = ['prijs' => 4, 'comfort' => 0, 'sport' => 0, 'merkdealer' => 0, 'pk' => 0, 'model' => 0];
        $scorer = new MatchScorer($wensen);

        // Km and year weigh 2 each: (4 × 100 + 2 × 50 + 2 × 0) / 8
        $this->assertSame(62.5, $scorer->match(['prijs' => 100, 'km' => 50, 'bouwjaar' => 0, 'comfort' => 0, 'sport' => 0, 'merkdealer' => 0, 'pk' => 0, 'model' => 0]));

        $wensen['gewichten'] = array_fill_keys(Wensen::SOFT, 0);
        $this->assertSame(50.0, (new MatchScorer($wensen))->match(['prijs' => 100]));
    }

    public function test_the_deal_score_on_0_to_100_and_the_total(): void
    {
        $this->assertSame(0.0, MatchScorer::dealScore(-10));
        $this->assertSame(0.0, MatchScorer::dealScore(-25));
        $this->assertSame(50.0, MatchScorer::dealScore(0));
        $this->assertSame(75.0, MatchScorer::dealScore(5));
        $this->assertSame(100.0, MatchScorer::dealScore(10));
        $this->assertSame(100.0, MatchScorer::dealScore(14));
        $this->assertSame(50.0, MatchScorer::dealScore(null));
        // "Check dit" is no top deal.
        $this->assertSame(50.0, MatchScorer::dealScore(20, true));

        $scorer = new MatchScorer(Wensen::defaults()); // w = 0.7
        $this->assertSame(77.0, $scorer->total(80, 70));
        $this->assertSame(71.0, $scorer->total(80, 50));
    }

    public function test_hard_requirements(): void
    {
        $wensen = Wensen::defaults();
        $wensen['eisen']['uitsluiten'] = ['BMW|X3'];
        $scorer = new MatchScorer($wensen);
        $ok = ['merk' => 'Volvo', 'model' => 'V60', 'prijs' => 35000, 'kilometerstand' => 50000, 'bouwjaar' => 2023, 'aanbieder_type' => 'handelaar', 'garantie' => null];

        $this->assertSame([], $scorer->rejections($ok));
        $this->assertSame(['prijs boven budget'], $scorer->rejections(['prijs' => 40001] + $ok));
        $this->assertSame(['te veel km', 'te oud'], $scorer->rejections(['kilometerstand' => 90000, 'bouwjaar' => 2021] + $ok));
        $this->assertSame(['model uitgesloten'], $scorer->rejections(['merk' => 'BMW', 'model' => 'X3'] + $ok));

        $wensen['eisen']['alleen_merkdealer'] = true;
        $this->assertSame(['geen merkdealer of merkgarantie'], (new MatchScorer($wensen))->rejections($ok));
        $this->assertSame([], (new MatchScorer($wensen))->rejections(['garantie' => 'MERKGARANTIE'] + $ok));

        $wensen['eisen']['alleen_merkdealer'] = false;
        $wensen['eisen']['kleuren_uitsluiten'] = ['WIT'];
        $this->assertSame(['kleur uitgesloten'], (new MatchScorer($wensen))->rejections(['kleur' => 'WIT'] + $ok));
        $this->assertSame([], (new MatchScorer($wensen))->rejections(['kleur' => null] + $ok));
    }
}
