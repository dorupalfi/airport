<?php

namespace App\Services\OpenSky;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenSkyClient
{
    private const TOKEN_CACHE_KEY = 'opensky:access-token';

    private const TOKEN_LOCK_KEY = 'opensky:token-refresh';

    public function getDepartureFlights(string $icao, CarbonInterface $begin, CarbonInterface $end): array
    {
        $response = $this->departureRequest($icao, $begin, $end, $this->accessToken());

        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);
            $response = $this->departureRequest($icao, $begin, $end, $this->accessToken());
        }

        if ($response->status() === 404) {
            return [];
        }

        if ($response->status() === 429) {
            throw OpenSkyRateLimitException::fromResponse($response);
        }

        $response->throw();
        $flights = $response->json();

        if (! is_array($flights)) {
            throw new RuntimeException('OpenSky returned an invalid flight list.');
        }

        return $flights;
    }

    private function accessToken(): string
    {
        $cachedToken = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cachedToken) && $cachedToken !== '') {
            return $cachedToken;
        }

        return Cache::lock(self::TOKEN_LOCK_KEY, 15)->block(10, function (): string {
            $cachedToken = Cache::get(self::TOKEN_CACHE_KEY);

            if (is_string($cachedToken) && $cachedToken !== '') {
                return $cachedToken;
            }

            $tokenUrl = config('services.opensky.token_url');

            if (! is_string($tokenUrl) || $tokenUrl === '') {
                throw new RuntimeException('OpenSky token endpoint is not configured.');
            }

            $response = Http::asForm()
                ->timeout(15)
                ->post($tokenUrl, [
                    'client_id' => config('services.opensky.client_id'),
                    'client_secret' => config('services.opensky.client_secret'),
                    'grant_type' => 'client_credentials',
                ]);

            $response->throw();
            $token = $response->json('access_token');

            if (! is_string($token) || $token === '') {
                throw new RuntimeException('OpenSky did not return an access token.');
            }

            $expiresIn = (int) $response->json('expires_in', 3600);
            $ttl = max(1, $expiresIn - 60);

            Cache::put(self::TOKEN_CACHE_KEY, $token, now()->addSeconds($ttl));

            return $token;
        });
    }

    private function departureRequest(string $icao, CarbonInterface $begin, CarbonInterface $end, string $token): Response
    {
        $baseUrl = config('services.opensky.base_url');

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new RuntimeException('OpenSky API base URL is not configured.');
        }

        return Http::acceptJson()
            ->withToken($token)
            ->timeout(30)
            ->get(rtrim($baseUrl, '/').'/flights/departure', [
                'airport' => strtoupper($icao),
                'begin' => $begin->utc()->timestamp,
                'end' => $end->utc()->timestamp,
            ]);
    }
}
