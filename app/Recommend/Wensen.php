<?php

namespace App\Recommend;

use Illuminate\Support\Facades\DB;

/**
 * The one set of preferences ("Mijn wensen"), stored in the settings table: filters a car must pass,
 * and the vakken of its rapportcijfer, each with an importance and its own anchor points.
 */
final class Wensen
{
    public const string KEY = 'wensen';

    /** The stored layout; older ones are converted on reading. */
    public const int VERSIE = 4;

    /** The vakken of the rapportcijfer, in the order the pages show them. */
    public const array VAKKEN = ['deal', 'km', 'bouwjaar', 'luxe', 'vermogen'];

    /** The weights the page offers: niet, beetje, belangrijk, heel belangrijk. */
    public const array BELANG = [0, 1, 3, 5];

    /** Sport filter: only sport models, none, or either. */
    public const array SPORT = ['ja', 'nee', 'maakt_niet_uit'];

    public static function defaults(): array
    {
        return [
            'versie' => self::VERSIE,
            'filters' => [
                'prijs_min' => 0,
                'prijs_max' => 40000,
                'km_max' => 80000,
                'bouwjaar_min' => 2022,
                // None chosen: every make. A make without chosen models: all its models.
                'merken' => [],
                // "merk|model"
                'modellen' => [],
                // Colours as Gaspedaal writes them ("ZWART"); none chosen: every colour.
                'kleuren' => [],
                // Whether a car without a colour passes a colour filter.
                'onbekende_kleur' => true,
                'sport' => 'maakt_niet_uit',
                'alleen_merkdealer' => false,
            ],
            // Per vak its importance and what earns a 10; the 6 is the matching filter limit.
            'vakken' => [
                // A 10 at this percentage below the market, a 6 at the market price.
                'deal' => ['belang' => 5, 'tien' => 10],
                // A 10 at this many km or fewer, a 6 at filters.km_max.
                'km' => ['belang' => 3, 'tien' => 10000],
                // A 10 from this year, a 6 at filters.bouwjaar_min.
                'bouwjaar' => ['belang' => 3, 'tien' => (int) date('Y')],
                // Comfort level 3 a 10, 2 a 7, 1 a 4.
                'luxe' => ['belang' => 3],
                // A 10 from this many pk, a 6 at min.
                'vermogen' => ['belang' => 3, 'min' => 150, 'tien' => 200],
            ],
        ];
    }

    public static function get(): array
    {
        $stored = DB::table('settings')->where('key', self::KEY)->value('value');
        $stored = self::upgrade($stored ? json_decode($stored, true) : [], fn () => self::knownColours());

        // Lists replace the defaults, they do not merge into them by index.
        $wensen = array_replace_recursive(self::defaults(), $stored);

        foreach (['merken', 'modellen', 'kleuren'] as $list) {
            $wensen['filters'][$list] = array_values($stored['filters'][$list] ?? $wensen['filters'][$list]);
        }

        return $wensen;
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
     * Older stored wensen. Up to version 3 there were hard requirements, soft wishes scored 0–100 and
     * a match/deal split; version 4 has filters and the vakken of a rapportcijfer. Soft wishes that
     * became filters (model order, colour preference) are dropped, exclusions become the matching
     * selection.
     *
     * @param  callable(): list<string>  $colours  the colours known now, to turn excluded ones into a selection
     */
    public static function upgrade(array $stored, callable $colours): array
    {
        if ($stored === [] || ($stored['versie'] ?? 1) >= self::VERSIE) {
            return $stored;
        }

        $d = self::defaults();
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
            'versie' => self::VERSIE,
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
