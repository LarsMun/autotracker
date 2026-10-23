<?php

namespace Tests\Unit;

use App\Recommend\MatchScorer;
use App\Recommend\Reasons;
use App\Recommend\Wensen;
use PHPUnit\Framework\TestCase;

class MatchScoreTest extends TestCase
{
    private const array CAR = [
        'merk' => 'Volvo', 'model' => 'V60', 'prijs' => 35000, 'kilometerstand' => 50000, 'bouwjaar' => 2023,
        'kleur' => 'GRIJS', 'sport' => false, 'aanbieder_type' => 'handelaar', 'garantie' => null,
        'comfort_niveau' => 2, 'uitvoeringslijn' => 'Plus', 'pk' => 197,
    ];

    private function wensen(array $filters = [], array $vakken = []): array
    {
        $wensen = Wensen::defaults();
        $wensen['filters'] = $filters + $wensen['filters'];
        $wensen['vakken'] = array_replace_recursive($wensen['vakken'], $vakken);

        return $wensen;
    }

    public function test_the_line_through_the_6_and_the_10(): void
    {
        // Km: maximum 80.000 a 6, 10.000 a 10.
        $this->assertSame(10.0, MatchScorer::line(5000, 80000, 10000));
        $this->assertSame(10.0, MatchScorer::line(10000, 80000, 10000));
        $this->assertSame(8.0, MatchScorer::line(45000, 80000, 10000));
        $this->assertSame(6.0, MatchScorer::line(80000, 80000, 10000));
        // Past the 6 it goes on down, to no lower than 1.
        $this->assertSame(4.9, MatchScorer::line(99000, 80000, 10000));
        $this->assertSame(1.0, MatchScorer::line(500000, 80000, 10000));

        // Bouwjaar: 2022 a 6, 2026 a 10, a grade per year.
        $this->assertSame([6.0, 7.0, 8.0, 9.0, 10.0, 10.0], array_map(fn ($y) => MatchScorer::line($y, 2022, 2026), range(2022, 2027)));

        // Equal anchors: nothing to choose between.
        $this->assertSame(10.0, MatchScorer::line(150, 150, 150));
    }

    public function test_the_deal_grade(): void
    {
        $this->assertSame(6.0, MatchScorer::deal(0, false, 10));
        $this->assertSame(8.0, MatchScorer::deal(5, false, 10));
        $this->assertSame(10.0, MatchScorer::deal(10, false, 10));
        $this->assertSame(10.0, MatchScorer::deal(14, false, 10));
        $this->assertSame(2.0, MatchScorer::deal(-10, false, 10));
        $this->assertSame(1.0, MatchScorer::deal(-30, false, 10));
        $this->assertSame(8.0, MatchScorer::deal(10, false, 20));
        // No comparable cars, or suspiciously cheap: no grade, so it does not count.
        $this->assertNull(MatchScorer::deal(null, false, 10));
        $this->assertNull(MatchScorer::deal(20, true, 10));
    }

    public function test_luxe(): void
    {
        $this->assertSame(10.0, MatchScorer::luxe(3, 'Ultimate'));
        $this->assertSame(7.0, MatchScorer::luxe(2, 'Plus'));
        $this->assertSame(4.0, MatchScorer::luxe(1, 'Core'));
        // Without a recognised line the level is a guess.
        $this->assertNull(MatchScorer::luxe(1, null));
        $this->assertNull(MatchScorer::luxe(null, null));
    }

    public function test_the_grades_of_a_car(): void
    {
        $scorer = new MatchScorer($this->wensen(vakken: [
            'deal' => ['tien' => 10], 'km' => ['zes' => 80000, 'tien' => 10000], 'bouwjaar' => ['zes' => 2022, 'tien' => 2026], 'vermogen' => ['zes' => 150, 'tien' => 200],
        ]));

        $this->assertSame(
            ['deal' => 8.0, 'km' => 7.7, 'bouwjaar' => 7.0, 'luxe' => 7.0, 'vermogen' => 9.8],
            $scorer->grades(self::CAR, ['deal_pct' => 5.0, 'check_dit' => false]),
        );

        // What is unknown has no grade.
        $grades = $scorer->grades(['pk' => null, 'uitvoeringslijn' => null] + self::CAR, null);
        $this->assertNull($grades['deal']);
        $this->assertNull($grades['luxe']);
        $this->assertNull($grades['vermogen']);
    }

