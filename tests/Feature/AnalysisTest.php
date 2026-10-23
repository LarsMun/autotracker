<?php

namespace Tests\Feature;

use App\Analysis\Analysis;
use App\Analysis\ListingFilter;
use App\Gaspedaal\Scraper;
use App\Models\Listing;
use App\Models\SavedFilter;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeGaspedaal;
use Tests\TestCase;

class AnalysisTest extends TestCase
{
    use RefreshDatabase;

    private ?string $page = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        Queue::fake();
        $this->travelTo(now()->setDate(2026, 10, 16)->setTime(6, 0));
        $this->actingAs(User::create(['name' => 'lars', 'email' => 'l@auto.local', 'password' => 'geheim-wachtwoord']));
        Http::fake(fn () => Http::response($this->page));
    }

    private function day(Source $source, array $occasions): void
    {
        $this->page = FakeGaspedaal::page($occasions, count($occasions), pageSize: 100);
        app(Scraper::class)->run($source);
    }

    private function car(int $id, int $price, int $km = 50000, int $year = 2021, int $kw = 135): array
    {
        $o = FakeGaspedaal::occasion($id, $price, $km);
        $o['autogegevens']['geschiedenis']['bouwjaar'] = $year;
        $o['autogegevens']['motor']['vermogenKw'] = $kw;

        return $o;
    }

    public function test_the_daily_series_uses_the_cars_online_that_day(): void
    {
        $source = Source::create(['name' => 'BMW', 'url' => 'https://www.gaspedaal.nl/bmw', 'active' => true]);

        // Day 1: three cars. Day 2: car 1 cheaper, car 3 gone, car 4 new and expensive.
        $this->day($source, [$this->car(1, 30000), $this->car(2, 20000), $this->car(3, 40000)]);
        $this->travel(1)->day();
        $this->day($source, [$this->car(1, 28000), $this->car(2, 20000), $this->car(4, 50000)]);

        $analysis = app(Analysis::class);
        $daily = $analysis->daily(ListingFilter::from([]));

        $this->assertSame([
            ['date' => '2026-10-16', 'n' => 3, 'avg' => 30000, 'median' => 30000],
            ['date' => '2026-10-17', 'n' => 3, 'avg' => 32667, 'median' => 28000],
        ], $daily);

        // The price filter applies to each day's own price: car 1 (30k, then 28k) only counts on day 2.
        $cheap = $analysis->daily(ListingFilter::from(['prijs_max' => '29000']));
        $this->assertSame([1, 2], array_column($cheap, 'n'));

        // Now: car 3 is gone.
        $now = $analysis->now(ListingFilter::from([]));
        $this->assertSame(3, $now['count']);
        $this->assertSame(28000, $now['median']);
        $this->assertSame(20000, $now['min']);
        $this->assertSame(50000, $now['max']);

        $change = $analysis->change($daily);
        $this->assertSame(-2000, $change['median']);
        $this->assertSame(-6.7, $change['median_pct']);
    }

    public function test_property_and_source_filters(): void
    {
        $a = Source::create(['name' => 'A', 'url' => 'https://www.gaspedaal.nl/a', 'active' => true]);
        $b = Source::create(['name' => 'B', 'url' => 'https://www.gaspedaal.nl/b', 'active' => true]);
        $this->day($a, [$this->car(1, 30000, year: 2022, kw: 135), $this->car(2, 25000, year: 2020, kw: 110)]);
        $this->day($b, [$this->car(3, 35000, year: 2023, kw: 190)]);

        $count = fn (array $f) => app(Analysis::class)->now(ListingFilter::from($f))['count'];

        $this->assertSame(3, $count([]));
        $this->assertSame(2, $count(['bouwjaar_min' => 2022]));
        $this->assertSame(1, $count(['pk_min' => 184, 'pk_max' => 184])); // 135 kW = 184 pk
        $this->assertSame(1, $count(['bron' => $b->id]));
        $this->assertSame(1, $count(['bron' => $b->id, 'bouwjaar_min' => 2022]));
        $this->assertSame(3, $count(['uitvoering' => '330e']));
        $this->assertSame(0, $count(['uitvoering' => 'Cross Country']));
        $this->assertSame(3, $count(['onbekend' => 'x', 'km_max' => '']));
        $this->assertSame([1], array_column(app(Analysis::class)->daily(ListingFilter::from(['bron' => $b->id])), 'n'));
    }

    public function test_the_colour_filter(): void
    {
        $source = Source::create(['name' => 'A', 'url' => 'https://www.gaspedaal.nl/a', 'active' => true]);
        $zwart = $this->car(2, 25000);
        $zwart['autogegevens']['algemeen']['kleur'] = 'ZWART';
        $unknown = $this->car(3, 35000);
        $unknown['autogegevens']['algemeen']['kleur'] = '$undefined';
        $this->day($source, [$this->car(1, 30000), $zwart, $unknown]);

        $this->assertSame(['GRIJS', 'ZWART', null], Listing::orderBy('advertentie_id')->pluck('kleur')->all());

        $count = fn (array $f) => app(Analysis::class)->now(ListingFilter::from($f))['count'];
        $this->assertSame(1, $count(['kleur' => 'ZWART']));
        $this->assertSame(2, $count(['kleur' => 'zwart, grijs']));
        $this->assertSame('GRIJS,ZWART', ListingFilter::from(['kleur' => ['ZWART', 'GRIJS', 'ZWART']])->values['kleur']);

        $this->get('/analyse?kleur=ZWART')->assertOk()->assertInertia(fn ($page) => $page
            ->where('filters.kleur', 'ZWART')
            ->where('options.kleuren', ['GRIJS', 'ZWART'])
            ->where('listings.data.0.kleur', 'ZWART'));
    }

    public function test_the_page_and_saved_filters(): void
    {
        $source = Source::create(['name' => 'BMW', 'url' => 'https://www.gaspedaal.nl/bmw', 'active' => true]);
        $this->day($source, [$this->car(1, 30000), $this->car(2, 20000)]);

        $this->get('/analyse?merk=BMW&prijs_max=25000&sort=-km')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Analysis/Index')
                ->where('filters', ['merk' => 'BMW', 'prijs_max' => 25000])
                ->where('now.count', 1));

        $this->post('/analyse/filters', ['name' => 'Goedkoop', 'filters' => ['prijs_max' => '25000', 'model' => '']])->assertRedirect();
        $this->post('/analyse/filters', ['name' => 'Alles', 'filters' => []])->assertRedirect();

        $cheap = SavedFilter::where('name', 'Goedkoop')->first();
        $this->assertSame(['prijs_max' => 25000], $cheap->filters);
        $this->assertSame([1, 2], SavedFilter::orderBy('id')->pluck('color_slot')->all());

        $this->get("/analyse?vergelijk={$cheap->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('saved.0.name', 'Alles')
                ->where('saved.0.daily', null)
                ->where('saved.1.now.count', 1)
                ->where('saved.1.daily.0.n', 1));

        $this->patch("/analyse/filters/{$cheap->id}", ['name' => 'Onder 25k'])->assertRedirect();
        $this->assertSame('Onder 25k', $cheap->fresh()->name);

        $this->delete("/analyse/filters/{$cheap->id}")->assertRedirect();
        $this->assertSame(1, SavedFilter::count());

        // Its colour is free again for the next one.
        $this->post('/analyse/filters', ['name' => 'Nieuw', 'filters' => []]);
        $this->assertSame(1, SavedFilter::where('name', 'Nieuw')->value('color_slot'));
    }

    public function test_the_list_sorts_by_column_both_ways(): void
    {
        $source = Source::create(['name' => 'BMW', 'url' => 'https://www.gaspedaal.nl/bmw', 'active' => true]);
        $this->day($source, [$this->car(1, 30000, 60000, 2021, 135), $this->car(2, 20000, 10000, 2023, 110), $this->car(3, 40000, 30000, 2022, 190)]);
        Listing::where('advertentie_id', 3)->update(['kilometerstand' => null]);

        $order = fn (string $sort) => $this->get("/analyse?sort={$sort}")->assertOk()->viewData('page')['props']['listings']['data'];
        $ids = fn (string $sort) => array_column($order($sort), 'advertentie_id');

        $this->assertSame([2, 1, 3], $ids('prijs'));
        $this->assertSame([3, 1, 2], $ids('-prijs'));
        $this->assertSame([1, 3, 2], $ids('bouwjaar'));
        $this->assertSame([2, 1, 3], $ids('pk'));
        // Unknown km last, both ways.
        $this->assertSame([2, 1, 3], $ids('km'));
        $this->assertSame([1, 2, 3], $ids('-km'));
        // Unknown sort: by price.
        $this->assertSame([2, 1, 3], $ids('kleur'));
        $this->get('/analyse?sort=-bouwjaar')->assertInertia(fn ($page) => $page->where('sort', '-bouwjaar'));
    }
}
