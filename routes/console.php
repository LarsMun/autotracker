<?php

use Illuminate\Support\Facades\Schedule;

// The daily run; the command itself adds a random delay so the time varies a little.
Schedule::command('auto:scrape --jitter')->dailyAt(config('gaspedaal.daily_at'));

// Scores come right after the daily run (queued behind it). This is the safety net for a day
// that job did not run, e.g. because the worker was down.
Schedule::command('auto:scores')->dailyAt('10:00')
    ->when(fn () => ! \Illuminate\Support\Facades\DB::table('match_scores')->where('date', today()->toDateString())->exists());