    public function test_the_cijfer_is_the_weighted_average_of_the_known_grades(): void
    {
        $scorer = new MatchScorer($this->wensen(vakken: [
            'deal' => ['belang' => 5], 'km' => ['belang' => 3], 'bouwjaar' => ['belang' => 1], 'luxe' => ['belang' => 0], 'vermogen' => ['belang' => 3],
        ]));

        // (5 × 8 + 3 × 6 + 1 × 10 + 3 × 9) / 12; luxe weighs nothing.
        $this->assertSame(7.9, $scorer->cijfer(['deal' => 8.0, 'km' => 6.0, 'bouwjaar' => 10.0, 'luxe' => 1.0, 'vermogen' => 9.0]));
        // An unknown grade leaves the average to the others: (3 × 6 + 1 × 10 + 3 × 9) / 7
        $this->assertSame(7.9, $scorer->cijfer(['deal' => null, 'km' => 6.0, 'bouwjaar' => 10.0, 'luxe' => null, 'vermogen' => 9.0]));
        // Nothing counts: no cijfer.
        $this->assertNull($scorer->cijfer(['deal' => null, 'km' => null, 'bouwjaar' => null, 'luxe' => 5.0, 'vermogen' => null]));
    }

    public function test_no_filter_set_lets_every_car_through(): void
    {
        $scorer = new MatchScorer($this->wensen());

        $this->assertSame([], $scorer->rejections(self::CAR));
        $this->assertSame([], $scorer->rejections(['prijs' => null, 'kilometerstand' => null, 'bouwjaar' => null, 'pk' => null, 'kleur' => null, 'comfort_niveau' => null] + self::CAR));
    }

    public function test_price_and_power_ranges(): void
    {
        $scorer = new MatchScorer($this->wensen(['prijs_min' => 20000, 'prijs_max' => 40000, 'pk_min' => 150]));

        $this->assertSame([], $scorer->rejections(self::CAR));
        $this->assertSame([], $scorer->rejections(['prijs' => 40000, 'pk' => 150] + self::CAR));
        $this->assertSame(['prijs buiten je range'], $scorer->rejections(['prijs' => 40001] + self::CAR));
        $this->assertSame(['prijs buiten je range'], $scorer->rejections(['prijs' => 19999] + self::CAR));
        $this->assertSame(['vermogen buiten je range'], $scorer->rejections(['pk' => 149] + self::CAR));
        // A bound is set: an unknown value does not pass it.
        $this->assertSame(['prijs buiten je range', 'vermogen buiten je range'], $scorer->rejections(['prijs' => null, 'pk' => null] + self::CAR));

        // Only an upper bound.
        $this->assertSame(['prijs buiten je range'], (new MatchScorer($this->wensen(['prijs_max' => 30000])))->rejections(self::CAR));
        $this->assertSame([], (new MatchScorer($this->wensen(['pk_max' => 200])))->rejections(self::CAR));
    }

    public function test_year_and_km_ranges_and_comfort_levels(): void
    {
        $scorer = new MatchScorer($this->wensen(['bouwjaar_min' => 2023, 'bouwjaar_max' => 2025, 'km_min' => 20000, 'km_max' => 50000, 'luxe' => [2, 3]]));

        $this->assertSame([], $scorer->rejections(self::CAR));
        $this->assertSame([], $scorer->rejections(['bouwjaar' => 2025, 'kilometerstand' => 20000] + self::CAR));
        $this->assertSame(['bouwjaar buiten je range'], $scorer->rejections(['bouwjaar' => 2022] + self::CAR));
        $this->assertSame(['bouwjaar buiten je range'], $scorer->rejections(['bouwjaar' => 2026] + self::CAR));
        $this->assertSame(['km buiten je range'], $scorer->rejections(['kilometerstand' => 50001] + self::CAR));
        $this->assertSame(['km buiten je range'], $scorer->rejections(['kilometerstand' => null] + self::CAR));
        $this->assertSame(['ander uitrustingsniveau'], $scorer->rejections(['comfort_niveau' => 1] + self::CAR));

        // One side only.
        $this->assertSame([], (new MatchScorer($this->wensen(['km_max' => 50000])))->rejections(['kilometerstand' => 0] + self::CAR));
    }

