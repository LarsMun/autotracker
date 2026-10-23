<?php

namespace App\Analysis;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * A selection of cars, as set on the analysis page.
 *
 * Properties of the car (make, model, year, power, body, gearbox, trim) filter the listing. Price
 * and km change over time: for "now" they filter the car's latest values, for the daily series the
 * values of that day. The source filter means: found by that source (now, or on that day).
 */
final readonly class ListingFilter
{
    /** The keys a filter can have, in the order the page shows them. */
    public const array KEYS = [
        'merk', 'model', 'bron',
        'bouwjaar_min', 'bouwjaar_max', 'pk_min', 'pk_max',
        'km_min', 'km_max', 'prijs_min', 'prijs_max',
        'carrosserie', 'transmissie', 'kleur', 'uitvoering',
        'aanbieder', 'garantie', 'btw',
        'comfort', 'sport',
    ];

    /** The values the choice filters accept. */
    public const array CHOICES = [
        'aanbieder' => ['merkdealer', 'handelaar', 'particulier'],
        'garantie' => ['merkgarantie', 'bovag', 'geen'],
        'btw' => ['verrekenbaar', 'marge'],
        'sport' => ['ja', 'nee'],
    ];

    private const array NUMERIC = ['bron', 'bouwjaar_min', 'bouwjaar_max', 'pk_min', 'pk_max', 'km_min', 'km_max', 'prijs_min', 'prijs_max'];

    /**
     * @param  array<string, string|int>  $values  only the keys that are set
     */
    private function __construct(public array $values) {}

    /**
     * From request input or a saved filter: unknown keys and empty values are dropped.
     */
    public static function from(array $input): self
    {
        $values = [];

        foreach (self::KEYS as $key) {
            $value = $input[$key] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            // Comfort levels: several at once, kept as "1,3" (in the URL and in saved filters).
            if ($key === 'comfort') {
                $levels = array_values(array_unique(array_intersect(
                    array_map('intval', is_array($value) ? $value : explode(',', (string) $value)),
                    [1, 2, 3],
                )));
                sort($levels);

                if ($levels !== []) {
                    $values[$key] = implode(',', $levels);
                }

                continue;
            }

            // Colours: several at once, kept as "GRIJS,ZWART".
            if ($key === 'kleur') {
                $colours = collect(is_array($value) ? $value : explode(',', (string) $value))
                    ->map(fn ($c) => mb_strtoupper(trim((string) $c)))->filter()->unique()->sort()->values();

                if ($colours->isNotEmpty()) {
                    $values[$key] = mb_substr($colours->implode(','), 0, 200);
                }

                continue;
            }

            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }

            if (isset(self::CHOICES[$key])) {
                if (! in_array($value, self::CHOICES[$key], true)) {
                    continue;
                }
            } elseif (in_array($key, self::NUMERIC, true)) {
                if (! is_numeric($value)) {
                    continue;
                }

                $value = (int) $value;
            } else {
                $value = mb_substr((string) $value, 0, 100);
            }

            $values[$key] = $value;
        }

        return new self($values);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    private function get(string $key): string|int|null
    {
        return $this->values[$key] ?? null;
    }

    /**
     * The cars online now: followed by a source (that source, when filtered) and not gone.
     */
    public function current(): Builder
    {
        $query = \App\Models\Listing::query();
        $this->applyProperties($query->getQuery());
        $this->applyRanges($query->getQuery(), 'listings.prijs', 'listings.kilometerstand');

        $source = $this->get('bron');
        $query->whereExists(fn (QueryBuilder $q) => $q->from('listing_source')
            ->whereColumn('listing_source.listing_id', 'listings.id')
            ->whereNull('listing_source.gone_at')
            ->when($source, fn ($q) => $q->where('listing_source.source_id', $source)));

        return $query;
    }

    /**
     * Every observation of a selected car on a day it matched: date, prijs, kilometerstand.
     */
    public function observations(): QueryBuilder
    {
        $query = DB::table('observations')
            ->join('listings', 'listings.id', '=', 'observations.listing_id')
            ->whereNotNull('observations.prijs');

        $this->applyProperties($query);
        $this->applyRanges($query, 'observations.prijs', 'observations.kilometerstand');

        if ($source = $this->get('bron')) {
            // Found by that source on that day.
            $query->whereExists(fn (QueryBuilder $q) => $q->from('listing_source')
                ->whereColumn('listing_source.listing_id', 'observations.listing_id')
                ->where('listing_source.source_id', $source)
                ->whereColumn('listing_source.first_seen', '<=', 'observations.date')
                ->whereColumn('listing_source.last_seen', '>=', 'observations.date'));
        }

        return $query;
    }

    private function applyProperties(QueryBuilder $query): void
    {
        foreach (['merk', 'model', 'carrosserie', 'transmissie'] as $key) {
            if (($value = $this->get($key)) !== null) {
                $query->where("listings.{$key}", $value);
            }
        }

        if (($value = $this->get('kleur')) !== null) {
            $query->whereIn('listings.kleur', explode(',', (string) $value));
        }

        if (($value = $this->get('uitvoering')) !== null) {
            $query->where('listings.uitvoering', 'like', '%'.addcslashes((string) $value, '%_\\').'%');
        }

        if (($value = $this->get('aanbieder')) !== null) {
            $query->where('listings.aanbieder_type', $value);
        }

        // "geen" and "marge" only for cars whose filters have been checked (kenmerken_op).
        match ($this->get('garantie')) {
            'merkgarantie' => $query->where('listings.garantie', 'MERKGARANTIE'),
            'bovag' => $query->where('listings.garantie', 'BOVAG'),
            'geen' => $query->whereNull('listings.garantie')->whereNotNull('listings.kenmerken_op'),
            default => null,
        };

        match ($this->get('btw')) {
            'verrekenbaar' => $query->where('listings.btw_verrekenbaar', true),
            'marge' => $query->where('listings.btw_verrekenbaar', false),
            default => null,
        };

        if (($value = $this->get('comfort')) !== null) {
            $query->whereIn('listings.comfort_niveau', array_map('intval', explode(',', (string) $value)));
        }

        if (($value = $this->get('sport')) !== null) {
            $query->where('listings.sport', $value === 'ja');
        }

        if (($value = $this->get('bouwjaar_min')) !== null) {
            $query->where('listings.bouwjaar', '>=', $value);
        }

        if (($value = $this->get('bouwjaar_max')) !== null) {
            $query->where('listings.bouwjaar', '<=', $value);
        }

        // pk as shown everywhere: round(kW × 1.36).
        if (($value = $this->get('pk_min')) !== null) {
            $query->whereRaw('ROUND(listings.vermogen_kw * 1.36) >= ?', [$value]);
        }

        if (($value = $this->get('pk_max')) !== null) {
            $query->whereRaw('ROUND(listings.vermogen_kw * 1.36) <= ?', [$value]);
        }
    }

    private function applyRanges(QueryBuilder $query, string $price, string $km): void
    {
        foreach (['prijs' => $price, 'km' => $km] as $key => $column) {
            if (($value = $this->get("{$key}_min")) !== null) {
                $query->where($column, '>=', $value);
            }

            if (($value = $this->get("{$key}_max")) !== null) {
                $query->where($column, '<=', $value);
            }
        }
    }
}
