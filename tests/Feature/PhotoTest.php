<?php

namespace Tests\Feature;

use App\Jobs\DownloadPhoto;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoTest extends TestCase
{
    use RefreshDatabase;

    private function listing(): Listing
    {
        return Listing::create(['advertentie_id' => 4321, 'foto_url' => 'https://cdn.gaspedaal.nl/images/large/4321.jpg', 'first_seen' => '2026-10-16', 'last_seen' => '2026-10-16']);
    }

    public function test_a_photo_is_stored(): void
    {
        Storage::fake('public');
        Http::fake(fn () => Http::response('jpeg', 200, ['Content-Type' => 'image/jpeg']));
        $listing = $this->listing();

        (new DownloadPhoto($listing->id))->handle();

        $this->assertSame('photos/321/4321.jpg', $listing->fresh()->foto_path);
        Storage::disk('public')->assertExists('photos/321/4321.jpg');
    }

    public function test_a_4xx_marks_the_photo_unavailable_and_is_not_retried(): void
    {
        Log::spy();
        Http::fake(fn () => Http::response('Bad Request', 400, ['Content-Type' => 'text/html']));
        $listing = $this->listing();

        // No exception: the queue does not try again.
        (new DownloadPhoto($listing->id))->handle();

        $listing->refresh();
        $this->assertNotNull($listing->foto_onbeschikbaar_at);
        $this->assertNull($listing->foto_path);
        $this->assertNull($listing->photo());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $context['status'] === 400 && $context['url'] === $listing->foto_url)->once();

        // A second run of the job does not ask the CDN again.
        (new DownloadPhoto($listing->id))->handle();
        Http::assertSentCount(1);
    }

    public function test_a_server_error_is_retried(): void
    {
        Http::fake(fn () => Http::response('', 503));
        $listing = $this->listing();

        $this->expectException(RequestException::class);

        try {
            (new DownloadPhoto($listing->id))->handle();
        } finally {
            $this->assertNull($listing->fresh()->foto_onbeschikbaar_at);
        }
    }
}
