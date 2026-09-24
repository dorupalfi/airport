<?php

namespace App\Services\Airports;

use RuntimeException;

class ExternalAirportLookupDeferredException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter = 5)
    {
        parent::__construct('External airport lookup is already in progress.');
    }
}
