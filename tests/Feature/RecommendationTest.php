<?php

namespace Tests\Feature;

use App\Gaspedaal\Scraper;
use App\Jobs\RecomputeMatches;
use App\Models\Listing;
use App\Models\Source;
use App\Models\User;
use App\Recommend\Scores;
use App\Recommend\Wensen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeGaspedaal;
use Tests\TestCase;

class RecommendationTest extends TestCase
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
        $this->source = Source::create(['name' => 'V60', 'url' => 'https://www.gaspedaal.nl/volvo/v60', 'active' => true]);
    }

    /**
     * 20 V60s, 2022–2025, priced by age and km with a bit of spread; $cheap makes some cheaper.
     */
    private function market(array $cheap = []): array
    {
        $cars = [];

        for ($i = 1; $i <= 20; $i++) {
            $year = 2022 + $i % 4;
            $km = 5000 + 3500 * $i;
            $o = FakeGaspedaal::occasion($i, (int) (42000 - 3000 * (2026 - $year) - 0.1 * $km + ($i % 3) * 400) - ($cheap[$i] ?? 0), $km);
            $o['autogegevens']['algemeen']['merknaam'] = 'Volvo';
            $o['autogegevens']['algemeen']['modelnaam'] = 'V60';
            $o['autogegevens']['algemeen']['uitvoering'] = $i % 2 ? 'B4 Plus | Pano | Leder | Trekhaak' : 'B3 Core';
            $o['autogegevens']['geschiedenis']['bouwjaar'] = $year;
            $cars[] = $o;
        }

        return $cars;
    }

    private function day(array $cheap = []): void
    {
        $cars = $this->market($cheap);
        $this->page = FakeGaspedaal::page($cars, count($cars), pageSize: 100);
        app(Scraper::class)->run($this->source);
        app(Scores::class)->run();
    }

    /**
     * Saves the wensen and runs the recalculation that queues, as the worker would.
     */
    private function saveWensen(array $wensen): TestResponse
    {
        $before = Queue::pushed(RecomputeMatches::class)->count();
        $response = $this->put('/wensen', $wensen);

        Queue::pushed(RecomputeMatches::class)->slice($before)->each(fn ($job) => app()->call([$job, 'handle']));

        return $response;
    }

    public function test_the_nightly_scores_and_the_voor_jou_page(): void
    {
        $this->day();

        $this->assertSame(1, DB::table('model_factors')->where('scope', 'model')->count());
        $this->assertSame(20, DB::table('deal_scores')->where('date', '2026-10-16')->whereNotNull('verwachte_prijs')->count());
        $this->assertSame(20, DB::table('match_scores')->where('date', '2026-10-16')->where('door_eisen', true)->count());
        $this->assertSame(range(1, 20), DB::table('match_scores')->where('date', '2026-10-16')->orderBy('rang')->pluck('rang')->all());

        $this->get('/voor-jou')->assertOk()->assertInertia(fn ($page) => $page->component('VoorJou/Index')
            ->where('day', '2026-10-16')
            ->where('ranking.total', 20)
            ->where('ranking.data.0.rang', 1)
            ->where('top.vorige_dag', null)
            ->has('ranking.data.0.chips'));

        // Day two: car 3 drops €4.000 and becomes a good deal, not yet a "Check dit".
        $this->travel(1)->day();
        $this->day([3 => 4000]);

        $car3 = Listing::where('advertentie_id', 3)->first();
        $deal = DB::table('deal_scores')->where('listing_id', $car3->id)->where('date', '2026-10-17')->first();
        $this->assertGreaterThan(5, (float) $deal->deal_pct);

        $response = $this->get('/voor-jou')->assertOk();
        $top = $response->viewData('page')['props']['top'];
        $this->assertSame('2026-10-16', $top['vorige_dag']);
        $rank3 = DB::table('match_scores')->where('listing_id', $car3->id)->where('date', '2026-10-17')->value('rang');
        $this->assertLessThanOrEqual(10, $rank3);

        // Every car that is in today's top 10 has a "since" date, and new and dropped ones balance out.
        $this->assertCount(10, $top['sinds']);
        $this->assertSame(count($top['nieuw']), count($top['eruit']));

        // The detail page explains it.
        $this->get("/listings/{$car3->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('score.door_eisen', true)
            ->where('score.cijfer', fn ($c) => $c >= 1 && $c <= 10)
            ->where('score.deal.verwachte_prijs', $deal->verwachte_prijs)
            ->where('score.vakken.0.vak', 'deal')
            ->where('score.vakken.0.cijfer', fn ($c) => $c > 6)
            ->has('score.vakken', 5)
            ->has('score.vergelijkbaar'));
    }

    public function test_the_make_and_model_filter_keeps_the_ranking(): void
    {
        $this->day();

        // Five of the V60s become XC60s, a model of the same make.
        $xc60 = Listing::orderBy('id')->limit(5)->pluck('id');
        Listing::whereKey($xc60)->update(['model' => 'XC60']);

        $this->get('/voor-jou?merk=Volvo&model=Volvo|XC60')->assertOk()->assertInertia(fn ($page) => $page
            ->where('filters', ['merk' => 'Volvo', 'model' => 'Volvo|XC60'])
            ->where('ranking.total', 5)
            ->where('options.0.merk', 'Volvo')
            ->has('options.0.modellen', 2));

        $rows = $this->get('/voor-jou?merk=Volvo&model=Volvo|XC60')->viewData('page')['props']['ranking']['data'];
        $this->assertSame([1, 2, 3, 4, 5], array_column($rows, 'plek'));
        $expected = DB::table('match_scores')->where('date', '2026-10-16')->whereIn('listing_id', $xc60)->orderBy('rang')->pluck('rang')->all();
        $this->assertSame($expected, array_column($rows, 'rang'));

        // Remembered: without filters in the URL, back to the last ones.
        $this->get('/voor-jou')->assertRedirect('/voor-jou?merk=Volvo&model=Volvo%7CXC60');
        // An empty merk clears it; a model without its make is dropped.
        $this->get('/voor-jou?merk=&model=Volvo|XC60')->assertOk()->assertInertia(fn ($page) => $page
            ->where('filters', ['merk' => '', 'model' => ''])
            ->where('ranking.total', 20)
            ->where('ranking.data.0.plek', 1));
        $this->get('/voor-jou')->assertOk();

        // Several makes; one with no cars in the ranking leaves the rest.
        $this->get('/voor-jou?merk=BMW,Volvo')->assertInertia(fn ($page) => $page->where('filters.merk', 'BMW,Volvo')->where('ranking.total', 20));
    }

    public function test_saving_the_wensen_recomputes_the_ranking(): void
    {
        $this->day();

        $this->get('/wensen')->assertOk()->assertInertia(fn ($page) => $page->component('Wensen/Edit')
            ->where('wensen.filters.prijs_max', 40000)
            ->where('merken.0.merk', 'Volvo')
            ->has('merken.0.modellen', 1));

        $wensen = Wensen::defaults();
        $wensen['filters']['prijs_max'] = 30000;

        $this->saveWensen($wensen)->assertRedirect('/voor-jou');

        $this->assertSame(30000, Wensen::get()['filters']['prijs_max']);
        $passing = DB::table('match_scores')->where('date', '2026-10-16')->where('door_eisen', true)->count();
        $this->assertSame(Listing::where('prijs', '<=', 30000)->count(), $passing);
        $this->assertGreaterThan(0, $passing);
        $this->assertLessThan(20, $passing);
        $this->assertSame(['prijs buiten je range'], json_decode(DB::table('match_scores')->where('date', '2026-10-16')->where('door_eisen', false)->value('afgewezen'), true));

        // Colours: FakeGaspedaal's cars are GRIJS.
        $wensen['filters']['prijs_max'] = 40000;
        $wensen['filters']['kleuren'] = ['ZWART'];
        $this->saveWensen($wensen);
        $this->assertSame(0, DB::table('match_scores')->where('date', '2026-10-16')->where('door_eisen', true)->count());
        $wensen['filters']['kleuren'] = ['ZWART', 'GRIJS'];
        $this->saveWensen($wensen);
        $this->assertSame(20, DB::table('match_scores')->where('date', '2026-10-16')->where('door_eisen', true)->count());
        $this->get('/wensen')->assertInertia(fn ($page) => $page->where('kleuren.0.kleur', 'GRIJS'));

        // Another make, or another model of this one, leaves nothing; a model without its make is dropped.
        $wensen['filters']['merken'] = ['BMW'];
        $wensen['filters']['modellen'] = ['Volvo|V60'];
        $this->saveWensen($wensen);
        $this->assertSame([], Wensen::get()['filters']['modellen']);
        $this->assertSame(0, DB::table('match_scores')->where('date', '2026-10-16')->where('door_eisen', true)->count());
        $wensen['filters']['merken'] = ['Volvo'];
        $wensen['filters']['modellen'] = ['Volvo|XC60'];
        $this->saveWensen($wensen);
        $this->assertSame(0, DB::table('match_scores')->where('date', '2026-10-16')->where('door_eisen', true)->count());
        $wensen['filters']['modellen'] = ['Volvo|V60'];
        $this->saveWensen($wensen);
        $this->assertSame(20, DB::table('match_scores')->where('date', '2026-10-16')->where('door_eisen', true)->count());
    }

    public function test_the_ranking_is_by_cijfer_then_price(): void
    {
        $this->day();

        $rows = DB::table('match_scores as m')->join('listings as l', 'l.id', '=', 'm.listing_id')
            ->where('m.date', '2026-10-16')->orderBy('m.rang')->get(['m.cijfer', 'l.prijs'])
            ->map(fn ($r) => [-(float) $r->cijfer, $r->prijs])->all();
        $sorted = $rows;
        sort($sorted);
        $this->assertSame($sorted, $rows);

        // Only bouwjaar counts: the cars of one year share a cijfer, the cheapest of them first.
        $wensen = Wensen::defaults();
        $wensen['vakken'] = array_map(fn ($v) => ['belang' => 0] + $v, $wensen['vakken']);
        $wensen['vakken']['bouwjaar']['belang'] = 5;
        $this->saveWensen($wensen);

        $top = DB::table('match_scores as m')->join('listings as l', 'l.id', '=', 'm.listing_id')
            ->where('m.date', '2026-10-16')->orderBy('m.rang')->limit(5)->get(['m.cijfer', 'l.bouwjaar', 'l.prijs']);
        $this->assertSame([2025], $top->pluck('bouwjaar')->unique()->values()->all());
        $this->assertSame(Listing::where('bouwjaar', 2025)->orderBy('prijs')->limit(5)->pluck('prijs')->all(), $top->pluck('prijs')->all());
    }

    public function test_the_live_preview_gives_the_top_5_that_voor_jou_shows_after_saving(): void
    {
        $this->day();
        $saved = DB::table('match_scores')->where('date', '2026-10-16')->pluck('rang', 'listing_id')->all();

        // Only km counts now, and the deal not at all.
        $wensen = Wensen::defaults();
        $wensen['vakken'] = array_map(fn ($v) => ['belang' => 0] + $v, $wensen['vakken']);
        $wensen['vakken']['km']['belang'] = 5;

        $preview = $this->postJson('/wensen/voorbeeld', $wensen)->assertOk()->json();

        $this->assertSame(20, $preview['voldoen']);
        $this->assertCount(5, $preview['top']);
        $this->assertSame([1, 2, 3, 4, 5], array_column($preview['top'], 'rang'));
        foreach ($preview['top'] as $row) {
            $this->assertSame($saved[$row['id']] - $row['rang'], $row['verschuiving']);
        }
        // A preview stores nothing.
        $this->assertSame($saved, DB::table('match_scores')->where('date', '2026-10-16')->pluck('rang', 'listing_id')->all());

        $this->saveWensen($wensen)->assertRedirect('/voor-jou');
        $ranking = $this->get('/voor-jou')->viewData('page')['props']['ranking']['data'];

        $this->assertSame(array_column($preview['top'], 'id'), array_column(array_column(array_slice($ranking, 0, 5), 'car'), 'id'));
        $this->assertEquals(array_column($preview['top'], 'cijfer'), array_column(array_slice($ranking, 0, 5), 'cijfer'));

        // Saved now: nothing moved.
        $this->assertSame([0, 0, 0, 0, 0], array_column($this->postJson('/wensen/voorbeeld', $wensen)->json('top'), 'verschuiving'));

        // Only the four importance steps.
        $wensen['vakken']['km']['belang'] = 2;
        $this->postJson('/wensen/voorbeeld', $wensen)->assertUnprocessable()->assertJsonValidationErrors('vakken.km.belang');
        $wensen['vakken']['km']['belang'] = 5;

        // The 10 lies beyond the 6: fewer km than the maximum, a later year, more pk.
        foreach ([['km', 'tien', 80000], ['bouwjaar', 'tien', 2022], ['vermogen', 'tien', 150], ['deal', 'tien', 0]] as [$vak, $key, $value]) {
            $wrong = $wensen;
            $wrong['vakken'][$vak][$key] = $value;
            $this->postJson('/wensen/voorbeeld', $wrong)->assertUnprocessable()->assertJsonValidationErrors("vakken.{$vak}.{$key}");
        }
        $wrong = $wensen;
        $wrong['filters']['prijs_min'] = 50000;
        $this->postJson('/wensen/voorbeeld', $wrong)->assertUnprocessable()->assertJsonValidationErrors('filters.prijs_max');

        // Any positive whole number, not only multiples of a step.
        $odd = $wensen;
        $odd['filters'] = ['prijs_max' => 40250, 'km_max' => 80000, 'bouwjaar_min' => 1985] + $wensen['filters'];
        $odd['vakken']['vermogen']['min'] = 155;
        $this->postJson('/wensen/voorbeeld', $odd)->assertOk();
        $odd['filters']['km_max'] = 0;
        $this->postJson('/wensen/voorbeeld', $odd)->assertUnprocessable()->assertJsonValidationErrors('filters.km_max');
        $odd['filters']['km_max'] = 80000.5;
        $this->postJson('/wensen/voorbeeld', $odd)->assertUnprocessable()->assertJsonValidationErrors('filters.km_max');
    }

    public function test_the_command(): void
    {
        $this->page = FakeGaspedaal::page($this->market(), 20, pageSize: 100);
        app(Scraper::class)->run($this->source);

        $this->artisan('auto:scores')->expectsOutputToContain("20 auto's, ")->assertSuccessful();
    }

    public function test_saving_the_wensen_queues_the_recalculation_and_voor_jou_says_so(): void
    {
        $this->day();
        $before = DB::table('match_scores')->where('date', '2026-10-16')->pluck('rang', 'listing_id')->all();

        $wensen = Wensen::defaults();
        $wensen['filters']['prijs_max'] = 30000;

        $this->put('/wensen', $wensen)->assertRedirect('/voor-jou')->assertSessionHas('flash', 'Wensen opgeslagen; de scores worden bijgewerkt.');

        // Nothing recomputed in the request itself.
        Queue::assertPushedOn('scores', RecomputeMatches::class);
        $this->assertSame($before, DB::table('match_scores')->where('date', '2026-10-16')->pluck('rang', 'listing_id')->all());
        $this->get('/voor-jou')->assertInertia(fn ($page) => $page->where('bijwerken', true)->where('ranking.total', 20));

        // Saved again before the worker got to it: the first job leaves the second one pending.
        $this->put('/wensen', $wensen);
        [$first, $second] = Queue::pushed(RecomputeMatches::class)->all();

        app()->call([$first, 'handle']);
        $this->get('/voor-jou')->assertInertia(fn ($page) => $page->where('bijwerken', true));

        app()->call([$second, 'handle']);
        $this->get('/voor-jou')->assertInertia(fn ($page) => $page->where('bijwerken', false)
            ->where('ranking.total', Listing::where('prijs', '<=', 30000)->count()));

        // A job that fails for good does not leave the page waiting.
        $this->put('/wensen', $wensen);
        Queue::pushed(RecomputeMatches::class)->last()->failed(new \RuntimeException('test'));
        $this->assertFalse(RecomputeMatches::pending());
    }

    public function test_one_car_with_an_impossible_deal_score_does_not_stop_the_others(): void
    {
        Log::spy();

        // A typo of 40 million among the V60s, and five Picantos of one year with hardly any km.
        $cars = $this->market();
        $cars[4]['prijs']['totaalInclusiefBtw'] = 40_000_000;

        for ($i = 101; $i <= 105; $i++) {
            $o = FakeGaspedaal::occasion($i, 17000 + $i * 10, 1000 + $i);
            $o['autogegevens']['algemeen']['merknaam'] = 'Kia';
            $o['autogegevens']['algemeen']['modelnaam'] = 'Picanto';
            $o['autogegevens']['geschiedenis']['bouwjaar'] = 2025;
            $cars[] = $o;
        }

        $this->page = FakeGaspedaal::page($cars, count($cars), pageSize: 100);
        app(Scraper::class)->run($this->source);

        // No scores yet today: saving the wensen runs the whole calculation, as it did when it failed.
        $this->saveWensen(Wensen::defaults())->assertRedirect('/voor-jou');

        // The typo is left out of the V60s' regression; the Picantos, without spread, get none.
        $this->assertSame(19, DB::table('model_factors')->where('scope', 'model')->where('model', 'V60')->value('n'));
        $this->assertFalse(DB::table('model_factors')->where('merk', 'Kia')->exists());
        $this->assertSame(25, DB::table('deal_scores')->where('date', '2026-10-16')->count());
        $typo = DB::table('deal_scores')->where('listing_id', Listing::where('advertentie_id', 5)->value('id'))->first();
        $this->assertNull($typo->verwachte_prijs);
        $this->assertNull($typo->deal_pct);
        $this->assertSame(1, (int) $typo->onbetrouwbaar);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'out of range') && $context['prijs'] === 40_000_000)->once();

        // Every stored value fits, and the other V60s are scored as usual.
        $this->assertSame(0, DB::table('deal_scores')->where('verwachte_prijs', '<=', 0)->count());
        $this->assertSame(19, DB::table('deal_scores')->whereNotNull('verwachte_prijs')->count());

        $this->get('/voor-jou?merk=')->assertInertia(fn ($page) => $page->where('bijwerken', false)->where('ranking.total', fn ($n) => $n > 0));
        $this->get("/listings/{$typo->listing_id}")->assertInertia(fn ($page) => $page->where('score.deal.onbetrouwbaar', true));
    }
}
