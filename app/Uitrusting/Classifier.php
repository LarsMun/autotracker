<?php

namespace App\Uitrusting;

use Illuminate\Support\Str;

/**
 * Comfortniveau (1 basic, 2 redelijk, 3 luxe) and sport ja/nee from a car's title, by the table
 * and keywords in config/uitrusting.php.
 *
 * Step 1: the most specific uitvoeringslijn of the brand gives a base level; three option points
 * or more add one (max 3). Without a recognised line, the option points alone give the level.
 */
class Classifier
{
    /** @var array<string, list<array{naam: string, termen: list<string>, niet: list<string>, niveau: int}>> per brand, most specific first */
    private array $lines = [];

    /** @var array<string, list<string>> option => normalised keywords */
    private array $options = [];

    /** @var array<string, list<string>> brand ('*' for all) => normalised keywords */
    private array $sport = [];

    private array $config;

    private string $version;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('uitrusting');
        $this->version = md5(serialize($this->config));

        foreach ($this->config['lijnen'] as $brand => $lines) {
            $parsed = [];

            foreach ($lines as $name => $rule) {
                $rule = is_array($rule) ? $rule : ['niveau' => $rule];
                $parsed[] = [
                    'naam' => $name,
                    'termen' => array_map($this->normalize(...), explode(' + ', $name)),
                    'niet' => array_map($this->normalize(...), $rule['niet'] ?? []),
                    'niveau' => (int) $rule['niveau'],
                ];
            }

            // Most specific first: the most text to match, then the most terms.
            usort($parsed, fn ($a, $b) => [strlen(implode(' ', $b['termen'])), count($b['termen'])] <=> [strlen(implode(' ', $a['termen'])), count($a['termen'])]);
            $this->lines[$brand] = $parsed;
        }

        foreach ($this->config['opties'] as $option => $terms) {
            $this->options[$option] = array_map($this->normalize(...), $terms);
        }

        foreach ($this->config['sport'] as $brand => $terms) {
            $this->sport[$brand] = array_map($this->normalize(...), $terms);
        }
    }

    /**
     * A fingerprint of the table: a car classified with another version is classified again.
     */
    public function version(): string
    {
        return $this->version;
    }

    /**
     * @return array{comfort_niveau: int, sport: bool, uitvoeringslijn: ?string, uitrusting_opties: list<string>, uitrusting_versie: string}
     */
    public function classify(?string $brand, ?string $model, ?string $uitvoering): array
    {
        $text = ' '.$this->normalize(trim("{$model} {$uitvoering}")).' ';
        $key = $this->config['merken'][$this->normalize((string) $brand)] ?? null;

        $line = $this->line($text, $key ? $this->lines[$key] ?? [] : []);

        $options = array_keys(array_filter($this->options, fn (array $terms) => $this->hasAny($text, $terms)));
        $points = count($options);

        if ($line !== null) {
            $level = $line['niveau'] + ($points >= $this->config['opties_voor_extra_niveau'] ? 1 : 0);
        } else {
            $level = 1;

            foreach ($this->config['niveau_uit_opties'] as $minimum => $fromOptions) {
                if ($points >= $minimum) {
                    $level = $fromOptions;
                }
            }
        }

        $sportTerms = [...$this->sport['*'] ?? [], ...($key ? $this->sport[$key] ?? [] : [])];

        return [
            'comfort_niveau' => max(1, min(3, $level)),
            'sport' => $this->hasAny($text, $sportTerms),
            'uitvoeringslijn' => $line['naam'] ?? null,
            'uitrusting_opties' => $options,
            'uitrusting_versie' => $this->version(),
        ];
    }

    /**
     * The line that applies. A line whose words lie within a more specific matching line is not
     * one of its own ("Business Edition" inside "Business Edition Plus", "R" inside "R-Line"). Of
     * the lines that remain, side by side in the title, the highest level wins: "M40i xDrive High
     * Executive" is an M40i.
     *
     * @param  list<array{naam: string, termen: list<string>, niet: list<string>, niveau: int}>  $lines  most specific first
     */
    private function line(string $text, array $lines): ?array
    {
        $matched = [];

        foreach ($lines as $candidate) {
            if (! $this->hasAll($text, $candidate['termen']) || $this->hasAny($text, $candidate['niet'])) {
                continue;
            }

            $within = false;

            foreach ($matched as $longer) {
                $longerText = ' '.implode(' | ', $longer['termen']).' ';

                if (array_all($candidate['termen'], fn (string $term) => str_contains($longerText, " {$term} "))) {
                    $within = true;
                    break;
                }
            }

            if (! $within) {
                $matched[] = $candidate;
            }
        }

        $best = null;

        // In specificity order, so on a tie the most specific stays.
        foreach ($matched as $candidate) {
            if ($best === null || $candidate['niveau'] > $best['niveau']) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Lower case, no accents, "+" after a word as " plus", every other non-letter/digit (except &)
     * a space, single spaces.
     */
    public function normalize(string $value): string
    {
        $value = Str::lower(Str::ascii($value));
        $value = preg_replace('/(?<=[a-z0-9])\+/', ' plus ', $value);
        $value = preg_replace('/[^a-z0-9&]+/', ' ', $value);

        return trim(preg_replace('/\s+/', ' ', $value));
    }

    /**
     * @param  list<string>  $terms  normalised
     */
    private function hasAll(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if (! str_contains($text, " {$term} ")) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $terms  normalised
     */
    private function hasAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if ($term !== '' && str_contains($text, " {$term} ")) {
                return true;
            }
        }

        return false;
    }
}
