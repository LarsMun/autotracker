<?php

namespace App\Gaspedaal;

use App\Models\Listing;
use App\Models\ScrapePage;
use App\Models\ScrapeRun;
use App\Models\Source;
use App\Monitoring\Monitor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One run of one source: every page, one after the other with a pause in between.
 *
 * Cars are recorded as soon as their page is in, so a run that fails halfway still keeps what it
 * saw. Only a complete run marks cars that were not seen as gone.
 */
class Scraper
{
    public function __construct(
        private Client $client,
        private PageParser $parser,
        private Recorder $recorder,
        private Monitor $monitor,
        private SellerType $sellerType,
    ) {}

    /**
     * Gaspedaal's own filters, run as extra passes of each source: which of today's cars are
     * offered privately, with merkgarantie or BOVAG, and with btw verrekenbaar. Grouped by the
     * column they fill; a group only applies when all its passes came in complete.
     */
    public const array FACETS = [
        'particulier' => ['vrk=PARTICULIER'],
        'garantie' => ['grn=MERKGARANTIE', 'grn=BOVAG'],
        'btw_verrekenbaar' => ['btw=true'],
    ];

    public function run(Source $source, string $trigger = 'schedule'): ScrapeRun
    {
        $date = today();

        $run = $source->runs()->create([
            'run_date' => $date->toDateString(),
            'status' => ScrapeRun::RUNNING,
            'trigger' => $trigger,
            'url' => $source->url,
            'started_at' => now(),
        ]);

        $source->forceFill(['last_run_id' => $run->id])->save();

        /** @var array<int, int> $seen advertentieId => listing id */
        $seen = [];
        $facetCounts = [];
        $newCount = 0;

        try {
            $page = 1;
            $pages = 1;

            do {
                if ($page > 1) {
                    Client::politeDelay();
                }

                $parsed = $this->fetch($run, $source->url, $page);

                if ($page === 1) {
                    $pages = $parsed->numberOfPages;
                    $facetCounts = $parsed->facetCounts;
                    $run->fill(['total_reported' => $parsed->totalOccasions, 'pages_expected' => $pages])->save();

                    if ($parsed->totalOccasions === 0 || $parsed->occasions === []) {
                        throw new ParseException('Gaspedaal gaf 0 auto\'s voor deze zoekopdracht.');
                    }
                } elseif ($parsed->pageNumber !== $page) {
                    throw new ParseException("Pagina {$page} gevraagd, maar pagina {$parsed->pageNumber} gekregen.");
                }

                foreach ($parsed->occasions as $occasion) {
                    $id = (int) $occasion['advertentieId'];

                    if (isset($seen[$id])) {
                        continue;
                    }

                    $recorded = $this->recorder->record($source->id, $date, $occasion, $parsed->ldItems[$id] ?? null);
                    $seen[$id] = $recorded['listing_id'];

                    if ($recorded['new']) {
                        $newCount++;
                    }
                }

                if ($parsed->ldMatches() === false) {
                    $run->addWarning("Pagina {$page}: ld+json noemt andere auto's dan de occasions.");
                }

                $run->fill([
                    'pages_fetched' => $page,
                    'listings_seen' => count($seen),
                    'new_count' => $newCount,
                ])->save();

                $page++;
            } while ($page <= $pages);

            $this->finish($run, $source, array_values($seen));
        } catch (Throwable $e) {
            $run->fill([
                'status' => ScrapeRun::FAILED,
                'error' => $e->getMessage(),
                'listings_seen' => count($seen),
                'new_count' => $newCount,
                'finished_at' => now(),
            ])->save();

            Log::error("Run {$run->id} of source {$source->id} ({$source->name}) failed: {$e->getMessage()}");

            if (! $e instanceof FetchException && ! $e instanceof ParseException) {
                report($e);
            }
        }

        // Extra, and never able to fail the run itself.
        try {
            $this->facets($run, $source, $seen, $facetCounts);
            $this->sellerType->refresh();
        } catch (Throwable $e) {
            $run->addWarning("Kenmerken (garantie, particulier, btw) niet bijgewerkt: {$e->getMessage()}");
            $run->save();
            report($e);
        }

        $this->monitor->report();

        return $run;
    }