    public function test_make_and_model(): void
    {
        // Volvo only the V60 and XC60; BMW all its models.
        $scorer = new MatchScorer($this->wensen(['merken' => ['BMW', 'Volvo'], 'modellen' => ['Volvo|V60', 'Volvo|XC60']]));

        $this->assertSame([], $scorer->rejections(self::CAR));
        $this->assertSame(['ander model'], $scorer->rejections(['model' => 'V90'] + self::CAR));
        $this->assertSame([], $scorer->rejections(['merk' => 'BMW', 'model' => 'X3'] + self::CAR));
        $this->assertSame(['ander merk'], $scorer->rejections(['merk' => 'Audi', 'model' => 'A4'] + self::CAR));

        // No make chosen: every car.
        $this->assertSame([], (new MatchScorer($this->wensen()))->rejections(['merk' => 'Kia', 'model' => 'Picanto'] + self::CAR));
    }

    public function test_colour(): void
    {
        $scorer = new MatchScorer($this->wensen(['kleuren' => ['GRIJS', 'BLAUW'], 'onbekende_kleur' => true]));

        $this->assertSame([], $scorer->rejections(self::CAR));
        $this->assertSame(['andere kleur'], $scorer->rejections(['kleur' => 'WIT'] + self::CAR));
        $this->assertSame([], $scorer->rejections(['kleur' => null] + self::CAR));

        $strict = new MatchScorer($this->wensen(['kleuren' => ['GRIJS'], 'onbekende_kleur' => false]));
        $this->assertSame(['kleur onbekend'], $strict->rejections(['kleur' => null] + self::CAR));

        // No colour chosen: every colour, known or not.
        $this->assertSame([], (new MatchScorer($this->wensen(['onbekende_kleur' => false])))->rejections(['kleur' => null] + self::CAR));
    }

    public function test_sport_and_brand_dealer(): void
    {
        $sport = new MatchScorer($this->wensen(['sport' => true]));
        $this->assertSame(['niet sportief'], $sport->rejections(self::CAR));
        $this->assertSame([], $sport->rejections(['sport' => true] + self::CAR));
        $this->assertSame([], (new MatchScorer($this->wensen(['sport' => false])))->rejections(['sport' => true] + self::CAR));

        $dealer = new MatchScorer($this->wensen(['alleen_merkdealer' => true]));
        $this->assertSame(['geen merkdealer of merkgarantie'], $dealer->rejections(self::CAR));
        $this->assertSame([], $dealer->rejections(['garantie' => 'MERKGARANTIE'] + self::CAR));
        $this->assertSame([], $dealer->rejections(['aanbieder_type' => 'merkdealer'] + self::CAR));
    }

    public function test_version_3_wensen_are_converted(): void
    {
        // What was stored in production before the rapportcijfer.
        $v3 = [
            'versie' => 3,
            'eisen' => ['prijs_max' => 45000, 'km_max' => 70000, 'km_ruimer' => false, 'bouwjaar_min' => 2022, 'bouwjaar_ruimer' => false,
                'uitsluiten' => ['Kia|Picanto'], 'kleuren_uitsluiten' => ['WIT'], 'alleen_merkdealer' => true],
            'gewichten' => ['prijs' => 5, 'comfort' => 5, 'sport' => 5, 'merkdealer' => 5, 'pk' => 3, 'model' => 5, 'kleur' => 5],
            'comfort_doel' => 3, 'sport' => 'ja', 'pk_min' => 175,
            'modellen' => ['Audi|A4', 'BMW|X3', 'Volvo|V60', 'Volvo|XC60'],
            'kleuren' => ['BLAUW'],
            'w' => 0.5,
        ];
        $new = Wensen::upgrade($v3, fn () => ['BLAUW', 'GRIJS', 'WIT', 'ZWART']);

        $this->assertSame([
            // The excluded Picanto's make is left out; the others keep all their models.
            'merken' => ['Audi', 'BMW', 'Volvo'], 'modellen' => [],
            'prijs_min' => null, 'prijs_max' => 45000,
            // A minimum year and a maximum km, as before.
            'bouwjaar_min' => 2022, 'bouwjaar_max' => null, 'km_min' => null, 'km_max' => 70000,
            'luxe' => [], 'sport' => true, 'pk_min' => null, 'pk_max' => null,
            // "Not white" is every other colour.
            'kleuren' => ['BLAUW', 'GRIJS', 'ZWART'], 'onbekende_kleur' => true,
            'alleen_merkdealer' => true,
        ], $new['filters']);
        $this->assertSame(['deal' => 5, 'km' => 3, 'bouwjaar' => 3, 'luxe' => 5, 'vermogen' => 3], Wensen::weights($new));
        // The old limits are the 6 of their vakken.
        $this->assertSame(['belang' => 3, 'zes' => 70000, 'tien' => 10000], $new['vakken']['km']);
        $this->assertSame(['belang' => 3, 'zes' => 2022, 'tien' => (int) date('Y')], $new['vakken']['bouwjaar']);
        $this->assertSame(['belang' => 3, 'zes' => 175, 'tien' => 225], $new['vakken']['vermogen']);
        $this->assertSame(Wensen::VERSIE, $new['versie']);
        // Already converted: left alone.
        $this->assertSame($new, Wensen::upgrade($new, fn () => []));
    }

