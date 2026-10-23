<?php

namespace Tests\Unit;

use App\Uitrusting\Classifier;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClassifierTest extends TestCase
{
    public static function titles(): array
    {
        return [
            // From the assignment.
            ['Volvo', 'V60', 'B4 Ultimate Dark | Pano | H&K', 3, true, 'Ultimate'],
            ['BMW', 'X3', 'xDrive20i Business Edition Plus', 2, false, 'Business Edition Plus'],
            ['BMW', '3-serie', '320i High Executive M Sport', 3, true, 'High Executive + M Sport'],
            ['Audi', 'A4', '35 TFSI Advanced edition', 1, false, 'Advanced edition'],
            ['Volvo', 'V60', 'B3 Essential Edition | Navigatie | Camera', 1, false, 'Essential'],
            ['Volkswagen', 'Passat', '2.0 TSI | Pano | Leder | ACC', 3, false, null],

            // Most specific line first.
            ['BMW', '3-serie', 'Touring 318i High Executive Edition', 2, false, 'High Executive'],
            ['BMW', '3-serie', 'Touring 318i Executive Edition | Panoramadak', 1, false, 'Executive'],
            ['Audi', 'Q5', 'S edition Competition 2.0 e-hybrid', 3, false, 'S edition Competition'],
            ['Audi', 'A4', 'Avant 40 TFSI S line Competition', 3, true, 'S line Competition'],
            ['Audi', 'A4', 'Avant 35 TFSI Pro Line S', 3, false, 'Pro Line S'],
            ['Mercedes-Benz', 'C-klasse', '300e AMG Line Premium Plus', 3, true, 'AMG Line Premium Plus'],
            ['Skoda', 'Superb', 'Combi 1.5 TSI Business Edition Plus', 2, false, 'Business Edition Plus'],
            ['Škoda', 'Superb', 'Combi 1.4 TSI iV L&K', 3, false, 'L&K'],

            // Spelling variants: hyphen, space, "+", case.
            ['Volkswagen', 'Arteon', '2.0 TSI R Line Virtual Pano', 2, true, 'R-Line'],
            ['Volkswagen', 'Passat', 'Variant 1.5 eHybrid R-Line Business+ | Trekhaak', 2, true, 'R-Line Business+'],
            ['BMW', '3-serie', 'Touring 318i High Executive | M-sport | Panoramadak', 3, true, 'High Executive + M Sport'],
            ['Mercedes-Benz', 'E-klasse', 'Estate 300 e 319PK AMG-LINE AUTOMAAT NAVI / LEDER', 2, true, 'AMG Line'],
            ['Mercedes-Benz', 'C-klasse', '300e AMG Premium+ Soh 98%/PANO/VIRTUAL/360-CAMERA', 3, true, 'AMG Premium Plus'],

            // "Zonder R-Line": the exclusion beats the longer term.
            ['Volkswagen', 'Passat', 'Variant Elegance R-Line', 2, true, 'R-Line'],
            ['Volkswagen', 'Passat', 'Variant 1.5 TSI Elegance', 1, false, 'Elegance'],

            // R as a whole word only: the performance model.
            ['Volkswagen', 'Arteon', 'Shooting Brake 2.0 TSI R 4Motion', 3, true, 'R'],

            // "Business R" is the older R-Line Business, not the R model.
            ['Volkswagen', 'Passat', 'Variant 1.5 TSI Elegance Business R | Camera', 2, true, 'Business R'],
            ['Mercedes-Benz', 'C-klasse', 'Estate 200 Business Solution AMG | Rij assistentie', 2, true, 'Business Solution AMG'],
            ['Mercedes-Benz', 'C-klasse', 'Estate 200 Business Solution | Trekhaak', 1, false, 'Business Solution'],

            // Performance models, also through the model name.
            ['BMW', 'X3', 'M40i xDrive High Executive | Pano | ACC | HUD', 3, true, 'M40i'],
            ['Audi', 'S4', 'Avant 3.0 TFSI quattro', 3, true, 'S4'],

            // Three option points add a level to a recognised line, but never above 3.
            ['BMW', '3-serie', '318i Business Edition | Pano | Leder | Trekhaak', 2, false, 'Business Edition'],
            ['Volvo', 'XC60', 'B5 Ultimate | Pano | Leder | Harman Kardon | Trekhaak', 3, false, 'Ultimate'],

            // Without a line: 0-1 points 1, 2 points 2.
            ['Volvo', 'XC60', 'T6 Recharge Ultra Dark', 1, true, null],
            ['Volkswagen', 'Passat', '2.0 TDI | Pano | Trekhaak', 2, false, null],
            // A brand not in the table: options and the general sport terms only.
            ['Peugeot', '508', 'SW 1.6 Black Edition | Pano', 1, true, null],
        ];
    }

    #[DataProvider('titles')]
    public function test_classification(string $brand, string $model, string $title, int $level, bool $sport, ?string $line): void
    {
        $result = app(Classifier::class)->classify($brand, $model, $title);

        $this->assertSame($level, $result['comfort_niveau'], "{$title}: niveau");
        $this->assertSame($sport, $result['sport'], "{$title}: sport");
        $this->assertSame($line, $result['uitvoeringslijn'], "{$title}: lijn");
    }

    public function test_each_option_counts_once_and_options_are_listed(): void
    {
        $result = app(Classifier::class)->classify('Volvo', 'V60', 'B4 | Panoramadak | Pano | Leder | Nappa | 360° camera | Head-up display');

        $this->assertSame(['pano', 'leer', 'hud', 'camera360'], $result['uitrusting_opties']);
    }

    public function test_words_match_whole_words_only(): void
    {
        // "acc" in "accu", "pano" in "panoramisch"-like words, "leer" in "leeruitvoering": no points.
        $result = app(Classifier::class)->classify('Volvo', 'V60', 'B3 accu 98% | Trekhaakvoorbereiding | Hudson');

        $this->assertSame([], $result['uitrusting_opties']);
    }

    public function test_the_version_follows_the_table(): void
    {
        $config = config('uitrusting');
        $a = new Classifier($config);
        $config['lijnen']['volvo']['Ultra'] = 3;
        $b = new Classifier($config);

        $this->assertNotSame($a->version(), $b->version());
        $this->assertSame(3, $b->classify('Volvo', 'XC60', 'T6 Recharge Ultra Dark')['comfort_niveau']);
    }
}
