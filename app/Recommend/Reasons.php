<?php

namespace App\Recommend;

/**
 * What the grades per vak do not say, as short chips: "€2,1k onder 12 vergelijkbare", "Check dit",
 * "14 dagen online".
 */
final class Reasons
{
    /**
     * @param  object|null  $deal  a deal_scores row
     * @return list<array{tekst: string, toon: string}> toon: good, bad, warn or info
     */
    public static function chips(array $car, ?object $deal): array
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
