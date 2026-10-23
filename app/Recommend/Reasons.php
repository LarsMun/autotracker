<?php

namespace App\Recommend;

/**
 * The few reasons worth showing for a car's score, as short chips: "€2,1k onder 12 vergelijkbare",
 * "km ✓", "Check dit".
 */
final class Reasons
{
    /**
     * @param  object|null  $deal  a deal_scores row
     * @param  array<string, int>  $scores  the match subscores
     * @return list<array{tekst: string, toon: string}> toon: good, bad, warn or info
     */
    public static function chips(array $car, ?object $deal, array $scores, array $wensen): array
    {
        $chips = [];

        if ($deal?->check_dit) {
            $chips[] = ['tekst' => 'Check dit', 'toon' => 'warn'];
        }

        if ($deal && $deal->verwachte_prijs !== null) {
            $n = $deal->vergelijkbaar_n;
            $eur = (int) $deal->deal_eur;

            $chips[] = match (true) {
                $eur >= 500 => ['tekst' => self::thousands($eur)." onder {$n} vergelijkbare", 'toon' => $deal->check_dit ? 'warn' : 'good'],
                $eur <= -500 => ['tekst' => self::thousands(-$eur)." boven {$n} vergelijkbare", 'toon' => 'bad'],
                default => ['tekst' => "marktprijs ({$n} vergelijkbare)", 'toon' => 'info'],
            };
        } elseif ($deal?->onbetrouwbaar ?? false) {
            $chips[] = ['tekst' => 'dealscore onvoldoende betrouwbaar', 'toon' => 'info'];
        } else {
            $chips[] = ['tekst' => 'te weinig vergelijkbare', 'toon' => 'info'];
        }

        $weights = Wensen::weights($wensen);
        $good = fn (string $wish, int $from = 75) => ($weights[$wish] ?? 0) > 0 && ($scores[$wish] ?? 0) >= $from;

        if ($good('km')) {
            $chips[] = ['tekst' => 'km ✓', 'toon' => 'good'];
        }

        // In the "iets ruimer" margin.
        if (($car['kilometerstand'] ?? null) !== null && $car['kilometerstand'] > $wensen['eisen']['km_max']) {
            $chips[] = ['tekst' => 'net boven km-grens', 'toon' => 'warn'];
        }

        if (($car['bouwjaar'] ?? null) !== null && $car['bouwjaar'] < $wensen['eisen']['bouwjaar_min']) {
            $chips[] = ['tekst' => '1 jaar ouder dan gewenst', 'toon' => 'warn'];
        }

        if ($good('comfort', 100) && $car['comfort_niveau']) {
            $chips[] = ['tekst' => "comfort {$car['comfort_niveau']} ✓", 'toon' => 'good'];
        }

        if ($good('pk', 100)) {
            $chips[] = ['tekst' => 'pk ✓', 'toon' => 'good'];
        }

        if ($wensen['sport'] === 'ja' && $good('sport', 100)) {
            $chips[] = ['tekst' => 'sport ✓', 'toon' => 'good'];
        }

        if (MatchScorer::isBrandDealer($car)) {
            $chips[] = ['tekst' => $car['garantie'] === 'MERKGARANTIE' ? 'merkgarantie' : 'merkdealer', 'toon' => 'good'];
        }

        if (($wensen['modellen'] ?? []) !== [] && ($scores['model'] ?? 0) === 100) {
            $chips[] = ['tekst' => 'favoriet model', 'toon' => 'good'];
        }

        if (($wensen['kleuren'] ?? []) !== [] && $good('kleur', 100)) {
            $chips[] = ['tekst' => 'kleur ✓', 'toon' => 'good'];
        }

        if ($car['online_sinds']) {
            $days = (int) \Illuminate\Support\Carbon::parse($car['online_sinds'])->diffInDays(today());
            $chips[] = $car['online_sinds_bron'] === 'gaspedaal'
                ? ['tekst' => "{$days} dagen online", 'toon' => 'info']
                : ['tekst' => "≥ {$days} dagen online", 'toon' => 'info'];
        }

        return $chips;
    }

    /** 2140 → "€2,1k" */
    public static function thousands(int $eur): string
    {
        return $eur >= 1000 ? '€'.str_replace('.', ',', (string) round($eur / 1000, 1)).'k' : "€{$eur}";
    }
}
