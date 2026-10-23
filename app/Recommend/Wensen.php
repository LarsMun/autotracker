<?php

namespace App\Recommend;

use Illuminate\Support\Facades\DB;

/**
 * The one set of preferences, stored in the settings table: the filters of "Voor jou" (which cars
 * it lists) and the vakken of the rapportcijfer ("Mijn wensen"), each with an importance and the
 * values that earn a 6 and a 10.
 */
final class Wensen
{
    public const string KEY = 'wensen';

    /** The stored layout; older ones are converted on reading. */
    public const int VERSIE = 6;

    /** The vakken of the rapportcijfer, in the order the pages show them. */
    public const array VAKKEN = ['deal', 'km', 'bouwjaar', 'luxe', 'vermogen'];

    /** The weights the page offers: niet, beetje, belangrijk, heel belangrijk. */
    public const array BELANG = [0, 1, 3, 5];

    /** The filters that are lists; an empty one lets everything through. */
    public const array LIJSTEN = ['merken', 'modellen', 'luxe', 'kleuren'];

    /** The filters that are a range from–to; a bound that is null is not set. */
    public const array BEREIKEN = ['prijs', 'bouwjaar', 'km', 'pk'];

    public static function defaults(): array
    {
        return [
            'versie' => self::VERSIE,
            // Every list empty and every bound null: every car.
            'filters' => [
                'merken' => [],
                // "merk|model"; a chosen make without chosen models: all its models.
                'modellen' => [],
                'prijs_min' => null,
                'prijs_max' => null,
                'bouwjaar_min' => null,
                'bouwjaar_max' => null,
                'km_min' => null,
                'km_max' => null,
                // Comfort levels 1–3.
                'luxe' => [],
                // Only sport models.
                'sport' => false,
                'pk_min' => null,
                'pk_max' => null,
                // Colours as Gaspedaal writes them ("ZWART").
                'kleuren' => [],
                // Whether a car without a colour passes a colour filter.
                'onbekende_kleur' => true,
                'alleen_merkdealer' => false,
            ],
            // Per vak its importance, the value that earns a 6 and the value that earns a 10.
            'vakken' => [
                // Percent below the market: 0 (the market price) a 6.
                'deal' => ['belang' => 5, 'tien' => 10],
                'km' => ['belang' => 3, 'zes' => 80000, 'tien' => 10000],
                'bouwjaar' => ['belang' => 3, 'zes' => 2022, 'tien' => (int) date('Y')],
                // Comfort level 3 a 10, 2 a 7, 1 a 4.
                'luxe' => ['belang' => 3],
                'vermogen' => ['belang' => 3, 'zes' => 150, 'tien' => 200],
            ],
        ];
    }

    public static function get(): array
    {
        $stored = DB::table('settings')->where('key', self::KEY)->value('value');
        $stored = self::upgrade($stored ? json_decode($stored, true) : [], fn () => self::knownColours());

        // Lists replace the defaults, they do not merge into them by index.
        $wensen = array_replace_recursive(self::defaults(), $stored);

        foreach (self::LIJSTEN as $list) {
            $wensen['filters'][$list] = array_values($stored['filters'][$list] ?? []);
        }

        return $wensen;
    }

