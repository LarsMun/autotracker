<?php

namespace App\Recommend;

/**
 * How well a car fits the wensen: hard requirements it must meet, and soft wishes that each give a
 * score of 0–100, averaged with their weights. With the deal score, a total score.
 */
final class MatchScorer
{
    /** "Iets ruimer": km up to this share above the maximum. */
    public const float KM_MARGE = 0.1;

    /** The score at the far end of the "iets ruimer" margin, for km and year alike. */
    public const int MARGE_SCORE = -50;

    /**
     * @param  ?int  $newestYear  the newest year among the cars that meet the requirements; full year score from there
     */
    public function __construct(private array $wensen, private ?int $newestYear = null) {}

    /** The highest km that passes: the maximum, or 10% above it with "iets ruimer". */
    public static function kmLimit(array $eisen): int
    {
        return ! empty($eisen['km_ruimer']) ? (int) round($eisen['km_max'] * (1 + self::KM_MARGE)) : (int) $eisen['km_max'];
    }

    /** The oldest year that passes: the minimum, or one year older with "iets ruimer". */
    public static function yearLimit(array $eisen): int
    {
        return (int) $eisen['bouwjaar_min'] - (! empty($eisen['bouwjaar_ruimer']) ? 1 : 0);
    }

    /**
     * The reasons the car falls out; empty when it meets every requirement.
     *
     * @param  array{merk: ?string, model: ?string, prijs: ?int, kilometerstand: ?int, bouwjaar: ?int, aanbieder_type: ?string, garantie: ?string}  $car
     * @return list<string>
     */
    public function rejections(array $car): array
    {
        $e = $this->wensen['eisen'];
        $reasons = [];

        if ($car['prijs'] === null || $car['prijs'] > $e['prijs_max']) {
            $reasons[] = 'prijs boven budget';
        }

        if ($car['kilometerstand'] === null || $car['kilometerstand'] > self::kmLimit($e)) {
            $reasons[] = 'te veel km';
        }

        if ($car['bouwjaar'] === null || $car['bouwjaar'] < self::yearLimit($e)) {
            $reasons[] = 'te oud';
        }

        if (in_array("{$car['merk']}|{$car['model']}", $e['uitsluiten'] ?? [], true)) {
            $reasons[] = 'model uitgesloten';
        }

        if (($car['kleur'] ?? null) !== null && in_array($car['kleur'], $e['kleuren_uitsluiten'] ?? [], true)) {
            $reasons[] = 'kleur uitgesloten';
        }

        if (! empty($e['alleen_merkdealer']) && ! self::isBrandDealer($car)) {
            $reasons[] = 'geen merkdealer of merkgarantie';
        }

        return $reasons;
    }

    /**
     * @return array<string, ?int> wish => score 0–100 (km and bouwjaar down to -50 in the margin); null when the wish has nothing to judge
     */
    public function scores(array $car): array
    {
        $e = $this->wensen['eisen'];

        return [
            'prijs' => self::price($car['prijs'], $e['prijs_max']),
            'km' => self::km($car['kilometerstand'], (int) $e['km_max']),
            'bouwjaar' => self::year($car['bouwjaar'], max($this->newestYear ?? $e['bouwjaar_min'], $e['bouwjaar_min']), (int) $e['bouwjaar_min']),
            'comfort' => self::comfort($car['comfort_niveau'] ?? null, (int) $this->wensen['comfort_doel']),
            'sport' => self::sport($car['sport'] ?? null, $this->wensen['sport']),
            'merkdealer' => self::isBrandDealer($car) ? 100 : 0,
            'pk' => self::power($car['pk'] ?? null, (int) $this->wensen['pk_min']),
            'model' => self::model("{$car['merk']}|{$car['model']}", $this->wensen['modellen'] ?? []),
            'kleur' => self::colour($car['kleur'] ?? null, $this->wensen['kleuren'] ?? []),
        ];
    }

