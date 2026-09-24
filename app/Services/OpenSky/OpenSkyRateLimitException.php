<?php

namespace App\Services\OpenSky;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

class OpenSkyRateLimitException extends RuntimeException
{
    public function __construct(public readonly ?int $retryAfter = null)
    {
        parent::__construct('OpenSky rate limit reached.');
    }

    public static function fromResponse(Response $response): self
    {
        $retryAfter = $response->header('Retry-After');

        if (! is_string($retryAfter) || $retryAfter === '') {
            return new self;
        }

        if (ctype_digit($retryAfter)) {
            return new self(max(1, (int) $retryAfter));
        }

        try {
            return new self(max(1, now('UTC')->diffInSeconds(CarbonImmutable::parse($retryAfter, 'UTC'), false)));
        } catch (Throwable) {
            return new self;
        }
    }
}
