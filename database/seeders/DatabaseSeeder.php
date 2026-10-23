<?php

namespace Database\Seeders;

use App\Models\Source;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * The searches from BOUWOPDRACHT.md. Safe to run again: existing URLs are skipped.
     */
    public function run(): void
    {
        $sources = [
            'BMW 3-serie Touring 2020+' => 'https://www.gaspedaal.nl/bmw/3-serie/stationwagen?bmin=2020',
            'BMW 5-serie Touring 2020+' => 'https://www.gaspedaal.nl/bmw/5-serie/stationwagen?bmin=2020',
            'BMW X3 2020+' => 'https://www.gaspedaal.nl/bmw/x3?bmin=2020',
            'Volvo XC60 2020+' => 'https://www.gaspedaal.nl/volvo/xc60?bmin=2020',
            'Volvo V90 2020+' => 'https://www.gaspedaal.nl/volvo/v90?bmin=2020',
            'Volvo V60 2020+' => 'https://www.gaspedaal.nl/volvo/v60?bmin=2020',
        ];

        foreach ($sources as $name => $url) {
            Source::firstOrCreate(['url' => $url], ['name' => $name, 'active' => true]);
        }
    }
}
