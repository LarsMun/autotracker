<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Listing extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'datum_geplaatst' => 'datetime',
            'first_seen' => 'date',
            'last_seen' => 'date',
            'gone_at' => 'date',
            'kenmerken_op' => 'date',
            'online_sinds' => 'date',
            'foto_onbeschikbaar_at' => 'datetime',
            'zonder_aanbieder' => 'boolean',
            'particulier' => 'boolean',
            'btw_verrekenbaar' => 'boolean',
            'sport' => 'boolean',
            'uitrusting_opties' => 'array',
        ];
    }

    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(Source::class)
            ->withPivot(['first_seen', 'last_seen', 'gone_at']);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(ListingSnapshot::class);
    }

    /** pk = kW × 1,36 */
    public function pk(): ?int
    {
        return $this->vermogen_kw ? (int) round($this->vermogen_kw * 1.36) : null;
    }

    /** The local copy when there is one, else Gaspedaal's CDN, unless that refused it. */
    public function photo(): ?string
    {
        if ($this->foto_path) {
            return Storage::disk('public')->url($this->foto_path);
        }

        return $this->foto_onbeschikbaar_at ? null : $this->foto_url;
    }

    public function title(): string
    {
        return trim("{$this->merk} {$this->model} {$this->uitvoering}");
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'advertentie_id' => $this->advertentie_id,
            'title' => $this->title(),
            'merk' => $this->merk,
            'model' => $this->model,
            'uitvoering' => $this->uitvoering,
            'bouwjaar' => $this->bouwjaar,
            'kilometerstand' => $this->kilometerstand,
            'prijs' => $this->prijs,
            'pk' => $this->pk(),
            'brandstof' => $this->brandstof,
            'transmissie' => $this->transmissie,
            'kleur' => $this->kleur,
            'plaats' => $this->plaats,
            'aanbieder_type' => $this->aanbieder_type,
            'comfort_niveau' => $this->comfort_niveau,
            'sport' => $this->sport,
            'garantie' => $this->garantie,
            'btw_verrekenbaar' => $this->btw_verrekenbaar,
            'foto' => $this->photo(),
            'first_seen' => $this->first_seen?->toDateString(),
            'last_seen' => $this->last_seen?->toDateString(),
            'gone_at' => $this->gone_at?->toDateString(),
        ];
    }
}
