<?php

return [

    // Gaspedaal serves plain HTML to an ordinary browser; no headless browser needed.
    'user_agent' => env('GASPEDAAL_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'),

    'timeout' => (int) env('GASPEDAAL_TIMEOUT', 30),

    // Pause between two requests, in seconds: a random value in this range.
    'delay_min' => (int) env('GASPEDAAL_DELAY_MIN', 3),
    'delay_max' => (int) env('GASPEDAAL_DELAY_MAX', 7),

    // Attempts per page, and the wait before each retry in seconds.
    'attempts' => 3,
    'retry_waits' => [15, 60],

    // A run only counts as complete (and may mark cars as gone) when all pages were fetched and
    // at least this share of the reported total was actually seen. Listings shift between pages
    // while a run is busy, so a few can be missed or seen twice.
    'min_coverage' => (float) env('GASPEDAAL_MIN_COVERAGE', 0.97),

    // The daily run: the start time, plus a random delay of up to jitter minutes.
    'daily_at' => env('GASPEDAAL_DAILY_AT', '05:15'),
    'jitter_minutes' => (int) env('GASPEDAAL_JITTER_MINUTES', 75),

    // Pause between two sources in the daily run, in seconds.
    'source_gap_min' => 20,
    'source_gap_max' => 60,

    // Where the raw pages go (gzipped), on the local disk.
    'raw_disk' => 'local',
    'raw_dir' => 'raw',

    // Uptime Kuma push monitor, e.g. https://status.example/api/push/AbC123 (optional).
    // Pinged every hour with status up or down; Kuma alerts when it is down or goes silent.
    'push_url' => env('MONITOR_PUSH_URL'),

    // A source whose latest successful run is older than this counts as a problem.
    'stale_hours' => 30,
];
