<?php

namespace App\Jobs;

use App\Gaspedaal\Client;
use App\Models\Listing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps a copy of a car's photo, so it can still be shown after the ad (and its CDN image) is gone.
 */
class DownloadPhoto implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 300;

    public function __construct(public int $listingId) {}

    public function handle(): void
    {
        $listing = Listing::find($this->listingId);

        if ($listing === null || $listing->foto_path || $listing->foto_onbeschikbaar_at || ! $listing->foto_url) {
            return;
        }

        Client::pause(1);

        $response = Http::withHeaders(['User-Agent' => config('gaspedaal.user_agent')])
            ->timeout(30)
            ->get($listing->foto_url);

        // 4xx, or a page instead of an image: trying again gives the same answer.
        if ($response->clientError() || ($response->successful() && ! str_starts_with((string) $response->header('Content-Type'), 'image/'))) {
            Log::warning('DownloadPhoto: photo not available', [
                'listing_id' => $listing->id, 'status' => $response->status(), 'content_type' => $response->header('Content-Type'), 'url' => $listing->foto_url,
            ]);
            $listing->forceFill(['foto_onbeschikbaar_at' => now()])->save();

            return;
        }

        // 5xx and the like: the queue tries again later.
        $response->throw();

        $extension = match (strtok((string) $response->header('Content-Type'), ';')) {
            'image/webp' => 'webp',
            'image/png' => 'png',
            default => 'jpg',
        };

        $path = sprintf('photos/%03d/%d.%s', $listing->advertentie_id % 1000, $listing->advertentie_id, $extension);
        Storage::disk('public')->put($path, $response->body());

        $listing->forceFill(['foto_path' => $path])->save();
    }
}