    private function fetch(ScrapeRun $run, string $url, int $number, ?string $facet = null): ParsedPage
    {
        $attempts = 1;
        $page = new ScrapePage(['page_number' => $number, 'facet' => $facet]);
        $page->run()->associate($run);

        try {
            $result = $this->client->fetchPage($url, $number, function () use (&$attempts) {
                $attempts++;
            }, $this->parser);
        } catch (Throwable $e) {
            $page->fill([
                'attempts' => $attempts,
                'error' => $e->getMessage(),
                'http_status' => $e instanceof FetchException && $e->getCode() ? $e->getCode() : null,
                'fetched_at' => now(),
            ]);

            if ($e instanceof ParseException && $e->html !== null) {
                $page->fill(['http_status' => 200, 'bytes' => strlen($e->html), 'path' => $this->store($run, $number, $e->html, $facet)]);
            }

            $page->save();

            throw $e;
        }

        $parsed = $result['page'];

        $page->fill([
            'http_status' => $result['status'],
            'bytes' => strlen($result['html']),
            'path' => $this->store($run, $number, $result['html'], $facet),
            'occasions_count' => count($parsed->occasions),
            'ld_matches' => $parsed->ldMatches(),
            'attempts' => $attempts,
            'fetched_at' => now(),
        ])->save();

        return $parsed;
    }

    private function store(ScrapeRun $run, int $number, string $html, ?string $facet = null): string
    {
        $path = sprintf(
            '%s/%s/source-%d/run-%d%s-page-%02d.html.gz',
            config('gaspedaal.raw_dir'),
            $run->run_date->toDateString(),
            $run->source_id,
            $run->id,
            $facet ? '-'.str_replace(['=', '_'], ['-', ''], strtolower($facet)) : '',
            $number,
        );

        Storage::disk(config('gaspedaal.raw_disk'))->put($path, gzencode($html, 6));

        return $path;
    }

    /**
     * @param  list<int>  $listingIds  the listings this run saw
     */
    private function finish(ScrapeRun $run, Source $source, array $listingIds): void
    {
        $seen = count($listingIds);
        $total = (int) $run->total_reported;
        $coverage = $total > 0 ? $seen / $total : 0;

        if ($coverage < config('gaspedaal.min_coverage')) {
            $run->fill([
                'status' => ScrapeRun::INCOMPLETE,
                'error' => sprintf('%d van %d auto\'s gezien (%.1f%%); niets als verdwenen gemarkeerd.', $seen, $total, $coverage * 100),
                'finished_at' => now(),
            ])->save();

            return;
        }

        // The URL changed: what this source had but no longer finds is outside the new search,
        // not sold. Those links go; the cars and their history stay.
        if ($source->rebase_pending) {
            $unlinked = $this->unlinkUnseen($source->id, $listingIds);
            $source->forceFill(['rebase_pending' => false])->save();
            $run->addWarning("URL gewijzigd: {$unlinked} auto's die niet meer binnen de zoekopdracht vallen zijn ontkoppeld (niet als verdwenen geteld).");

            $run->fill(['status' => ScrapeRun::SUCCESS, 'gone_count' => 0, 'finished_at' => now()])->save();

            return;
        }

        $run->fill([
            'status' => ScrapeRun::SUCCESS,
            'gone_count' => $this->markGone($source->id, $run->run_date->toDateString(), $listingIds),
            'finished_at' => now(),
        ])->save();
    }

    /**
     * Listings linked to this source (and not gone) that the run did not see.
     *
     * @param  list<int>  $seen
     * @return Collection<int, int>
     */
    private function unseen(int $sourceId, array $seen): Collection
    {
        $lookup = array_flip($seen);

        return DB::table('listing_source')
            ->where('source_id', $sourceId)
            ->whereNull('gone_at')
            ->pluck('listing_id')
            ->reject(fn (int $id) => isset($lookup[$id]))
            ->values();
    }

    /**
     * @param  list<int>  $seen
     */
    private function unlinkUnseen(int $sourceId, array $seen): int
    {
        $listingIds = $this->unseen($sourceId, $seen);

        foreach ($listingIds->chunk(500) as $chunk) {
            DB::table('listing_source')->where('source_id', $sourceId)->whereIn('listing_id', $chunk)->delete();
        }

        return $listingIds->count();
    }

    /**
     * Cars this source had, but not in today's complete run. A car is gone altogether once none
     * of its sources has it any more.
     *
     * @param  list<int>  $seen  the listings this run saw
     * @return int the number of cars that left this source
     */
    private function markGone(int $sourceId, string $day, array $seen): int
    {
        return DB::transaction(function () use ($sourceId, $day, $seen) {
            $listingIds = $this->unseen($sourceId, $seen);

            foreach ($listingIds->chunk(500) as $chunk) {
                DB::table('listing_source')
                    ->where('source_id', $sourceId)
                    ->whereIn('listing_id', $chunk)
                    ->update(['gone_at' => $day]);

                Listing::whereIn('id', $chunk)
                    ->whereNull('gone_at')
                    ->whereNotExists(fn ($q) => $q->from('listing_source')
                        ->whereColumn('listing_source.listing_id', 'listings.id')
                        ->whereNull('listing_source.gone_at'))
                    ->update(['gone_at' => $day]);
            }

            return $listingIds->count();
        });
    }

