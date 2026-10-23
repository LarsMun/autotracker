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
        $scorer = new MatchScorer($this->wensen(['km_max' => 80000, 'bouwjaar_min' => 2022], [
            'deal' => ['tien' => 10], 'km' => ['tien' => 10000], 'bouwjaar' => ['tien' => 2026], 'vermogen' => ['min' => 150, 'tien' => 200],
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

    public function test_the_filters(): void
    {
        $scorer = new MatchScorer($this->wensen(['prijs_min' => 20000, 'prijs_max' => 40000, 'km_max' => 80000, 'bouwjaar_min' => 2022]));

        $this->assertSame([], $scorer->rejections(self::CAR));
        $this->assertSame([], $scorer->rejections(['prijs' => 40000, 'kilometerstand' => 80000, 'bouwjaar' => 2022] + self::CAR));
        $this->assertSame(['prijs buiten je range'], $scorer->rejections(['prijs' => 40001] + self::CAR));
        $this->assertSame(['prijs buiten je range'], $scorer->rejections(['prijs' => 19999] + self::CAR));
        $this->assertSame(['te veel km', 'te oud'], $scorer->rejections(['kilometerstand' => 80001, 'bouwjaar' => 2021] + self::CAR));
        $this->assertSame(['prijs buiten je range', 'te veel km', 'te oud'], $scorer->rejections(['prijs' => null, 'kilometerstand' => null, 'bouwjaar' => null] + self::CAR));
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
        $this->assertSame(['niet sportief'], (new MatchScorer($this->wensen(['sport' => 'ja'])))->rejections(self::CAR));
        $this->assertSame([], (new MatchScorer($this->wensen(['sport' => 'ja'])))->rejections(['sport' => true] + self::CAR));
        $this->assertSame(['sportief'], (new MatchScorer($this->wensen(['sport' => 'nee'])))->rejections(['sport' => true] + self::CAR));
        $this->assertSame([], (new MatchScorer($this->wensen(['sport' => 'maakt_niet_uit'])))->rejections(['sport' => true] + self::CAR));

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
            'prijs_min' => 0, 'prijs_max' => 45000, 'km_max' => 70000, 'bouwjaar_min' => 2022,
            // The excluded Picanto's make is left out; the others keep all their models.
            'merken' => ['Audi', 'BMW', 'Volvo'], 'modellen' => [],
            // "Not white" is every other colour.
            'kleuren' => ['BLAUW', 'GRIJS', 'ZWART'], 'onbekende_kleur' => true,
            'sport' => 'ja', 'alleen_merkdealer' => true,
        ], $new['filters']);
        $this->assertSame(['deal' => 5, 'km' => 3, 'bouwjaar' => 3, 'luxe' => 5, 'vermogen' => 3], Wensen::weights($new));
        $this->assertSame(['belang' => 3, 'min' => 175, 'tien' => 225], $new['vakken']['vermogen']);
        $this->assertSame(Wensen::VERSIE, $new['versie']);
        // Already converted: left alone.
        $this->assertSame($new, Wensen::upgrade($new, fn () => []));
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

        $this->assertSame(88000, $new['filters']['km_max']);
        $this->assertSame(2021, $new['filters']['bouwjaar_min']);
        $this->assertSame(['BMW', 'Volvo'], $new['filters']['merken']);
        $this->assertSame(['BMW|3-serie', 'BMW|5-serie'], $new['filters']['modellen']);
        // A sport wish that did not count is no filter; deal only by match share 1.
        $this->assertSame('maakt_niet_uit', $new['filters']['sport']);
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