    /** Only the filters, keeping the vakken. */
    public static function saveFilters(array $filters): void
    {
        self::save(['filters' => $filters] + self::get());
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
     * @return array<string, int> vak => weight
     */
    public static function weights(array $wensen): array
    {
        return array_map(fn ($vak) => (int) $vak['belang'], array_intersect_key($wensen['vakken'], array_flip(self::VAKKEN)));
    }

    /**
     * Older stored wensen, up to the current version.
     *
     * @param  callable(): list<string>  $colours  the colours known now, to turn excluded ones into a selection
     */
    public static function upgrade(array $stored, callable $colours): array
    {
        if ($stored === []) {
            return $stored;
        }

        if (($stored['versie'] ?? 1) < 4) {
            $stored = self::upgradeTo4($stored, $colours);
        }

        if ($stored['versie'] < 5) {
            $stored = self::upgradeTo5($stored);
        }

        if ($stored['versie'] < 6) {
            $stored = self::upgradeTo6($stored);
        }

        return $stored;
    }

    /**
     * Version 5 chose bouwjaren one by one and km in steps of 10.000; now both are a range. A list
     * up to next year or a first step at 0 km was no bound on that side.
     */
    private static function upgradeTo6(array $stored): array
    {
        $f = $stored['filters'];
        $years = $f['bouwjaren'];
        $steps = $f['km'];
        unset($f['bouwjaren'], $f['km']);

        $ranges = [
            'bouwjaar_min' => $years ? min($years) : null,
            'bouwjaar_max' => $years && max($years) <= (int) date('Y') ? max($years) : null,
            'km_min' => $steps && min($steps) > 0 ? min($steps) : null,
            'km_max' => $steps ? max($steps) + 10000 : null,
        ];

        // In the order of defaults().
        $order = array_keys(self::defaults()['filters']);

        return ['versie' => 6, 'filters' => array_merge(array_flip($order), $f, $ranges), 'vakken' => $stored['vakken']];
    }

    /**
     * Version 4 had a minimum year and a maximum km as filters, which were also the 6 of those
     * vakken. Now the filters select years and steps of km, and every vak has its own 6. Sport
     * "nee" is gone: the filter only asks for sport models.
     */
    private static function upgradeTo5(array $stored): array
    {
        $f = $stored['filters'];
        $v = $stored['vakken'];
        $kmMax = (int) $f['km_max'];

        return [
            'versie' => 5,
            'filters' => [
                'merken' => $f['merken'],
                'modellen' => $f['modellen'],
                'prijs_min' => ($f['prijs_min'] ?? 0) > 0 ? (int) $f['prijs_min'] : null,
                'prijs_max' => (int) $f['prijs_max'],
                // Up to next year: Gaspedaal already lists some cars of next year's model.
                'bouwjaren' => range((int) $f['bouwjaar_min'], max((int) $f['bouwjaar_min'], (int) date('Y') + 1)),
                // Every step that starts below the maximum.
                'km' => range(0, max(0, (int) (ceil($kmMax / 10000) - 1) * 10000), 10000),
                'luxe' => [],
                'sport' => $f['sport'] === 'ja',
                'pk_min' => null,
                'pk_max' => null,
                'kleuren' => $f['kleuren'],
                'onbekende_kleur' => $f['onbekende_kleur'],
                'alleen_merkdealer' => $f['alleen_merkdealer'],
            ],
            'vakken' => [
                'deal' => $v['deal'],
                'km' => ['belang' => $v['km']['belang'], 'zes' => $kmMax, 'tien' => $v['km']['tien']],
                'bouwjaar' => ['belang' => $v['bouwjaar']['belang'], 'zes' => (int) $f['bouwjaar_min'], 'tien' => $v['bouwjaar']['tien']],
                'luxe' => $v['luxe'],
                'vermogen' => ['belang' => $v['vermogen']['belang'], 'zes' => $v['vermogen']['min'], 'tien' => $v['vermogen']['tien']],
            ],
        ];
    }

    /**
     * Up to version 3 there were hard requirements, soft wishes scored 0–100 and
     * a match/deal split; version 4 has filters and the vakken of a rapportcijfer. Soft wishes that
     * became filters (model order, colour preference) are dropped, exclusions become the matching
     * selection.
     *
     * @param  callable(): list<string>  $colours
     */
    private static function upgradeTo4(array $stored, callable $colours): array
    {
        // Version 4's defaults, which version 5 starts from.
        $d = ['vakken' => ['deal' => ['tien' => 10], 'km' => ['belang' => 3, 'tien' => 10000], 'bouwjaar' => ['belang' => 3, 'tien' => (int) date('Y')], 'vermogen' => ['min' => 150]]];
        $e = ($stored['eisen'] ?? []) + ['prijs_max' => 40000, 'km_max' => 80000, 'bouwjaar_min' => 2022];
        $g = $stored['gewichten'] ?? [];
        $belang = fn (string $wish, int $default) => array_key_exists($wish, $g) ? self::belang((int) $g[$wish]) : $default;

        // "Iets ruimer" is now part of the limit itself.
        $kmMax = ! empty($e['km_ruimer']) ? (int) round($e['km_max'] * 1.1) : (int) $e['km_max'];
        $yearMin = (int) $e['bouwjaar_min'] - (! empty($e['bouwjaar_ruimer']) ? 1 : 0);

        [$merken, $modellen] = self::selectionFrom($stored['modellen'] ?? [], $e['uitsluiten'] ?? []);

        $excluded = $e['kleuren_uitsluiten'] ?? [];
        $kleuren = $excluded === [] ? [] : array_values(array_diff($colours(), $excluded));

        // Sport only became a filter when it counted.
        $sport = $belang('sport', 0) > 0 ? ($stored['sport'] ?? 'ja') : 'maakt_niet_uit';
        $sport = in_array($sport, ['ja', 'nee'], true) ? $sport : 'maakt_niet_uit';

        $pkMin = (int) ($stored['pk_min'] ?? $d['vakken']['vermogen']['min']);

        return [
            'versie' => 4,
            'filters' => [
                'prijs_min' => 0,
                'prijs_max' => (int) $e['prijs_max'],
                'km_max' => $kmMax,
                'bouwjaar_min' => $yearMin,
                'merken' => $merken,
                'modellen' => $modellen,
                'kleuren' => $kleuren,
                'onbekende_kleur' => true,
                'sport' => $sport,
                'alleen_merkdealer' => (bool) ($e['alleen_merkdealer'] ?? false),
            ],
            'vakken' => [
                'deal' => ['belang' => self::dealBelang((float) ($stored['w'] ?? 0.7))] + $d['vakken']['deal'],
                'km' => $d['vakken']['km'],
                'bouwjaar' => $d['vakken']['bouwjaar'],
                'luxe' => ['belang' => $belang('comfort', 3)],
                'vermogen' => ['belang' => $belang('pk', 3), 'min' => $pkMin, 'tien' => $pkMin + 50],
            ],
        ];
    }

    /**
     * The old model list held every model that was online, in order of preference, without the
     * excluded ones. A make none of whose models was excluded becomes the whole make.
     *
     * @return array{0: list<string>, 1: list<string>} merken, modellen
     */
    private static function selectionFrom(array $order, array $excluded): array
    {
        if ($excluded === []) {
            return [[], []];
        }

        $make = fn (string $key) => explode('|', $key, 2)[0];
        $merken = array_values(array_unique(array_map($make, array_diff($order, $excluded))));
        sort($merken);
        $partial = array_unique(array_map($make, $excluded));
        $modellen = array_values(array_filter(array_diff($order, $excluded), fn ($key) => in_array($make($key), $partial, true)));
        sort($modellen);

        return [$merken, $modellen];
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

    /** The old match share (1 match only … 0.5 half deal) as the importance of the deal. */
    private static function dealBelang(float $w): int
    {
        return match (true) {
            $w >= 0.95 => 0,
            $w >= 0.8 => 1,
            $w >= 0.6 => 3,
            default => 5,
        };
    }

    /** @return list<string> */
    private static function knownColours(): array
    {
        return DB::table('listings')->whereNotNull('kleur')->distinct()->orderBy('kleur')->pluck('kleur')->all();
    }
}
