<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Uitrusting\Classifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('auto:uitrusting')]
#[Description('Classify the comfort level and sport of every car again, after config/uitrusting.php changed')]
class ClassifyUitrusting extends Command
{
    public function handle(Classifier $classifier): int
    {
        $changed = 0;

        Listing::query()->select(['id', 'merk', 'model', 'uitvoering', 'comfort_niveau', 'sport', 'uitvoeringslijn', 'uitrusting_opties', 'uitrusting_versie'])
            ->chunkById(500, function ($listings) use ($classifier, &$changed) {
                foreach ($listings as $listing) {
                    $listing->fill($classifier->classify($listing->merk, $listing->model, $listing->uitvoering));

                    if ($listing->isDirty(['comfort_niveau', 'sport', 'uitvoeringslijn', 'uitrusting_opties'])) {
                        $changed++;
                    }

                    $listing->save();
                }
            });

        $levels = Listing::query()->selectRaw('comfort_niveau, count(*) n')->groupBy('comfort_niveau')->pluck('n', 'comfort_niveau');
        $this->info(sprintf(
            '%d auto\'s geclassificeerd, %d gewijzigd. Niveau 1: %d, 2: %d, 3: %d. Sport: %d. Zonder herkende lijn: %d.',
            Listing::count(), $changed, $levels[1] ?? 0, $levels[2] ?? 0, $levels[3] ?? 0,
            Listing::where('sport', true)->count(), Listing::whereNull('uitvoeringslijn')->count(),
        ));

        return self::SUCCESS;
    }
}
