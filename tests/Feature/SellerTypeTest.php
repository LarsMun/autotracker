<?php

namespace Tests\Feature;

use App\Analysis\Analysis;
use App\Analysis\ListingFilter;
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

class SellerTypeTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> filter => html; '' is the search itself */
    private array $pages = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
        $this->travelTo(now()->setDate(2026, 10, 16)->setTime(6, 0));
        Http::fake(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $facet = collect(['vrk', 'grn', 'btw'])->filter(fn ($k) => isset($q[$k]))->map(fn ($k) => "{$k}={$q[$k]}")->first() ?? '';

            return Http::response($this->pages[$facet] ?? $this->pages['']);
        });
    }

    private function car(int $id, string $merk, bool $importer = false, bool $private = false): array
    {
        $o = FakeGaspedaal::occasion($id);
        $o['autogegevens']['algemeen']['merknaam'] = $merk;

        if ($importer) {
            $o['portalen'][] = ['klikUrl' => 'x', 'siteNr' => 83, 'portaalBeschrijving' => $merk, 'portaalType' => 'importeur'];
        }

        if ($private) {
            $o['aanbieder']['aanbiedergegevens'] = ['naamsvermelding' => '$undefined', 'plaatsnaam' => 'Breda', 'provincieHulpwaarde' => '$undefined'];
            $o['portalen'] = [['klikUrl' => 'x', 'siteNr' => 14, 'portaalBeschrijving' => 'AutoWereld', 'portaalType' => 'free portal']];
        }

        return $o;
    }

    private function cars(): array
    {
        return [
            1 => $this->car(1, 'Volvo', importer: true), // Volvo dealer through the importer's portal; slug garage-1
            2 => $this->car(2, 'BMW'),                     // merkgarantie
            3 => $this->car(3, 'BMW'),                     // nothing: handelaar
            4 => $this->car(4, 'BMW'),                     // BOVAG
            5 => $this->car(5, 'Volvo'),                   // same dealer as 1 (slug made garage-1 below)
            6 => $this->car(6, 'BMW', private: true),      // particulier
        ];
    }

    private function page(array $cars, array $facets = []): string
    {
        // Car 5 is sold by the same dealer as car 1.
        return str_replace('garage-5', 'garage-1', FakeGaspedaal::page(array_values($cars), count($cars), pageSize: 100, facets: $facets));
    }

    private function setPages(array $override = []): void
    {
        $c = $this->cars();
        $this->pages = $override + [
            '' => $this->page($c, ['grn=MERKGARANTIE' => 1, 'grn=BOVAG' => 1, 'vrk=PARTICULIER' => 1, 'btw=true' => 2]),
            'grn=MERKGARANTIE' => $this->page([$c[2]]),
            'grn=BOVAG' => $this->page([$c[4]]),
            'vrk=PARTICULIER' => $this->page([$c[6]]),
            'btw=true' => $this->page([$c[1], $c[2]]),
        ];
    }

    private function type(int $id): ?string
    {
        return Listing::where('advertentie_id', $id)->value('aanbieder_type');
    }

    public function test_filters_and_signals_make_particulier_merkdealer_and_handelaar(): void
    {
        $this->setPages();
        $source = Source::create(['name' => 'Mix', 'url' => 'https://www.gaspedaal.nl/mix?bmin=2022', 'active' => true]);

        $run = app(Scraper::class)->run($source);

        $this->assertSame(ScrapeRun::SUCCESS, $run->status);
        $this->assertNull($run->warnings);
        $this->assertSame(4, $run->pages()->whereNotNull('facet')->count());

        $this->assertSame('merkdealer', $this->type(1));   // importer's portal of its own brand
        $this->assertSame('merkdealer', $this->type(2));   // merkgarantie
        $this->assertSame('handelaar', $this->type(3));
        $this->assertSame('handelaar', $this->type(4));    // BOVAG is not a brand dealer
        $this->assertSame('merkdealer', $this->type(5));   // same dealer as 1, same brand
        $this->assertSame('particulier', $this->type(6));

        $four = Listing::where('advertentie_id', 4)->first();
        $this->assertSame('BOVAG', $four->garantie);
        $this->assertFalse((bool) $four->btw_verrekenbaar);
        $this->assertSame('2026-10-16', $four->kenmerken_op->toDateString());
        $this->assertTrue((bool) Listing::where('advertentie_id', 1)->value('btw_verrekenbaar'));
        $this->assertSame('garage-1', Listing::where('advertentie_id', 5)->value('dealer_slug'));
        $this->assertNull(Listing::where('advertentie_id', 6)->value('aanbieder')); // "$undefined" is not a name

        $count = fn (array $f) => app(Analysis::class)->now(ListingFilter::from($f))['count'];
        $this->assertSame(3, $count(['aanbieder' => 'merkdealer']));
        $this->assertSame(2, $count(['aanbieder' => 'handelaar']));
        $this->assertSame(1, $count(['garantie' => 'merkgarantie']));
        $this->assertSame(4, $count(['garantie' => 'geen']));
        $this->assertSame(2, $count(['btw' => 'verrekenbaar']));
        $this->assertSame(1, $count(['btw' => 'marge', 'aanbieder' => 'merkdealer'])); // car 5
        $this->assertSame(6, $count(['aanbieder' => 'importeur']));                  // unknown value: ignored
    }

    public function test_a_filter_gaspedaal_ignores_changes_nothing(): void
    {
        // vrk=PARTICULIER comes back as the whole search, while the sidebar says 1.
        $this->setPages();
        $this->pages['vrk=PARTICULIER'] = $this->pages[''];
        $source = Source::create(['name' => 'Mix', 'url' => 'https://www.gaspedaal.nl/mix', 'active' => true]);

        $run = app(Scraper::class)->run($source);

        $this->assertSame(ScrapeRun::SUCCESS, $run->status);
        $this->assertStringContainsString('vrk=PARTICULIER', $run->warnings);
        $this->assertNull(Listing::where('advertentie_id', 3)->value('particulier'));
        $this->assertSame('handelaar', $this->type(3));
        // Without the filter, the "$undefined" signature still marks the private seller.
        $this->assertSame('particulier', $this->type(6));
        // The other filters did apply.
        $this->assertSame('MERKGARANTIE', Listing::where('advertentie_id', 2)->value('garantie'));
    }

    public function test_a_source_that_already_filters_skips_that_pass(): void
    {
        // Only which passes are fetched matters here: every request gets the search itself.
        $this->setPages();
        $this->pages = ['' => $this->pages['']];
        $source = Source::create(['name' => 'Mix', 'url' => 'https://www.gaspedaal.nl/mix?grn=MERKGARANTIE', 'active' => true]);

        app(Scraper::class)->run($source);

        $this->assertSame(['btw=true', 'vrk=PARTICULIER'], ScrapeRun::first()->pages()->whereNotNull('facet')->orderBy('facet')->pluck('facet')->all());
    }
}
