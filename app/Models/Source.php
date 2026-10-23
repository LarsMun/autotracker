<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    protected $fillable = ['name', 'url', 'active', 'rebase_pending'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'rebase_pending' => 'boolean'];
    }

    public function listings(): BelongsToMany
    {
        return $this->belongsToMany(Listing::class)
            ->withPivot(['first_seen', 'last_seen', 'gone_at']);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ScrapeRun::class);
    }

    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(ScrapeRun::class, 'last_run_id');
    }

    /**
     * The latest run that finished complete; the one "new" and "gone" are counted against.
     */
    public function lastSuccessfulRun(): ?ScrapeRun
    {
        return $this->runs()->where('status', ScrapeRun::SUCCESS)->latest('id')->first();
    }
}
