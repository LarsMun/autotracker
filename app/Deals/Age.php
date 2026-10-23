<?php

namespace App\Deals;

use Carbon\CarbonInterface;

final class Age
{
    /**
     * Age in months. The data has only the build year (no first registration date), so the
     * middle of that year counts: 1 July.
     */
    public static function months(int $bouwjaar, CarbonInterface $on): int
    {
        return ($on->year - $bouwjaar) * 12 + ($on->month - 7);
    }
}
