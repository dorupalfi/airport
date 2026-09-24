<?php

namespace App\Services\Airports;

use App\Models\ExternalAirport;
use App\Services\ApiNinjas\ApiNinjasAirportClient;
use App\Services\ApiNinjas\ApiNinjasAirportRateLimitException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class ExternalAirportResolver
{
    private const RATE_LIMIT_KEY = 'api-ninjas:airports';

    public function __construct(private ApiNinjasAirportClient $client) {}

    public function resolve(string $icao): ?ExternalAirport
    {
        $icao = strtoupper(trim($icao));

        if (! preg_match('/^[A-Z0-9]{4}$/', $icao)) {
            return null;
        }

        if ($airport = $this->localAirport($icao)) {
            return $airport;
        }

        if (Cache::has($this->negativeCacheKey($icao))) {
            return null;
        }

        $lock = Cache::lock("api-ninjas:airport-lookup:{$icao}", 30);

        if (! $lock->get()) {
            if ($airport = $this->localAirport($icao)) {
                return $airport;
            }

            if (Cache::has($this->negativeCacheKey($icao))) {
                return null;
            }

            throw new ExternalAirportLookupDeferredException;
        }

        try {
            if ($airport = $this->localAirport($icao)) {
                return $airport;
            }

            if (Cache::has($this->negativeCacheKey($icao))) {
                return null;
            }

            $this->ensureRateLimitIsAvailable();
            $matches = $this->client->getAirports($icao);
            $match = collect($matches)->first(
                fn (mixed $airport): bool => is_array($airport)
                    && strtoupper((string) Arr::get($airport, 'icao', '')) === $icao,
            );

            if (! is_array($match) || ! $this->hasRequiredFields($match)) {
                Log::warning('External airport could not be resolved.', [
                    'icao' => $icao,
                    'reason' => is_array($match) ? 'incomplete_provider_record' : 'no_exact_provider_match',
                ]);

                Cache::put(
                    $this->negativeCacheKey($icao),
                    true,
                    now()->addSeconds(max(1, (int) config('services.api_ninjas.airport_not_found_cache_seconds'))),
                );

                return null;
            }

            try {
                return ExternalAirport::query()->create([
                    'code' => $icao,
                    'name' => trim((string) $match['name']),
                    'country' => trim((string) $match['country']),
                    // The existing local schema requires city, so only complete provider records are persisted.
                    'city' => trim((string) $match['city']),
                ]);
            } catch (QueryException) {
                return $this->localAirport($icao) ?? throw new \RuntimeException('Unable to persist external airport.');
            }
        } finally {
            $lock->release();
        }
    }

    private function ensureRateLimitIsAvailable(): void
    {
        $maxAttempts = max(1, (int) config('services.api_ninjas.airports_max_attempts'));

        if (RateLimiter::tooManyAttempts(self::RATE_LIMIT_KEY, $maxAttempts)) {
            throw new ApiNinjasAirportRateLimitException(
                max(1, RateLimiter::availableIn(self::RATE_LIMIT_KEY)),
            );
        }

        RateLimiter::hit(
            self::RATE_LIMIT_KEY,
            max(1, (int) config('services.api_ninjas.airports_decay_seconds')),
        );
    }

    private function localAirport(string $icao): ?ExternalAirport
    {
        return ExternalAirport::query()->where('code', $icao)->first();
    }

    private function negativeCacheKey(string $icao): string
    {
        return "api-ninjas:airport-not-found:{$icao}";
    }

    private function hasRequiredFields(array $airport): bool
    {
        return collect(['name', 'country', 'city'])->every(
            fn (string $field): bool => trim((string) Arr::get($airport, $field, '')) !== '',
        );
    }
}
