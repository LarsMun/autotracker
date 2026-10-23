<?php

namespace App\Gaspedaal;

use JsonException;

/**
 * Reads a Gaspedaal search results page.
 *
 * The site is a Next.js App Router app: the data sits in <script>self.__next_f.push([1,"..."])</script>
 * blocks. Each argument is valid JSON; the string parts together form one text that contains
 * "occasions":[...], "commonPortalURLProps":{...} and "numberOfPages":N.
 */
class PageParser
{
    public function parse(string $html): ParsedPage
    {
        $text = $this->flightText($html);

        if ($text === '') {
            throw new ParseException('No __next_f data found in the page.');
        }

        $occasions = $this->extractValue($text, '"occasions":[');
        $props = $this->extractValue($text, '"commonPortalURLProps":{');

        if (! is_array($occasions)) {
            throw new ParseException('No "occasions" array found in the page data.');
        }

        if (! is_array($props) || ! isset($props['totalOccasions'])) {
            throw new ParseException('No "commonPortalURLProps" found in the page data.');
        }

        foreach ($occasions as $occasion) {
            if (! is_array($occasion) || ! isset($occasion['advertentieId'])) {
                throw new ParseException('An occasion without advertentieId.');
            }
        }

        $numberOfPages = preg_match('/"numberOfPages":(\d+)/', $text, $m) ? (int) $m[1] : null;
        $total = (int) $props['totalOccasions'];
        $pageSize = (int) ($props['pageSize'] ?? 100);

        return new ParsedPage(
            facetCounts: $this->facetCounts($text),
            occasions: array_values($occasions),
            totalOccasions: $total,
            pageNumber: (int) ($props['pageNumber'] ?? 1),
            pageSize: $pageSize,
            numberOfPages: $numberOfPages ?? max(1, (int) ceil($total / max(1, $pageSize))),
            sort: isset($props['sort']) ? (string) $props['sort'] : null,
            ldItems: $this->ldItems($html),
        );
    }

    /**
     * How many cars of this search each of Gaspedaal's filters would give, as its sidebar shows
     * ("Merkgarantie (54)"), keyed like the URL parameter: "grn=MERKGARANTIE" => 54.
     *
     * @return array<string, int>
     */
    public function facetCounts(string $text): array
    {
        $counts = [];

        foreach (['soortAanbieder' => 'vrk', 'garantietype' => 'grn', 'btwVerrekenbaar' => 'btw'] as $name => $param) {
            $at = strpos($text, "\"{$name}\":[{\"value\"");

            if ($at === false) {
                continue;
            }

            try {
                $values = $this->extractValue(substr($text, $at), "\"{$name}\":[");
            } catch (ParseException) {
                continue;
            }

            foreach ((array) $values as $value) {
                if (isset($value['value'], $value['count'])) {
                    $key = is_bool($value['value']) ? ($value['value'] ? 'true' : 'false') : (string) $value['value'];
                    $counts["{$param}={$key}"] = (int) $value['count'];
                }
            }
        }

        return $counts;
    }

    /**
     * All string parts of the __next_f pushes, joined.
     */
    public function flightText(string $html): string
    {
        preg_match_all('/<script>self\.__next_f\.push\((\[.*?\])\)<\/script>/s', $html, $matches);

        $text = '';

        foreach ($matches[1] as $argument) {
            try {
                $parts = json_decode($argument, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (is_array($parts) && isset($parts[1]) && is_string($parts[1])) {
                $text .= $parts[1];
            }
        }

        return $text;
    }

    /**
     * The JSON value that starts at the last character of $needle, cut out with a bracket counter
     * that skips brackets inside strings.
     */
    public function extractValue(string $text, string $needle): mixed
    {
        $at = strpos($text, $needle);

        if ($at === false) {
            return null;
        }

        $start = $at + strlen($needle) - 1;
        $length = strlen($text);
        $depth = 0;
        $i = $start;

        while ($i < $length) {
            // Jump to the next character that matters.
            $i += strcspn($text, '"[]{}', $i);

            if ($i >= $length) {
                break;
            }

            $char = $text[$i];

            if ($char === '"') {
                // To the closing quote, skipping escaped characters.
                $i++;

                while ($i < $length) {
                    $i += strcspn($text, '"\\', $i);

                    if ($i >= $length) {
                        break;
                    }

                    if ($text[$i] === '\\') {
                        $i += 2;

                        continue;
                    }

                    break;
                }
            } elseif ($char === '[' || $char === '{') {
                $depth++;
            } else {
                $depth--;

                if ($depth === 0) {
                    try {
                        return json_decode(substr($text, $start, $i - $start + 1), true, 512, JSON_THROW_ON_ERROR);
                    } catch (JsonException $e) {
                        throw new ParseException("Invalid JSON after {$needle}: {$e->getMessage()}");
                    }
                }
            }

            $i++;
        }

        throw new ParseException("Unterminated value after {$needle}.");
    }

    /**
     * The schema.org ItemList of this page, per advertentieId: a check on the occasions, a larger
     * image than gpFotos gives, and the seller's slug on gaspedaal.nl when the ad names one
     * (/autobedrijven/<slug>/aanbod; only for dealers that also publish through Dealersite).
     *
     * @return array<int, array{image: ?string, seller_slug: ?string}>|null
     */
    public function ldItems(string $html): ?array
    {
        preg_match_all('/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/s', $html, $matches);

        foreach ($matches[1] as $json) {
            $data = json_decode($json, true);

            if (! is_array($data) || ($data['@type'] ?? null) !== 'ItemList') {
                continue;
            }

            $items = [];

            foreach ($data['itemListElement'] ?? [] as $element) {
                $item = $element['item'] ?? [];

                if (! preg_match('/#(\d+)$/', (string) ($item['@id'] ?? ''), $m)) {
                    continue;
                }

                $image = $item['image'] ?? null;
                $sellerUrl = (string) ($item['offers']['seller']['url'] ?? '');

                $items[(int) $m[1]] = [
                    'image' => is_array($image) ? ($image[0] ?? null) : $image,
                    'seller_slug' => preg_match('#^/autobedrijven/([^/]+)/#', $sellerUrl, $s) ? $s[1] : null,
                ];
            }

            return $items;
        }

        return null;
    }
}
