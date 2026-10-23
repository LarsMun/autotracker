<?php

namespace App\Gaspedaal;

final readonly class ParsedPage
{
    /**
     * @param  list<array<string, mixed>>  $occasions  the raw occasion objects, as Gaspedaal sends them
     * @param  array<int, array{image: ?string, seller_slug: ?string}>|null  $ldItems  per advertentieId, from the ld+json ItemList
     */
    /**
     * @param  array<string, int>  $facetCounts  e.g. "grn=MERKGARANTIE" => 54, from the filter sidebar
     */
    public function __construct(
        public array $facetCounts,
        public array $occasions,
        public int $totalOccasions,
        public int $pageNumber,
        public int $pageSize,
        public int $numberOfPages,
        public ?string $sort,
        public ?array $ldItems,
    ) {}

    /**
     * Whether the ld+json ItemList names the same cars as the occasions; null when there is none.
     */
    public function ldMatches(): ?bool
    {
        if ($this->ldItems === null) {
            return null;
        }

        $ids = array_map(fn (array $o) => (int) $o['advertentieId'], $this->occasions);
        sort($ids);
        $ld = array_keys($this->ldItems);
        sort($ld);

        return $ids === $ld;
    }
}
