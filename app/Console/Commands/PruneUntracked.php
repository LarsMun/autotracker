<?php

namespace App\Console\Commands;

use App\Models\Listing;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Cars no source follows any more (after a URL change) go, with their history, raw JSON and photo.
 * The raw pages on disk stay: they are the archive.
 */
#[Signature('auto:prune {--force : delete without asking}')]
#[Description('Delete the cars that no source follows any more, with their history')]
class PruneUntracked extends Command
{
    public function handle(): int
    {
        $query = Listing::whereDoesntHave('sources');
        $count = $query->count();

        if ($count === 0) {
            $this->info('Every car belongs to a source; nothing to delete.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("{$count} auto's zonder bron verwijderen, met hun prijshistorie en foto?")) {
            return self::SUCCESS;
        }

        $deleted = 0;

        $query->select(['id', 'foto_path'])->chunkById(500, function ($listings) use (&$deleted) {
            $photos = $listings->pluck('foto_path')->filter()->all();

            if ($photos !== []) {
                Storage::disk('public')->delete($photos);
            }

            // Observations and snapshots go with them (cascade).
            $deleted += Listing::whereKey($listings->modelKeys())->delete();
        });

        $this->info("{$deleted} auto's verwijderd.");

        return self::SUCCESS;
    }
}
