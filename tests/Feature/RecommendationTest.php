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

    /** The listing ids "Voor jou" shows, in order, over every page. */
    private function list(): array
    {
        $ids = [];

        for ($page = 1; ; $page++) {
            $ranking = $this->get("/voor-jou?page={$page}")->viewData('page')['props']['ranking'];
            $ids = [...$ids, ...array_column(array_column($ranking['data'], 'car'), 'id')];

            if ($page >= $ranking['last_page']) {
                return $ids;
            }
        }
    }

    private function filters(array $filters): TestResponse
    {
        return $this->put('/voor-jou/filters', $filters + Wensen::defaults()['filters']);
    }

    public function test_the_nightly_scores_and_the_voor_jou_page(): void
    {
        $this->day();

        $this->assertSame(1, DB::table('model_factors')->where('scope', 'model')->count());
        $this->assertSame(20, DB::table('deal_scores')->where('date', '2026-10-16')->whereNotNull('verwachte_prijs')->count());
        $this->assertSame(20, DB::table('match_scores')->where('date', '2026-10-16')->whereNotNull('cijfer')->count());

        $this->get('/voor-jou')->assertOk()->assertInertia(fn ($page) => $page->component('VoorJou/Index')
            ->where('day', '2026-10-16')
            ->where('ranking.total', 20)
            ->where('ranking.data.0.rang', 1)
            ->where('ranking.data.1.rang', 2)
            ->where('ranking.data.0.cijfer', fn ($c) => $c >= 1 && $c <= 10)
            ->has('ranking.data.0.cijfers', 5)
            ->has('ranking.data.0.chips')
            ->where('options.merken.0.merk', 'Volvo')
            ->has('options.bouwjaren', 4)
            ->where('filters.merken', []));

        // Day two: car 3 drops €4.000 and becomes a good deal, not yet a "Check dit".
        $this->travel(1)->day();
        $this->day([3 => 4000]);

        $car3 = Listing::where('advertentie_id', 3)->first();
        $deal = DB::table('deal_scores')->where('listing_id', $car3->id)->where('date', '2026-10-17')->first();
        $this->assertGreaterThan(5, (float) $deal->deal_pct);
        $this->assertLessThan(10, array_search($car3->id, $this->list(), true));

        // The detail page explains it.
        $this->get("/listings/{$car3->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('score.afgewezen', [])
            ->where('score.online', true)
            ->where('score.rang', array_search($car3->id, $this->list(), true) + 1)
            ->where('score.cijfer', fn ($c) => $c >= 1 && $c <= 10)
            ->where('score.deal.verwachte_prijs', $deal->verwachte_prijs)
            ->where('score.vakken.0.vak', 'deal')
            ->where('score.vakken.0.cijfer', fn ($c) => $c > 6)
            ->has('score.vakken', 5)
            ->has('score.vergelijkbaar'));
    }

    public function test_the_filters_narrow_the_list_at_once(): void
    {
        $this->day();
        $all = $this->list();

        // Five of the V60s become XC60s, a model of the same make.
        $xc60 = Listing::orderBy('id')->limit(5)->pluck('id')->all();
        Listing::whereKey($xc60)->update(['model' => 'XC60']);

        // Nothing queued: the cijfers stay, only the list changes.
        $this->filters(['merken' => ['Volvo'], 'modellen' => ['Volvo|XC60']])->assertRedirect('/voor-jou');
        Queue::assertNotPushed(RecomputeMatches::class);
        $this->assertSame(['Volvo|XC60'], Wensen::get()['filters']['modellen']);
        $this->assertSame(array_values(array_intersect($all, $xc60)), $this->list());
        $this->get('/voor-jou')->assertInertia(fn ($page) => $page->where('ranking.total', 5)->where('ranking.data.0.rang', 1)->has('options.merken.0.modellen', 2));

        // A model without its make is dropped; another make leaves nothing.
        $this->filters(['merken' => ['BMW'], 'modellen' => ['Volvo|V60']]);
        $this->assertSame([], Wensen::get()['filters']['modellen']);
        $this->assertSame([], $this->list());

        // Price range, years, steps of km and comfort levels.
        $this->filters(['prijs_max' => 30000]);
        $this->assertSame(Listing::where('prijs', '<=', 30000)->orderBy('id')->pluck('id')->all(), collect($this->list())->sort()->values()->all());
        $this->filters(['bouwjaren' => [2024, 2025]]);
        $this->assertSame(Listing::whereIn('bouwjaar', [2024, 2025])->count(), count($this->list()));
        $this->filters(['km' => [10000, 20000]]);
        $this->assertSame(Listing::whereBetween('kilometerstand', [10000, 29999])->count(), count($this->list()));
        $this->filters(['luxe' => [2]]);
        $this->assertSame(Listing::where('comfort_niveau', 2)->count(), count($this->list()));

        // Colours: FakeGaspedaal's cars are GRIJS.
        $this->filters(['kleuren' => ['ZWART']]);
        $this->assertSame([], $this->list());
        $this->filters(['kleuren' => ['ZWART', 'GRIJS']]);
        $this->assertCount(20, $this->list());

        // Only steps of 10.000 km.
        $this->filters(['km' => [15000]])->assertSessionHasErrors('km.0');

        // Cleared: every car, in the same order as before.
        $this->filters([]);
        $this->assertSame($all, $this->list());
    }

    public function test_the_list_is_by_cijfer_then_price(): void
    {
        $this->day();

        $cars = \App\Recommend\Ranking::cars('2026-10-16');
        $order = array_map(fn ($id) => [-$cars[$id]['cijfer'], $cars[$id]['prijs']], $this->list());
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order);

        // Only bouwjaar counts: the cars of one year share a cijfer, the cheapest of them first.
        $wensen = Wensen::defaults();
        $wensen['vakken'] = array_map(fn ($v) => ['belang' => 0] + $v, $wensen['vakken']);
        $wensen['vakken']['bouwjaar']['belang'] = 5;
        $this->saveWensen($wensen);

        $top = Listing::whereKey(array_slice($this->list(), 0, 5))->get()->sortBy('prijs');
        $this->assertSame([2025], $top->pluck('bouwjaar')->unique()->values()->all());
        $this->assertSame(Listing::where('bouwjaar', 2025)->orderBy('prijs')->limit(5)->pluck('id')->all(), array_slice($this->list(), 0, 5));
    }

    public function test_the_live_preview_gives_the_top_5_that_voor_jou_shows_after_saving(): void
    {
        $this->day();
        $saved = $this->list();

        $this->get('/wensen')->assertOk()->assertInertia(fn ($page) => $page->component('Wensen/Edit')->where('vakken.km.zes', 80000)->has('standaard'));

        // Only km counts now, and the deal not at all.
        $wensen = Wensen::defaults();
        $wensen['vakken'] = array_map(fn ($v) => ['belang' => 0] + $v, $wensen['vakken']);
        $wensen['vakken']['km']['belang'] = 5;

        $preview = $this->postJson('/wensen/voorbeeld', $wensen)->assertOk()->json();

        $this->assertSame(20, $preview['voldoen']);
        $this->assertCount(5, $preview['top']);
        $this->assertSame([1, 2, 3, 4, 5], array_column($preview['top'], 'rang'));
        foreach ($preview['top'] as $row) {
            $this->assertSame(array_search($row['id'], $saved, true) + 1 - $row['rang'], $row['verschuiving']);
        }
        // A preview stores nothing.
        $this->assertSame($saved, $this->list());

        // The preview keeps to the filters.
        $this->filters(['prijs_max' => 30000]);
        $this->assertSame(Listing::where('prijs', '<=', 30000)->count(), $this->postJson('/wensen/voorbeeld', $wensen)->json('voldoen'));
        $this->filters([]);

        $this->saveWensen($wensen)->assertRedirect('/voor-jou');
        $ranking = $this->get('/voor-jou')->viewData('page')['props']['ranking']['data'];

        $this->assertSame(array_column($preview['top'], 'id'), array_column(array_column(array_slice($ranking, 0, 5), 'car'), 'id'));
        $this->assertEquals(array_column($preview['top'], 'cijfer'), array_column(array_slice($ranking, 0, 5), 'cijfer'));
        // Saving the vakken keeps the filters.
        $this->assertSame(Wensen::defaults()['filters'], Wensen::get()['filters']);

        // Saved now: nothing moved.
        $this->assertSame([0, 0, 0, 0, 0], array_column($this->postJson('/wensen/voorbeeld', $wensen)->json('top'), 'verschuiving'));

        // Only the four importance steps.
        $wensen['vakken']['km']['belang'] = 2;
        $this->postJson('/wensen/voorbeeld', $wensen)->assertUnprocessable()->assertJsonValidationErrors('vakken.km.belang');
        $wensen['vakken']['km']['belang'] = 5;

        // The 10 lies beyond the 6: fewer km, a later year, more pk, below the market.
        foreach ([['km', 'tien', 80000], ['bouwjaar', 'tien', 2022], ['vermogen', 'tien', 150], ['deal', 'tien', 0]] as [$vak, $key, $value]) {
            $wrong = $wensen;
            $wrong['vakken'][$vak][$key] = $value;
            $this->postJson('/wensen/voorbeeld', $wrong)->assertUnprocessable()->assertJsonValidationErrors("vakken.{$vak}.{$key}");
        }
    }

    public function test_the_command(): void
    {
        $this->page = FakeGaspedaal::page($this->market(), 20, pageSize: 100);
        app(Scraper::class)->run($this->source);

        $this->artisan('auto:scores')->expectsOutputToContain('20 met cijfer.')->assertSuccessful();
    }

    public function test_saving_the_wensen_queues_the_recalculation_and_voor_jou_says_so(): void
    {
        $this->day();
        $before = $this->list();

        $wensen = Wensen::defaults();
        $wensen['vakken'] = array_map(fn ($v) => ['belang' => 0] + $v, $wensen['vakken']);
        $wensen['vakken']['km']['belang'] = 5;

        $this->put('/wensen', $wensen)->assertRedirect('/voor-jou')->assertSessionHas('flash', 'Wensen opgeslagen; de cijfers worden bijgewerkt.');

        // Nothing recomputed in the request itself.
        Queue::assertPushedOn('scores', RecomputeMatches::class);
        $this->assertSame($before, $this->list());
        $this->get('/voor-jou')->assertInertia(fn ($page) => $page->where('bijwerken', true)->where('ranking.total', 20));

        // Saved again before the worker got to it: the first job leaves the second one pending.
        $this->put('/wensen', $wensen);
        [$first, $second] = Queue::pushed(RecomputeMatches::class)->all();

        app()->call([$first, 'handle']);
        $this->get('/voor-jou')->assertInertia(fn ($page) => $page->where('bijwerken', true));

        app()->call([$second, 'handle']);
        $this->get('/voor-jou')->assertInertia(fn ($page) => $page->where('bijwerken', false));
        // Only km counts: fewest km first.
        $this->assertSame(Listing::orderBy('kilometerstand')->pluck('id')->all(), $this->list());

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

        $this->get('/voor-jou')->assertInertia(fn ($page) => $page->where('bijwerken', false)->where('ranking.total', fn ($n) => $n > 0));
        $this->get("/listings/{$typo->listing_id}")->assertInertia(fn ($page) => $page->where('score.deal.onbetrouwbaar', true));
    }
}
