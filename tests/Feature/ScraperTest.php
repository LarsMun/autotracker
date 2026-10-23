<?php

namespace Tests\Feature;

use App\Gaspedaal\Scraper;
use App\Models\Listing;
use App\Models\ScrapeRun;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeGaspedaal;
use Tests\TestCase;

class ScraperTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string|int>|null */
    private ?array $pages = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
        $this->travelTo(now()->setDate(2026, 10, 16)->setTime(6, 0));
    }

    /**
     * @param  array<int, string|int>  $pages  page number => html, or an HTTP status to fail with
     */
    private function fake(array $pages): void
    {
        // Http::fake() stacks callbacks and the first one keeps answering, so register it once.
        $first = $this->pages === null;
        $this->pages = $pages;

        if ($first) {
            Http::fake(function ($request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
                $body = $this->pages[(int) ($q['page'] ?? 1)] ?? 404;

                return is_int($body) ? Http::response('', $body) : Http::response($body);
            });
        }
    }

    private function source(string $url = 'https://www.gaspedaal.nl/bmw/3-serie?bmin=2020'): Source
    {
        return Source::create(['name' => 'BMW', 'url' => $url, 'active' => true]);
    }

    public function test_a_complete_run_stores_every_page(): void
    {
        $source = $this->source();
        $this->fake([
            1 => FakeGaspedaal::page([FakeGaspedaal::occasion(1), FakeGaspedaal::occasion(2)], 3, 1),
            2 => FakeGaspedaal::page([FakeGaspedaal::occasion(3, 25000)], 3, 2),
        ]);

        $run = app(Scraper::class)->run($source);

        $this->assertSame(ScrapeRun::SUCCESS, $run->status, (string) $run->error);
        $this->assertSame(3, $run->listings_seen);
        $this->assertSame(3, $run->new_count);
        $this->assertSame(2, $run->pages()->count());
        $this->assertSame(3, Listing::count());
        $this->assertDatabaseHas('observations', ['date' => '2026-10-16', 'prijs' => 25000]);
        $this->assertSame(184, Listing::where('advertentie_id', 3)->first()->pk());
        $this->assertSame('https://cdn.gaspedaal.nl/images/large/1.jpg', Listing::where('advertentie_id', 1)->value('foto_url'));

        $path = $run->pages()->first()->path;
        Storage::disk('local')->assertExists($path);
        $this->assertStringContainsString('__next_f', gzdecode(Storage::disk('local')->get($path)));
    }

    public function test_cars_missing_from_a_complete_run_are_gone_and_prices_are_tracked(): void
    {
        $source = $this->source();
        $this->fake([1 => FakeGaspedaal::page([FakeGaspedaal::occasion(1), FakeGaspedaal::occasion(2)], 2)]);
        app(Scraper::class)->run($source);

        $this->travel(1)->day();
        $this->fake([1 => FakeGaspedaal::page([FakeGaspedaal::occasion(1, 28500), FakeGaspedaal::occasion(5)], 2)]);
        $run = app(Scraper::class)->run($source);

        $this->assertSame(ScrapeRun::SUCCESS, $run->status);
        $this->assertSame(1, $run->new_count);
        $this->assertSame(1, $run->gone_count);
        $this->assertSame('2026-10-17', Listing::where('advertentie_id', 2)->first()->gone_at->toDateString());
        $this->assertNull(Listing::where('advertentie_id', 1)->first()->gone_at);
        $this->assertSame([30000, 28500], Listing::where('advertentie_id', 1)->first()->observations()->orderBy('date')->pluck('prijs')->all());
        // Price changed, so the raw JSON was stored a second time.
        $this->assertSame(2, Listing::where('advertentie_id', 1)->first()->snapshots()->count());

        // It comes back: no longer gone.
        $this->travel(1)->day();
        $this->fake([1 => FakeGaspedaal::page([FakeGaspedaal::occasion(1, 28500), FakeGaspedaal::occasion(2), FakeGaspedaal::occasion(5)], 3, pageSize: 3)]);
        app(Scraper::class)->run($source);
        $this->assertNull(Listing::where('advertentie_id', 2)->first()->gone_at);
        // Same JSON as day 2: no new snapshot.
        $this->assertSame(2, Listing::where('advertentie_id', 1)->first()->snapshots()->count());
    }

    public function test_a_failed_run_marks_nothing_as_gone(): void
    {
        $source = $this->source();
        $this->fake([
            1 => FakeGaspedaal::page([FakeGaspedaal::occasion(1), FakeGaspedaal::occasion(2)], 3, 1),
            2 => FakeGaspedaal::page([FakeGaspedaal::occasion(3)], 3, 2),
        ]);
        app(Scraper::class)->run($source);

        $this->travel(1)->day();
        $this->fake([1 => FakeGaspedaal::page([FakeGaspedaal::occasion(1), FakeGaspedaal::occasion(2)], 3, 1), 2 => 503]);
        $run = app(Scraper::class)->run($source);

        $this->assertSame(ScrapeRun::FAILED, $run->status);
        $this->assertStringContainsString('503', $run->error);
        $this->assertSame(0, Listing::whereNotNull('gone_at')->count());
        // What it did see still counts.
        $this->assertDatabaseHas('observations', ['date' => '2026-10-17']);
    }

    public function test_too_few_cars_seen_is_incomplete(): void
    {
        $source = $this->source();
        $this->fake([1 => FakeGaspedaal::page([FakeGaspedaal::occasion(1), FakeGaspedaal::occasion(2)], 2)]);
        app(Scraper::class)->run($source);

        // Gaspedaal reports 2 cars per page but this page lists one: 1 of 2 = 50%.
        $this->travel(1)->day();
        $this->fake([1 => FakeGaspedaal::page([FakeGaspedaal::occasion(1)], 2)]);
        $run = app(Scraper::class)->run($source);

        $this->assertSame(ScrapeRun::INCOMPLETE, $run->status);
        $this->assertSame(0, Listing::whereNotNull('gone_at')->count());
    }

    public function test_zero_cars_is_a_failure(): void
    {
        $this->fake([1 => FakeGaspedaal::page([], 0)]);

        $run = app(Scraper::class)->run($this->source());

        $this->assertSame(ScrapeRun::FAILED, $run->status);
        $this->assertStringContainsString("0 auto's", $run->error);
    }

    public function test_a_car_in_two_sources_is_one_listing_and_stays_until_both_lose_it(): void
    {
        $a = $this->source('https://www.gaspedaal.nl/bmw/a');
        $b = $this->source('https://www.gaspedaal.nl/bmw/b');
        $this->fake([1 => FakeGaspedaal::page([FakeGaspedaal::occasion(1), FakeGaspedaal::occasion(2)], 2)]);
        app(Scraper::class)->run($a);
        app(Scraper::class)->run($b);

        $this->assertSame(2, Listing::count());
        $this->assertSame(2, Listing::first()->observations()->count() + 1); // one observation per day, not per source

        $this->travel(1)->day();
        $this->fake([1 => FakeGaspedaal::page([FakeGaspedaal::occasion(1), FakeGaspedaal::occasion(9)], 2)]);
        app(Scraper::class)->run($a);

        // Gone from A, but B has not run complete without it yet.
        $this->assertNull(Listing::where('advertentie_id', 2)->first()->gone_at);

        app(Scraper::class)->run($b);
        $this->assertNotNull(Listing::where('advertentie_id', 2)->first()->gone_at);
    }

    public function test_after_a_url_change_cars_outside_the_new_search_are_unlinked_not_gone(): void
    {
        $this->withoutVite();
        $this->actingAs(\App\Models\User::create(['name' => 'lars', 'email' => 'l@auto.local', 'password' => 'geheim-wachtwoord']));
        $source = $this->source();
        $this->fake([1 => FakeGaspedaal::page([FakeGaspedaal::occasion(1), FakeGaspedaal::occasion(2)], 2)]);
        app(Scraper::class)->run($source);

        // Same day, narrower search: only car 1 still matches.
        $this->patch("/sources/{$source->id}", ['url' => 'https://www.gaspedaal.nl/bmw/3-serie?bmin=2022'])->assertRedirect();
        $this->assertTrue($source->fresh()->rebase_pending);

        $this->fake([1 => FakeGaspedaal::page([FakeGaspedaal::occasion(1)], 1)]);
        $run = app(Scraper::class)->run($source->fresh());

        $this->assertSame(ScrapeRun::SUCCESS, $run->status);
        $this->assertSame(0, $run->gone_count);
        $this->assertStringContainsString('1 auto', $run->warnings);
        $this->assertFalse($source->fresh()->rebase_pending);
        $this->assertSame([Listing::where('advertentie_id', 1)->value('id')], $source->listings()->pluck('listings.id')->all());

        // Car 2 keeps its history and is not counted as gone.
        $two = Listing::where('advertentie_id', 2)->first();
        $this->assertNull($two->gone_at);
        $this->assertSame(1, $two->observations()->count());

        // From now on, missing cars are gone again as usual.
        $this->travel(1)->day();
        $this->fake([1 => FakeGaspedaal::page([FakeGaspedaal::occasion(7)], 1)]);
        $this->assertSame(1, app(Scraper::class)->run($source->fresh())->gone_count);
        $this->get('/listings/'.$two->id)->assertOk();

        // auto:prune deletes it with its history; car 1 (gone, but still linked) stays.
        $this->artisan('auto:prune', ['--force' => true])->assertSuccessful();
        $this->assertNull($two->fresh());
        $this->assertSame(0, \App\Models\Observation::where('listing_id', $two->id)->count());
        $this->assertSame(0, \App\Models\ListingSnapshot::where('listing_id', $two->id)->count());
        $this->assertNotNull(Listing::where('advertentie_id', 1)->first());
    }

    public function test_a_second_complete_run_on_the_same_day_marks_unseen_cars_gone(): void
    {
        $source = $this->source();
        $this->fake([1 => FakeGaspedaal::page([FakeGaspedaal::occasion(1), FakeGaspedaal::occasion(2)], 2)]);
        app(Scraper::class)->run($source);

        $this->fake([1 => FakeGaspedaal::page([FakeGaspedaal::occasion(1)], 1)]);
        $run = app(Scraper::class)->run($source);

        $this->assertSame(1, $run->gone_count);
        $this->assertNotNull(Listing::where('advertentie_id', 2)->first()->gone_at);
    }
}
