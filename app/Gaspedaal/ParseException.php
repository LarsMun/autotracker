<?php

namespace App\Gaspedaal;

use RuntimeException;

class ParseException extends RuntimeException
{
    /** The page that could not be read, kept for debugging. */
    public ?string $html = null;
}
