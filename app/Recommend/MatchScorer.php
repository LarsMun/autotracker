<?php

namespace App\Recommend;

/**
 * How well a car fits the wensen: the filters it must pass, and its rapportcijfer: per vak a grade
 * from 1 to 10, averaged with the vakken's importance.
 *
 * Every vak grades on one straight line through two anchor points, a 6 and a 10, kept within 1–10.
 * For km and bouwjaar the 6 is the filter limit, so a car on the limit is "net voldoende".
 */
final class MatchScorer
{
    public function __construct(private array $wensen) {}

    /**
     * The reasons the car falls out; empty when it passes every filter.
     *
     * @return list<string>
     */
    public function rejections(array $car): array
    {
        $f = $this->wensen['filters'];
        $reasons = [];

        if ($car['prijs'] === null || $car['prijs'] < $f['prijs_min'] || $car['prijs'] > $f['prijs_max']) {
            $reasons[] = 'prijs buiten je range';
        }

        if ($car['kilometerstand'] === null || $car['kilometerstand'] > $f['km_max']) {
            $reasons[] = 'te veel km';
        }

        if ($car['bouwjaar'] === null || $car['bouwjaar'] < $f['bouwjaar_min']) {
            $reasons[] = 'te oud';
        }

        if ($f['merken'] !== [] && ! in_array($car['merk'], $f['merken'], true)) {
            $reasons[] = 'ander merk';
        } elseif (self::modelsOf($car['merk'], $f['modellen']) !== [] && ! in_array("{$car['merk']}|{$car['model']}", $f['modellen'], true)) {
            // A chosen make without chosen models: all its models pass.
            $reasons[] = 'ander model';
        }

        $colour = $car['kleur'] ?? null;

        if ($f['kleuren'] !== [] && ($colour === null ? ! $f['onbekende_kleur'] : ! in_array($colour, $f['kleuren'], true))) {
            $reasons[] = $colour === null ? 'kleur onbekend' : 'andere kleur';
        }

        if ($f['sport'] !== 'maakt_niet_uit' && (bool) ($car['sport'] ?? false) !== ($f['sport'] === 'ja')) {
            $reasons[] = $f['sport'] === 'ja' ? 'niet sportief' : 'sportief';
        }

        if ($f['alleen_merkdealer'] && ! self::isBrandDealer($car)) {
            $reasons[] = 'geen merkdealer of merkgarantie';
        }

        return $reasons;
    }

    /**
     * @param  array{deal_pct: ?float, check_dit: bool}|null  $deal
     * @return array<string, ?float> vak => grade 1–10; null when the car gives nothing to grade
     */
    public function grades(array $car, ?array $deal): array
    {
        $v = $this->wensen['vakken'];
        $f = $this->wensen['filters'];

        return [
            'deal' => self::deal($deal['deal_pct'] ?? null, (bool) ($deal['check_dit'] ?? false), (float) $v['deal']['tien']),
            'km' => $car['kilometerstand'] === null ? null : self::line($car['kilometerstand'], $f['km_max'], $v['km']['tien']),
            'bouwjaar' => $car['bouwjaar'] === null ? null : self::line($car['bouwjaar'], $f['bouwjaar_min'], $v['bouwjaar']['tien']),
            'luxe' => self::luxe($car['comfort_niveau'] ?? null, $car['uitvoeringslijn'] ?? null),
            'vermogen' => ($car['pk'] ?? null) === null ? null : self::line($car['pk'], $v['vermogen']['min'], $v['vermogen']['tien']),
        ];
    }

    /**
     * The weighted average of the known grades, to one decimal; null when no vak counts.
     *
     * @param  array<string, ?float>  $grades
     */
    public function cijfer(array $grades): ?float
    {
        $sum = $weights = 0;

        foreach (Wensen::weights($this->wensen) as $vak => $weight) {
            if ($weight > 0 && ($grades[$vak] ?? null) !== null) {
                $sum += $weight * $grades[$vak];
                $weights += $weight;
            }
        }

        return $weights > 0 ? round($sum / $weights, 1) : null;
    }

    /**
     * 6 at the market price, 10 at $tien percent below it. None, or one marked "Check dit": no
     * grade, so a suspiciously cheap car is no top deal.
     */
    public static function deal(?float $pct, bool $check, float $tien): ?float
    {
        return $pct === null || $check ? null : self::line($pct, 0, $tien);
    }

    /**
     * Comfort level 3 a 10, 2 a 7, 1 a 4. Without a recognised line the level is a guess: no grade.
     */
    public static function luxe(?int $level, ?string $line): ?float
    {
        return $level === null || $line === null ? null : [1 => 4.0, 2 => 7.0, 3 => 10.0][max(1, min(3, $level))];
    }

    /**
     * The straight line through ($six, 6) and ($ten, 10), either direction, kept within 1–10.
     */
    public static function line(float $value, float $six, float $ten): float
    {
        if ($six == $ten) {
            return 10.0;
        }

        return round(max(1, min(10, 6 + 4 * ($value - $six) / ($ten - $six))), 1);
    }

    /**
     * @param  list<string>  $models  "merk|model"
     * @return list<string> the chosen models of this make
     */
    public static function modelsOf(?string $make, array $models): array
    {
        return array_values(array_filter($models, fn ($key) => str_starts_with($key, "{$make}|")));
    }

    public static function isBrandDealer(array $car): bool
    {
        return ($car['aanbieder_type'] ?? null) === 'merkdealer' || ($car['garantie'] ?? null) === 'MERKGARANTIE';
    }
}
