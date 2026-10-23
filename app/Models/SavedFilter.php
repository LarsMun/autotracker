<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedFilter extends Model
{
    public const int SLOTS = 8;

    protected $fillable = ['name', 'filters', 'color_slot'];

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }

    /**
     * The lowest colour slot no saved filter uses; once all are taken, the least used one.
     */
    public static function freeSlot(): int
    {
        $used = static::query()->pluck('color_slot')->countBy();

        foreach (range(1, self::SLOTS) as $slot) {
            if (! $used->has($slot)) {
                return $slot;
            }
        }

        return (int) $used->sort()->keys()->first();
    }
}
