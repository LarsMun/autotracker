<?php

namespace Tests\Feature;

use App\Gaspedaal\Scraper;
use App\Models\Listing;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeGaspedaal;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::create(['name' => 'lars', 'email' => 'lars@auto.local', 'password' => 'geheim-wachtwoord']));
    }

    public function test_every_page_renders(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        Queue::fake();
        Http::fake(['*' => Http::response(FakeGaspedaal::page([FakeGaspedaal::occasion(1), FakeGaspedaal::occasion(2)], 2))]);

        $source = Source::create(['name' => 'BMW', 'url' => 'https://www.gaspedaal.nl/bmw/x3', 'active' => true]);
        $run = app(Scraper::class)->run($source);

        $this->get('/')->assertRedirect('/voor-jou');
        $this->get('/bronnen')->assertOk();
        $this->get('/sources/create')->assertRedirect('/bronnen');
        $this->get("/sources/{$source->id}")->assertOk();
        $this->get("/sources/{$source->id}?filter=gone&sort=-km&q=330e")->assertOk();
        $this->get('/listings/'.Listing::first()->id)->assertOk();
        $this->get('/runs')->assertOk();
        $this->get("/runs/{$run->id}")->assertOk();
    }

    public function test_preview_shows_page_one(): void
    {
        Http::fake(['*' => Http::response(FakeGaspedaal::page([FakeGaspedaal::occasion(1), FakeGaspedaal::occasion(2)], 40))]);

        $this->postJson('/sources/preview', ['url' => 'https://www.gaspedaal.nl/bmw/x3?bmin=2020&page=2'])
            ->assertOk()
            ->assertJsonPath('total', 40)
            ->assertJsonPath('pages', 20)
            ->assertJsonPath('url', 'https://www.gaspedaal.nl/bmw/x3?bmin=2020')
            ->assertJsonPath('cars.0.pk', 184)
            ->assertJsonCount(2, 'cars');
    }

    public function test_preview_refuses_other_sites(): void
    {
        $this->postJson('/sources/preview', ['url' => 'https://example.com/'])->assertUnprocessable();
    }
}
