<?php

namespace App\Services\ApiNinjas;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

class ApiNinjasAirportRateLimitException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('API Ninjas airport rate limit reached.');
    }

    public static function fromResponse(Response $response): self
    {
        $retryAfter = $response->header('Retry-After');
        $fallback = max(1, (int) config('services.api_ninjas.airports_429_fallback_delay_seconds'));

        if (! is_string($retryAfter) || $retryAfter === '') {
            return new self($fallback);
        }

        if (ctype_digit($retryAfter)) {
            return new self(max(1, (int) $retryAfter));
        }

        try {
            return new self(max(1, now('UTC')->diffInSeconds(CarbonImmutable::parse($retryAfter, 'UTC'), false)));
        } catch (Throwable) {
            return new self($fallback);
        }
    }
}
