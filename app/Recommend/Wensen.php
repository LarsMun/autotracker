<?php

namespace App\Recommend;

use Illuminate\Support\Facades\DB;

/**
 * The one set of preferences ("Mijn wensen"), stored in the settings table.
 */
final class Wensen
{
    public const string KEY = 'wensen';

    /** The stored layout; older ones are converted on reading. */
    public const int VERSIE = 3;

    /** The soft wishes, in the order the page shows them. */
    public const array SOFT = ['prijs', 'comfort', 'sport', 'merkdealer', 'pk', 'model', 'kleur'];

    /** Wishes that always count, with a weight the page does not offer. */
    public const array VAST = ['km' => 2, 'bouwjaar' => 2];

    /** The weights the page offers: niet, beetje, belangrijk, heel belangrijk. */
    public const array BELANG = [0, 1, 3, 5];

    /** "Goede deal" per importance: the weight of the match in the total score, the rest is the deal. */
    public const array DEAL = [0 => 1.0, 1 => 0.85, 3 => 0.7, 5 => 0.5];

    public static function defaults(): array
    {
        return [
            'versie' => self::VERSIE,
            'eisen' => [
                'prijs_max' => 40000,
                'km_max' => 80000,
                // "Iets ruimer": up to 10% more km passes.
                'km_ruimer' => false,
                'bouwjaar_min' => 2022,
                // "Iets ruimer": one year older passes.
                'bouwjaar_ruimer' => false,
                // "merk|model"
                'uitsluiten' => [],
                // Colours as Gaspedaal writes them ("ZWART"); an unknown colour is never excluded.
                'kleuren_uitsluiten' => [],
                'alleen_merkdealer' => false,
            ],
            'gewichten' => ['prijs' => 3, 'comfort' => 3, 'sport' => 0, 'merkdealer' => 1, 'pk' => 3, 'model' => 3, 'kleur' => 3],
            'comfort_doel' => 2,
            // ja or nee; not caring is weight 0
            'sport' => 'ja',
            'pk_min' => 150,
            // "merk|model", most wanted first
            'modellen' => [],
            // Preferred colours; none chosen and the colour does not count.
            'kleuren' => [],
            // Weight of the match in the total score; the rest is the deal score. One of DEAL.
            'w' => 0.7,
        ];
    }

    public static function get(): array
    {
        $stored = DB::table('settings')->where('key', self::KEY)->value('value');

        return array_replace_recursive(self::defaults(), self::upgrade($stored ? json_decode($stored, true) : []));
    }

    public static function save(array $wensen): void
    {
        $now = now();

        DB::table('settings')->upsert(
            [['key' => self::KEY, 'value' => json_encode(['versie' => self::VERSIE] + $wensen), 'created_at' => $now, 'updated_at' => $now]],
            ['key'],
            ['value', 'updated_at'],
        );
    }

    /**
     * Older stored wensen. Version 1, from before the importance buttons: weights 0–5 to 0/1/3/5 and
     * the match share to the nearest "Goede deal" step. Version 2: km and age had their own wish and
     * importance; they now always count with a fixed weight.
     */
    public static function upgrade(array $stored): array
    {
        $versie = $stored['versie'] ?? 1;

        if ($stored === [] || $versie >= self::VERSIE) {
            return $stored;
        }

        if ($versie < 2) {
            $stored = self::upgradeFrom1($stored);
        }

        unset($stored['km_doel'], $stored['bouwjaar_doel'], $stored['gewichten']['km'], $stored['gewichten']['leeftijd']);
        $stored['versie'] = self::VERSIE;

        return $stored;
    }

    private static function upgradeFrom1(array $stored): array
    {
        foreach ($stored['gewichten'] ?? [] as $wish => $weight) {
            $stored['gewichten'][$wish] = self::belang((int) $weight);
        }

        if (isset($stored['w'])) {
            $stored['w'] = self::nearestDeal((float) $stored['w']);
        }

        // "Maakt niet uit" is now importance "niet".
        if (($stored['sport'] ?? null) === 'maakt_niet_uit') {
            $stored['sport'] = 'ja';
            $stored['gewichten']['sport'] = 0;
        }

        return $stored;
    }

    /** An old weight 0–5 as importance: 0 niet, 1 beetje, 2–3 belangrijk, 4–5 heel belangrijk. */
    public static function belang(int $weight): int
    {
        return match (true) {
            $weight <= 0 => 0,
            $weight === 1 => 1,
            $weight <= 3 => 3,
            default => 5,
        };
    }

    public static function nearestDeal(float $w): float
    {
        return collect(self::DEAL)->sortBy(fn ($step) => abs($step - $w))->first();
    }

    /**
     * The weights the match uses: the chosen ones plus km and year, and the brand dealer wish counts
     * for nothing when it is a requirement.
     *
     * @return array<string, int>
     */
    public static function weights(array $wensen): array
    {
        $weights = self::VAST + $wensen['gewichten'];

        if (! empty($wensen['eisen']['alleen_merkdealer'])) {
            $weights['merkdealer'] = 0;
        }

        return $weights;
    }
}
