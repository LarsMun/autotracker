<?php

namespace App\Jobs;

use App\Recommend\Scores;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Today's match scores and ranking again, after the wensen were saved. On its own queue, which
 * the worker takes before the scrapes; while it waits or runs, "Voor jou" says so.
 */
class RecomputeMatches implements ShouldQueue
{
    use Queueable;

    /** Set while a recalculation is queued or running: the token of the latest one. */
    public const string PENDING_KEY = 'scores:bijwerken';

    public int $tries = 2;

    public int $timeout = 1200;

    public function __construct(public string $token)
    {
        $this->onQueue('scores');
    }

    /**
     * Marks the scores as being updated and queues the job.
     */
    public static function start(): void
    {
        $token = (string) Str::uuid();
        // Expires in case the worker is down, so the page does not wait forever.
        Cache::put(self::PENDING_KEY, $token, now()->addHours(2));

        self::dispatch($token);
    }

    public static function pending(): bool
    {
        return Cache::has(self::PENDING_KEY);
    }

    public function handle(Scores $scores): void
    {
        $scores->rematch();
        $this->done();
    }

    public function failed(?Throwable $e): void
    {
        $this->done();
    }

    /** Only the latest save clears the flag: an earlier job finishing leaves a later one pending. */
    private function done(): void
    {
        if (Cache::get(self::PENDING_KEY) === $this->token) {
            Cache::forget(self::PENDING_KEY);
        }
    }
}
