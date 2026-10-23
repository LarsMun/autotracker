<?php

namespace App\Gaspedaal;

use App\Models\Listing;
use Illuminate\Support\Facades\DB;

/**
 * Particulier, merkdealer or handelaar, derived per car. Gaspedaal itself only knows "particulier"
 * and "autobedrijf"; a merkdealer is an autobedrijf that shows it sells for the car's own brand.
 *
 * - particulier: found with vrk=PARTICULIER; before that filter ran, the "$undefined" name and
 *   province with which Gaspedaal shows a private seller.
 * - merkdealer: offered with merkgarantie, or through the importer's portal of the same brand
 *   (Volvo has one; BMW and Volkswagen have none, so there only merkgarantie shows it). Also any
 *   other car of that brand from the same dealer (dealer_slug), once one of them shows it.
 * - handelaar: every other autobedrijf.
 */
class SellerType
{
    public const string PARTICULIER = 'particulier';

    public const string MERKDEALER = 'merkdealer';

    public const string HANDELAAR = 'handelaar';

    /**
     * Derives the type of every car again; cheap enough to do after each run.
     */
    public function refresh(): void
    {
        $cars = DB::table('listings')->get(['id', 'merk', 'dealer_slug', 'importeur_portaal', 'zonder_aanbieder', 'particulier', 'garantie', 'aanbieder_type']);

        $isPrivate = fn ($c) => $c->particulier === null ? (bool) $c->zonder_aanbieder : (bool) $c->particulier;
        $shows = fn ($c) => $c->garantie === 'MERKGARANTIE'
            || ($c->importeur_portaal !== null && mb_strtolower($c->importeur_portaal) === mb_strtolower((string) $c->merk));

        // Dealers known to sell a brand as its dealer: "slug|brand".
        $brandDealers = [];

        foreach ($cars as $c) {
            if ($c->dealer_slug && ! $isPrivate($c) && $shows($c)) {
                $brandDealers[$c->dealer_slug.'|'.mb_strtolower((string) $c->merk)] = true;
            }
        }

        $byType = [];

        foreach ($cars as $c) {
            $type = match (true) {
                $isPrivate($c) => self::PARTICULIER,
                $shows($c), isset($brandDealers[$c->dealer_slug.'|'.mb_strtolower((string) $c->merk)]) => self::MERKDEALER,
                default => self::HANDELAAR,
            };

            if ($type !== $c->aanbieder_type) {
                $byType[$type][] = $c->id;
            }
        }

        foreach ($byType as $type => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                Listing::whereKey($chunk)->update(['aanbieder_type' => $type]);
            }
        }
    }
}
