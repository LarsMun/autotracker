<?php

namespace App\Jobs;

use App\Gaspedaal\Client;
use App\Gaspedaal\Scraper;
use App\Models\Source;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs one source. The single queue worker takes these one at a time, so Gaspedaal never gets
 * more than one request at once.
 */
class ScrapeSource implements ShouldQueue
{
    use Queueable;

    /** The scraper records its own failures; retrying a whole run is not wanted. */
    public int $tries = 1;

    /** The largest source has some 25 pages at up to ~70 s each with retries. */
    public int $timeout = 3000;

    /**
     * @param  int  $pauseBefore  seconds to wait first: a gap after the previous source
     */
    public function __construct(public int $sourceId, public string $trigger = 'schedule', public int $pauseBefore = 0)
    {
        $this->onQueue('scrape');
    }

    public function handle(Scraper $scraper): void
    {
        $source = Source::find($this->sourceId);

        if ($source === null || ($this->trigger === 'schedule' && ! $source->active)) {
            return;
        }

        Client::pause($this->pauseBefore);

        $scraper->run($source, $this->trigger);
    }
}
