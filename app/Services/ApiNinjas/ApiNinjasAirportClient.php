<?php

namespace App\Services\ApiNinjas;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ApiNinjasAirportClient
{
    public function getAirports(string $icao): array
    {
        $apiKey = config('services.api_ninjas.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('API Ninjas airport lookup is not configured.');
        }

        $response = Http::acceptJson()
            ->withHeaders(['X-Api-Key' => $apiKey])
            ->timeout(15)
            ->get(rtrim(config('services.api_ninjas.base_url'), '/').'/airports', ['icao' => $icao]);

        if ($response->status() === 429) {
            throw ApiNinjasAirportRateLimitException::fromResponse($response);
        }

        $response->throw();
        $airports = $response->json();

        if (! is_array($airports)) {
            throw new RuntimeException('API Ninjas returned an invalid airport list.');
        }

        return $airports;
    }
}
