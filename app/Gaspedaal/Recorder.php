<?php

namespace App\Gaspedaal;

use App\Jobs\DownloadPhoto;
use App\Models\Listing;
use App\Models\ListingSnapshot;
use App\Models\Observation;
use App\Uitrusting\Classifier;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Stores what one run saw of one car: its properties, the link to the source, the raw JSON (only
 * when it changed) and the price and km of the day.
 */
class Recorder
{
    public function __construct(private Classifier $classifier) {}

    /**
     * @param  array<string, mixed>  $occasion  the raw occasion object
     * @return array{listing_id: int, new: bool} the listing, and whether the car is new for this source
     */
    /**
     * @param  array{image: ?string, seller_slug: ?string}|null  $ld  this car in the page's ld+json
     */
    public function record(int $sourceId, CarbonInterface $date, array $occasion, ?array $ld = null): array
    {
        $day = $date->toDateString();
        $now = now();

        return DB::transaction(function () use ($sourceId, $day, $now, $occasion, $ld) {
            $attributes = $this->attributes($occasion, $ld);
            $listing = Listing::where('advertentie_id', $attributes['advertentie_id'])->lockForUpdate()->first();
            $isNewListing = $listing === null;

            if ($isNewListing) {
                $listing = Listing::create($attributes + $this->uitrusting($attributes) + ['first_seen' => $day, 'last_seen' => $day]);
            } else {
                $listing->fill($attributes);
                $listing->gone_at = null;

                // Classified again when the title changed, or the table did.
                if ($listing->isDirty(['merk', 'model', 'uitvoering']) || $listing->uitrusting_versie !== $this->classifier->version()) {
                    $listing->fill($this->uitrusting($attributes));
                }

                if ($listing->last_seen->toDateString() < $day) {
                    $listing->last_seen = $day;
                }

                $listing->save();
            }

            $isNewForSource = $this->link($sourceId, $listing->id, $day);
            $snapshotId = $this->snapshot($listing->id, $day, $occasion);

            Observation::upsert([[
                'listing_id' => $listing->id,
                'date' => $day,
                'prijs' => $attributes['prijs'],
                'kilometerstand' => $attributes['kilometerstand'],
                'snapshot_id' => $snapshotId,
                'seen_at' => $now,
            ]], ['listing_id', 'date'], ['prijs', 'kilometerstand', 'snapshot_id', 'seen_at']);

            if ($isNewListing && $listing->foto_url) {
                DownloadPhoto::dispatch($listing->id)->onQueue('photos')->afterCommit();
            }

            return ['listing_id' => $listing->id, 'new' => $isNewForSource];
        });
    }

    private function uitrusting(array $attributes): array
    {
        return $this->classifier->classify($attributes['merk'], $attributes['model'], $attributes['uitvoering']);
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(array $o, ?array $ld = null): array
    {
        $placed = Arr::get($o, 'datumGeplaatst');
        $name = Arr::get($o, 'aanbieder.aanbiedergegevens.naamsvermelding');
        $province = Arr::get($o, 'aanbieder.aanbiedergegevens.provincieHulpwaarde');
        $importer = collect(Arr::get($o, 'portalen', []))->firstWhere('portaalType', 'importeur');

        return [
            'advertentie_id' => (int) $o['advertentieId'],
            'merk' => Arr::get($o, 'autogegevens.algemeen.merknaam'),
            'model' => Arr::get($o, 'autogegevens.algemeen.modelnaam'),
            'uitvoering' => $this->limit(Arr::get($o, 'autogegevens.algemeen.uitvoering'), 500),
            'brandstof' => Arr::get($o, 'autogegevens.algemeen.brandstofsoort'),
            'transmissie' => Arr::get($o, 'autogegevens.algemeen.transmissietype'),
            'carrosserie' => Arr::get($o, 'autogegevens.algemeen.carrosserievorm'),
            'kleur' => $this->defined(Arr::get($o, 'autogegevens.algemeen.kleur')),
            'deuren' => $this->int(Arr::get($o, 'autogegevens.algemeen.aantalDeuren')),
            'bouwjaar' => $this->int(Arr::get($o, 'autogegevens.geschiedenis.bouwjaar')),
            'motorinhoud' => $this->int(Arr::get($o, 'autogegevens.motor.motorinhoud')),
            'vermogen_kw' => $this->int(Arr::get($o, 'autogegevens.motor.vermogenKw')),
            'aanbieder' => $this->limit($this->defined($name), 255),
            'plaats' => $this->defined(Arr::get($o, 'aanbieder.aanbiedergegevens.plaatsnaam')),
            'provincie' => $this->defined($province),
            'dealer_slug' => $ld['seller_slug'] ?? null,
            'importeur_portaal' => $importer['portaalBeschrijving'] ?? null,
            'zonder_aanbieder' => $name === '$undefined' && $province === '$undefined',
            'foto_url' => ($ld['image'] ?? null) ?: Arr::get($o, 'gpFotos.fotoGroot') ?: Arr::get($o, 'gpFotos.fotoKlein'),
            'datum_geplaatst' => $placed ? Carbon::parse($placed)->setTimezone(config('app.timezone')) : null,
            'prijs' => $this->int(Arr::get($o, 'prijs.totaalInclusiefBtw')),
            'kilometerstand' => $this->int(Arr::get($o, 'autogegevens.geschiedenis.kilometerstand')),
        ];
    }

    /**
     * @return bool whether the link is new
     */
    private function link(int $sourceId, int $listingId, string $day): bool
    {
        $link = DB::table('listing_source')
            ->where('source_id', $sourceId)
            ->where('listing_id', $listingId)
            ->first();

        if ($link === null) {
            DB::table('listing_source')->insert([
                'source_id' => $sourceId,
                'listing_id' => $listingId,
                'first_seen' => $day,
                'last_seen' => $day,
            ]);

            return true;
        }

        DB::table('listing_source')->where('id', $link->id)->update([
            'last_seen' => max($link->last_seen, $day),
            'gone_at' => null,
        ]);

        return false;
    }

    private function snapshot(int $listingId, string $day, array $occasion): int
    {
        $json = json_encode($occasion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = sha1($json);

        $snapshot = ListingSnapshot::where('listing_id', $listingId)->where('hash', $hash)->first();

        if ($snapshot === null) {
            return ListingSnapshot::insertGetId([
                'listing_id' => $listingId,
                'hash' => $hash,
                'data' => $json,
                'first_seen' => $day,
                'last_seen' => $day,
            ]);
        }

        if ($snapshot->last_seen->toDateString() < $day) {
            ListingSnapshot::where('id', $snapshot->id)->update(['last_seen' => $day]);
        }

        return $snapshot->id;
    }

    /**
     * Next.js writes a missing value as the string "$undefined".
     */
    private function defined(mixed $value): ?string
    {
        return $value === null || $value === '' || $value === '$undefined' ? null : (string) $value;
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function limit(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }
}