    /**
     * Runs the filtered passes and writes their outcome to the cars this run saw.
     *
     * @param  array<int, int>  $seen  advertentieId => listing id
     * @param  array<string, int>  $expected  per filter, the count Gaspedaal's sidebar gave
     */
    private function facets(ScrapeRun $run, Source $source, array $seen, array $expected): void
    {
        if ($run->status === ScrapeRun::FAILED || $seen === []) {
            return;
        }

        parse_str((string) parse_url($source->url, PHP_URL_QUERY), $query);
        $updates = [];

        foreach (self::FACETS as $column => $facets) {
            // The source already filters on this itself: the pass would say nothing new.
            if (array_key_exists(strtok($facets[0], '='), $query)) {
                continue;
            }

            $members = [];

            foreach ($facets as $facet) {
                $ids = $this->facetPass($run, $source->url, $facet, $expected[$facet] ?? null);

                if ($ids === null) {
                    continue 2;
                }

                $members[$facet] = array_flip($ids);
            }

            foreach ($seen as $advertentieId => $listingId) {
                $updates[$listingId][$column] = match ($column) {
                    'particulier' => isset($members['vrk=PARTICULIER'][$advertentieId]),
                    'btw_verrekenbaar' => isset($members['btw=true'][$advertentieId]),
                    'garantie' => match (true) {
                        isset($members['grn=MERKGARANTIE'][$advertentieId]) => 'MERKGARANTIE',
                        isset($members['grn=BOVAG'][$advertentieId]) => 'BOVAG',
                        default => null,
                    },
                };
            }
        }

        // Cars with the same outcome are written together.
        $groups = [];

        foreach ($updates as $listingId => $values) {
            $values['kenmerken_op'] = $run->run_date->toDateString();
            $groups[json_encode($values)][] = $listingId;
        }

        foreach ($groups as $values => $ids) {
            foreach (array_chunk($ids, 500) as $chunk) {
                Listing::whereKey($chunk)->update(json_decode($values, true));
            }
        }

        $run->save();
    }

    /**
     * Every page of the source's search with one of Gaspedaal's filters added.
     *
     * @return list<int>|null the advertentieIds, or null when the pass did not come in complete
     */
    private function facetPass(ScrapeRun $run, string $url, string $facet, ?int $expected): ?array
    {
        if ($expected === null) {
            $run->addWarning("Filter {$facet}: Gaspedaal noemt er geen aantal voor; die kenmerken zijn niet bijgewerkt.");

            return null;
        }

        // Nothing to fetch: no car of this search has it.
        if ($expected === 0) {
            return [];
        }

        $filtered = $url.(str_contains($url, '?') ? '&' : '?').$facet;
        $ids = [];
        $page = 1;
        $pages = 1;
        $total = 0;

        try {
            do {
                Client::politeDelay();
                $parsed = $this->fetch($run, $filtered, $page, $facet);

                if ($page === 1) {
                    $pages = $parsed->numberOfPages;
                    $total = $parsed->totalOccasions;
                }

                foreach ($parsed->occasions as $occasion) {
                    $ids[(int) $occasion['advertentieId']] = true;
                }

                $page++;
            } while ($page <= $pages && $total > 0);
        } catch (FetchException|ParseException $e) {
            $run->addWarning("Filter {$facet}: {$e->getMessage()}; die kenmerken zijn niet bijgewerkt.");

            return null;
        }

        // A filter Gaspedaal ignored would return the whole search: the total must match the sidebar.
        if (abs($total - $expected) > max(2, $expected * 0.03)) {
            $run->addWarning("Filter {$facet}: {$total} auto's gekregen, de zoekopdracht noemde er {$expected}; die kenmerken zijn niet bijgewerkt.");

            return null;
        }

        if ($total > 0 && count($ids) < $total * config('gaspedaal.min_coverage')) {
            $run->addWarning(sprintf('Filter %s: %d van %d auto\'s gezien; die kenmerken zijn niet bijgewerkt.', $facet, count($ids), $total));

            return null;
        }

        return array_keys($ids);
    }
}
