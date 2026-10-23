<?php

namespace Tests\Feature;

use App\Console\Commands\Idle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IdleTest extends TestCase
{
    use RefreshDatabase;

    public function test_idle_without_a_running_job(): void
    {
        // A waiting job is no reason to hold a deploy back.
        $this->job('scrape', reserved: false);

        $this->artisan('auto:idle')->assertSuccessful();
    }

    public function test_busy_while_the_worker_runs_a_job(): void
    {
        $this->job('scrape', reserved: true);

        $this->artisan('auto:idle')->expectsOutput('Busy: scrape')->assertExitCode(Idle::BUSY);
    }

    private function job(string $queue, bool $reserved): void
    {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => '{}',
            'attempts' => $reserved ? 1 : 0,
            'reserved_at' => $reserved ? now()->timestamp : null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
    }
}