    public function test_version_5_ranges_are_converted(): void
    {
        $v5 = ['versie' => 5, 'filters' => ['bouwjaren' => [2023, 2024], 'km' => [20000, 30000]] + array_diff_key(Wensen::defaults()['filters'], array_flip(['bouwjaar_min', 'bouwjaar_max', 'km_min', 'km_max'])), 'vakken' => Wensen::defaults()['vakken']];
        $new = Wensen::upgrade($v5, fn () => []);

        $this->assertSame([2023, 2024, 20000, 40000], [$new['filters']['bouwjaar_min'], $new['filters']['bouwjaar_max'], $new['filters']['km_min'], $new['filters']['km_max']]);
        $this->assertSame(array_keys(Wensen::defaults()['filters']), array_keys($new['filters']));
        $this->assertSame(Wensen::VERSIE, $new['versie']);
    }

    public function test_older_wensen_are_converted_too(): void
    {
        $none = fn () => $this->fail('No colour was excluded.');

        // "Iets ruimer" becomes part of the limit; one excluded model of a make keeps its other models.
        $new = Wensen::upgrade([
            'eisen' => ['prijs_max' => 40000, 'km_max' => 80000, 'km_ruimer' => true, 'bouwjaar_min' => 2022, 'bouwjaar_ruimer' => true, 'uitsluiten' => ['BMW|X3']],
            'gewichten' => ['sport' => 0, 'comfort' => 1],
            'modellen' => ['BMW|3-serie', 'Volvo|V60', 'BMW|5-serie'],
            'w' => 1.0,
        ], $none);

        $this->assertSame(88000, $new['vakken']['km']['zes']);
        $this->assertSame(90000, $new['filters']['km_max']);
        $this->assertSame(2021, $new['vakken']['bouwjaar']['zes']);
        $this->assertSame(2021, $new['filters']['bouwjaar_min']);
        $this->assertSame(['BMW', 'Volvo'], $new['filters']['merken']);
        $this->assertSame(['BMW|3-serie', 'BMW|5-serie'], $new['filters']['modellen']);
        // A sport wish that did not count is no filter; deal only by match share 1.
        $this->assertFalse($new['filters']['sport']);
        $this->assertSame(['deal' => 0, 'km' => 3, 'bouwjaar' => 3, 'luxe' => 1, 'vermogen' => 3], Wensen::weights($new));

        // Nothing excluded: every make.
        $this->assertSame([], Wensen::upgrade(['modellen' => ['Volvo|V60'], 'w' => 0.7], $none)['filters']['merken']);
    }

    public function test_the_chips(): void
    {
        $deal = (object) ['check_dit' => false, 'verwachte_prijs' => 40000, 'deal_eur' => 2140, 'vergelijkbaar_n' => 12];
        $car = ['online_sinds' => null, 'online_sinds_bron' => null];

        $this->assertSame([['tekst' => '€2,1k onder 12 vergelijkbare', 'toon' => 'good']], Reasons::chips($car, $deal));
        $this->assertSame([['tekst' => '€800 boven 12 vergelijkbare', 'toon' => 'bad']], Reasons::chips($car, (object) (['deal_eur' => -800] + (array) $deal)));
        $this->assertSame([['tekst' => 'Check dit', 'toon' => 'warn'], ['tekst' => '€9k onder 12 vergelijkbare', 'toon' => 'warn']],
            Reasons::chips($car, (object) (['check_dit' => true, 'deal_eur' => 9000] + (array) $deal)));
        $this->assertSame([], Reasons::chips($car, null));
    }
}