    /**
     * The weighted average of the scores, kept within 0–100; 50 when every weight is 0. A null score
     * does not count.
     *
     * @param  array<string, ?int>  $scores
     */
    public function match(array $scores): float
    {
        $sum = $weights = 0;
        $weightOf = Wensen::weights($this->wensen);

        foreach ($scores as $wish => $score) {
            if ($score === null) {
                continue;
            }

            $weight = (float) ($weightOf[$wish] ?? 0);
            $sum += $weight * $score;
            $weights += $weight;
        }

        return $weights > 0 ? round(max(0, min(100, $sum / $weights)), 1) : 50.0;
    }

    public function total(float $match, float $dealScore): float
    {
        $w = (float) $this->wensen['w'];

        return round($w * $match + (1 - $w) * $dealScore, 1);
    }

    /**
     * Deal % on 0–100: -10% or worse 0, 0% 50, +10% or better 100. No deal score, or one marked
     * "Check dit", counts as 50: a suspiciously cheap car is not a top deal.
     */
    public static function dealScore(?float $pct, bool $check = false): float
    {
        if ($pct === null || $check) {
            return 50.0;
        }

        return round(max(0, min(100, ($pct + 10) * 5)), 1);
    }

    /** 100 up to 75% of the budget, down to 0 at the budget. */
    public static function price(?int $price, int $budget): int
    {
        if ($price === null || $budget <= 0) {
            return 0;
        }

        return self::linear($price, 0.75 * $budget, $budget);
    }

    /** 100 at 0 km, down to 0 at the maximum, on to -50 at the end of the "iets ruimer" margin. */
    public static function km(?int $km, int $max): int
    {
        return match (true) {
            $km === null || $max <= 0 => 0,
            $km <= $max => self::linear($km, 0, $max),
            default => (int) round(self::MARGE_SCORE * min(1, ($km - $max) / (self::KM_MARGE * $max))),
        };
    }

    /** 100 from the newest year, down to 0 at the minimum year; a year older (the margin) or more -50. */
    public static function year(?int $year, int $newest, int $minYear): int
    {
        return match (true) {
            $year === null => 0,
            $year < $minYear => self::MARGE_SCORE,
            default => self::linear($year, $newest, $minYear),
        };
    }

    /** At or above the target 100, one level below 40, two 0. */
    public static function comfort(?int $level, int $target): int
    {
        return match (true) {
            $level === null => 0,
            $level >= $target => 100,
            $level === $target - 1 => 40,
            default => 0,
        };
    }

    /** ja: a sport model 100; nee: a model without sport 100. */
    public static function sport(?bool $sport, string $wish): int
    {
        return (bool) $sport === ($wish !== 'nee') ? 100 : 0;
    }

    /** At or above the minimum 100, down to 0 at 20% below it. */
    public static function power(?int $pk, int $min): int
    {
        if ($min <= 0) {
            return 100;
        }

        return $pk === null ? 0 : self::linear($pk, $min, 0.8 * $min);
    }

    /**
     * By the order of preference: number 1 100, the last 40. A model not in the list counts as
     * the last; with no list every model is 100.
     *
     * @param  list<string>  $order  "merk|model"
     */
    public static function model(string $model, array $order): int
    {
        if ($order === []) {
            return 100;
        }

        $at = array_search($model, $order, true);

        if ($at === false) {
            return 40;
        }

        return count($order) === 1 ? 100 : (int) round(100 - 60 * $at / (count($order) - 1));
    }

    /**
     * One of the preferred colours 100, an unknown colour 50, another 0. With no preference null:
     * the colour then leaves the match alone, whatever its weight.
     *
     * @param  list<string>  $preferred
     */
    public static function colour(?string $colour, array $preferred): ?int
    {
        return match (true) {
            $preferred === [] => null,
            $colour === null => 50,
            default => in_array($colour, $preferred, true) ? 100 : 0,
        };
    }

    public static function isBrandDealer(array $car): bool
    {
        return ($car['aanbieder_type'] ?? null) === 'merkdealer' || ($car['garantie'] ?? null) === 'MERKGARANTIE';
    }

    /**
     * 100 at $full, 0 at $zero, straight in between (either direction), clamped.
     */
    private static function linear(float $value, float $full, float $zero): int
    {
        if ($full === $zero) {
            return $value === $full ? 100 : 0;
        }

        $share = ($value - $zero) / ($full - $zero);

        return (int) round(100 * max(0, min(1, $share)));
    }
}
