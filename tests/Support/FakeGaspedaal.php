<?php

namespace Tests\Support;

/**
 * Builds pages in Gaspedaal's format: the data split over several __next_f pushes, as the site does.
 */
class FakeGaspedaal
{
    public static function occasion(int $id, int $price = 30000, int $km = 50000): array
    {
        return [
            'advertentieId' => $id,
            'datumGeplaatst' => '2026-10-01T08:00:00.000Z',
            'aanbieder' => ['aanbiedergegevens' => ['naamsvermelding' => 'Garage [x] "y"', 'plaatsnaam' => 'Utrecht', 'provincieHulpwaarde' => 'UT']],
            'autogegevens' => [
                'algemeen' => ['merknaam' => 'BMW', 'modelnaam' => '3-serie', 'uitvoering' => 'Touring 330e {M Sport}', 'brandstofsoort' => 'HYBRIDE', 'transmissietype' => 'AUTOMATISCH', 'carrosserievorm' => 'STATIONWAGEN', 'kleur' => 'GRIJS', 'aantalDeuren' => 5],
                'geschiedenis' => ['bouwjaar' => 2021, 'kilometerstand' => $km],
                'motor' => ['motorinhoud' => 1998, 'vermogenKw' => 135],
            ],
            'prijs' => ['totaalInclusiefBtw' => $price],
            'gpFotos' => ['fotoKlein' => "https://cdn.gaspedaal.nl/images/small/{$id}.jpg", 'fotoGroot' => "https://cdn.gaspedaal.nl/images/small/{$id}.jpg"],
            'portalen' => [['klikUrl' => "https://api.gaspedaal.nl/redirect/vehicle/{$id}", 'siteNr' => 2, 'portaalBeschrijving' => 'AutoTrack']],
        ];
    }

    /**
     * @param  list<array>  $occasions
     */
    /**
     * @param  array<string, int>  $facets  the filter sidebar, e.g. ['grn=MERKGARANTIE' => 3]; filters left out show 0
     */
    public static function page(array $occasions, int $total, int $pageNumber = 1, int $pageSize = 2, bool $ld = true, array $facets = []): string
    {
        $pages = max(1, (int) ceil($total / $pageSize));
        $count = fn (string $key) => $facets[$key] ?? 0;
        $sidebar = json_encode([
            'soortAanbieder' => [
                ['value' => 'UNIVERSEEL_AUTOBEDRIJF', 'slug' => 'universeel-autobedrijf', 'count' => $total - $count('vrk=PARTICULIER')],
                ['value' => 'PARTICULIER', 'slug' => 'particulier', 'count' => $count('vrk=PARTICULIER')],
            ],
            'garantietype' => [
                ['value' => 'MERKGARANTIE', 'slug' => 'merkgarantie', 'count' => $count('grn=MERKGARANTIE')],
                ['value' => 'BOVAG', 'slug' => 'bovag', 'count' => $count('grn=BOVAG')],
            ],
            'btwVerrekenbaar' => [
                ['value' => true, 'slug' => 'true', 'count' => $count('btw=true')],
                ['value' => false, 'slug' => 'false', 'count' => $total - $count('btw=true')],
            ],
        ]);
        $data = '1:["$","div",null,{"children":"]]}{ not data"}]'."\n"
            .'3:{"filters":'.$sidebar.'}'."\n"
            .'2:{"x":1,"occasions":'.json_encode($occasions).',"commonPortalURLProps":{"sort":"df-a","totalOccasions":'.$total.',"pageSize":'.$pageSize.',"pageNumber":'.$pageNumber.'},"numberOfPages":'.$pages.'}';

        // Cut in three, wherever: the parts only make sense joined.
        $len = strlen($data);
        $parts = [substr($data, 0, intdiv($len, 3)), substr($data, intdiv($len, 3), intdiv($len, 3)), substr($data, 2 * intdiv($len, 3))];

        $html = '<html><head></head><body><script>self.__next_f.push([0])</script>';

        foreach ($parts as $part) {
            $html .= '<script>self.__next_f.push('.json_encode([1, $part]).')</script>';
        }

        if ($ld) {
            $items = array_map(fn ($o, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'item' => [
                '@id' => "https://www.gaspedaal.nl/x#{$o['advertentieId']}",
                'image' => "https://cdn.gaspedaal.nl/images/large/{$o['advertentieId']}.jpg",
                // Odd ids name their dealer, as ads through Dealersite do.
                'offers' => ['seller' => ['@type' => 'AutoDealer', 'name' => 'Onbekende dealer'] + ($o['advertentieId'] % 2 ? ['url' => "/autobedrijven/garage-{$o['advertentieId']}/aanbod"] : [])],
            ]], $occasions, array_keys($occasions));
            $html .= '<script type="application/ld+json">'.json_encode(['@context' => 'https://schema.org', '@type' => 'ItemList', 'itemListElement' => $items]).'</script>';
        }

        return $html.'</body></html>';
    }
}
