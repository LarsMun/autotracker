<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScrapeRun extends Model
{
    public const string RUNNING = 'running';

    /** All pages fetched and enough cars seen: missing cars are marked gone. */
    public const string SUCCESS = 'success';

    /** All pages fetched but too few cars seen: the data is kept, nothing is marked gone. */
    public const string INCOMPLETE = 'incomplete';

    /** A page could not be fetched or read, or there were no cars at all. */
    public const string FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'run_date' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(ScrapePage::class);
    }

    public function addWarning(string $warning): void
    {
        $this->warnings = trim(($this->warnings ?? '')."\n".$warning);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'source_id' => $this->source_id,
            'source' => $this->source?->name,
            'run_date' => $this->run_date?->toDateString(),
            'status' => $this->status,
            'trigger' => $this->trigger,
            'total_reported' => $this->total_reported,
            'pages_expected' => $this->pages_expected,
            'pages_fetched' => $this->pages_fetched,
            'listings_seen' => $this->listings_seen,
            'new_count' => $this->new_count,
            'gone_count' => $this->gone_count,
            'error' => $this->error,
            'warnings' => $this->warnings,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'duration' => $this->started_at && $this->finished_at
                ? (int) $this->started_at->diffInSeconds($this->finished_at) : null,
        ];
    }
}
