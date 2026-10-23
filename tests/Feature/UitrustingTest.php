<?php

namespace Tests\Feature;

use App\Analysis\Analysis;
use App\Analysis\ListingFilter;
use App\Gaspedaal\Scraper;
use App\Models\Listing;
use App\Models\SavedFilter;
use App\Models\Source;
use App\Models\User;
use App\Uitrusting\Classifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeGaspedaal;
use Tests\TestCase;

class UitrustingTest extends TestCase
{
    use RefreshDatabase;

    private ?string $page = null;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        Queue::fake();
        $this->travelTo(now()->setDate(2026, 10, 16)->setTime(6, 0));
        $this->actingAs(User::create(['name' => 'lars', 'email' => 'l@auto.local', 'password' => 'geheim-wachtwoord']));
        Http::fake(fn () => Http::response($this->page));
        $this->source = Source::create(['name' => 'Volvo', 'url' => 'https://www.gaspedaal.nl/volvo', 'active' => true]);
    }

    private function car(int $id, string $title, string $merk = 'Volvo'): array
    {
        $o = FakeGaspedaal::occasion($id);
        $o['autogegevens']['algemeen']['merknaam'] = $merk;
        $o['autogegevens']['algemeen']['modelnaam'] = 'V60';
        $o['autogegevens']['algemeen']['uitvoering'] = $title;

        return $o;
    }

    private function scrape(array $cars): void
    {
        $this->page = FakeGaspedaal::page($cars, count($cars), pageSize: 100);
        app(Scraper::class)->run($this->source);
    }

    public function test_runs_classify_new_and_changed_cars(): void
    {
        $this->scrape([$this->car(1, 'B4 Ultimate Dark | Pano | H&K'), $this->car(2, 'B3 Essential Edition')]);

        $one = Listing::where('advertentie_id', 1)->first();
        $this->assertSame(3, $one->comfort_niveau);
        $this->assertTrue($one->sport);
        $this->assertSame('Ultimate', $one->uitvoeringslijn);
        $this->assertSame(['pano', 'audio'], $one->uitrusting_opties);
        $this->assertSame(app(Classifier::class)->version(), $one->uitrusting_versie);

        // The dealer rewrites the title: classified again.
        $this->travel(1)->day();
        $this->scrape([$this->car(1, 'B4 Ultimate Dark | Pano | H&K'), $this->car(2, 'B3 Plus | Pano | Leder | Trekhaak')]);

        $two = Listing::where('advertentie_id', 2)->first();
        $this->assertSame(3, $two->comfort_niveau); // Plus (2) + three options
        $this->assertSame('Plus', $two->uitvoeringslijn);
    }

    public function test_the_command_classifies_everything_again(): void
    {
        $this->scrape([$this->car(1, 'T6 Recharge Ultra Dark')]);
        $this->assertSame(1, Listing::first()->comfort_niveau);

        config(['uitrusting.lijnen.volvo.Ultra' => 3]);
        $this->app->forgetInstance(Classifier::class);

        $this->artisan('auto:uitrusting')->expectsOutputToContain('1 gewijzigd')->assertSuccessful();
        $this->assertSame(3, Listing::first()->comfort_niveau);
        $this->assertSame('Ultra', Listing::first()->uitvoeringslijn);
    }

    public function test_filters_on_comfort_and_sport(): void
    {
        $this->scrape([
            $this->car(1, 'B4 Ultimate Dark'),        // 3, sport
            $this->car(2, 'B3 Plus'),                 // 2
            $this->car(3, 'B3 Core'),                 // 1
            $this->car(4, 'B4 Plus Dark'),            // 2, sport
        ]);

        $count = fn (array $f) => app(Analysis::class)->now(ListingFilter::from($f))['count'];
        $this->assertSame(2, $count(['comfort' => '2']));
        $this->assertSame(3, $count(['comfort' => '3,2']));
        $this->assertSame(2, $count(['sport' => 'ja']));
        $this->assertSame(1, $count(['comfort' => '2', 'sport' => 'nee']));
        $this->assertSame(4, $count(['comfort' => '7']));
        $this->assertSame(['comfort' => '2,3'], ListingFilter::from(['comfort' => '3,2,2'])->values);

        $this->post('/analyse/filters', ['name' => 'Luxe sport', 'filters' => ['comfort' => '3', 'sport' => 'ja']]);
        $this->assertSame(['comfort' => '3', 'sport' => 'ja'], SavedFilter::first()->filters);

        $this->get("/sources/{$this->source->id}?comfort=1,3&sport=nee")->assertOk()
            ->assertInertia(fn ($page) => $page->where('listings.total', 1)->where('filters.comfort', '1,3'));
    }

    public function test_the_unrecognised_titles_page(): void
    {
        $this->scrape([$this->car(1, 'T6 Recharge Ultra Dark'), $this->car(2, 'T6 Recharge Ultra Dark'), $this->car(3, 'B3 Core')]);

        $this->get('/uitvoeringen')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Uitvoeringen/Index')
                ->where('models.0.totaal', 3)
                ->where('models.0.niet_herkend', 2)
                ->where('models.0.titels.0', ['uitvoering' => 'T6 Recharge Ultra Dark', 'aantal' => 2]));

        $this->get('/listings/'.Listing::where('advertentie_id', 3)->value('id'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('listing.uitvoeringslijn', 'Core')->where('listing.comfort_niveau', 1));
    }
}
