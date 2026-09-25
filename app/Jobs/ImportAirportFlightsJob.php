<?php

namespace App\Jobs;

use App\Models\Airport;
use App\Models\Flight;
use App\Services\Airports\ExternalAirportLookupDeferredException;
use App\Services\Airports\ExternalAirportResolver;
use App\Services\ApiNinjas\ApiNinjasAirportRateLimitException;
use App\Services\OpenSky\OpenSkyClient;
use App\Services\OpenSky\OpenSkyRateLimitException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ImportAirportFlightsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 7200;

    public function __construct(
        public int $airportId,
        public string $windowStart,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->airportId}:{$this->windowStart}";
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(OpenSkyClient $openSky, ExternalAirportResolver $externalAirportResolver): void
    {
        $airport = Airport::query()->find($this->airportId);

        // Airports currently have no active/inactive field, so every existing airport is managed.
        if (! $airport) {
            return;
        }

        $windowStart = CarbonImmutable::parse($this->windowStart, 'UTC')->startOfHour();
        $windowEnd = $windowStart->endOfHour();

        try {
            $importedFlights = $openSky->getDepartureFlights($airport->code, $windowStart, $windowEnd);
        } catch (OpenSkyRateLimitException $exception) {
            $this->release($exception->retryAfter ?? 300);

            return;
        }

        try {
            $externalAirportIds = $this->externalAirportIds($importedFlights, $externalAirportResolver);
        } catch (ApiNinjasAirportRateLimitException|ExternalAirportLookupDeferredException $exception) {
            $this->release($exception->retryAfter);

            return;
        }

        DB::transaction(function () use ($airport, $externalAirportIds, $importedFlights): void {
            $newPendingFlightsAdded = false;

            foreach ($importedFlights as $importedFlight) {
                if (! is_array($importedFlight)) {
                    continue;
                }

                $icao24 = strtolower(trim((string) Arr::get($importedFlight, 'icao24', '')));
                $firstSeen = Arr::get($importedFlight, 'firstSeen');

                if ($icao24 === '' || ! is_numeric($firstSeen)) {
                    continue;
                }

                $departureAt = CarbonImmutable::createFromTimestampUTC((int) $firstSeen);
                $arrivalAirportCode = $this->airportCode(Arr::get($importedFlight, 'estArrivalAirport'));
                $attributes = [
                    'callsign' => $this->callsign(Arr::get($importedFlight, 'callsign')),
                    'estimated_arrival_at' => $this->timestamp(Arr::get($importedFlight, 'lastSeen')),
                    'departure_external_airport_id' => $externalAirportIds[$this->airportCode(Arr::get($importedFlight, 'estDepartureAirport'))] ?? null,
                    'arrival_external_airport_id' => $externalAirportIds[$arrivalAirportCode] ?? null,
                    'arrival_external_airport_code' => $arrivalAirportCode,
                ];

                $flight = Flight::query()
                    ->where('airport_id', $airport->id)
                    ->where('icao24', $icao24)
                    ->where('estimated_departure_at', $departureAt)
                    ->first();

                if ($flight) {
                    $flight->update($attributes);

                    continue;
                }

                Flight::query()->create([
                    ...$attributes,
                    'airport_id' => $airport->id,
                    'icao24' => $icao24,
                    'estimated_departure_at' => $departureAt,
                    'allocation_status' => 'pending',
                ]);
                $newPendingFlightsAdded = true;
            }

            if ($newPendingFlightsAdded) {
                AllocatePendingFlightsJob::dispatch($airport->id)->afterCommit();
            }
        });
    }

    private function externalAirportIds(array $importedFlights, ExternalAirportResolver $resolver): array
    {
        $codes = collect($importedFlights)
            ->filter(fn (mixed $flight): bool => is_array($flight))
            ->flatMap(fn (array $flight) => [
                $this->airportCode(Arr::get($flight, 'estDepartureAirport')),
                $this->airportCode(Arr::get($flight, 'estArrivalAirport')),
            ])
            ->filter()
            ->unique()
            ->values();

        $externalAirportIds = [];

        foreach ($codes as $code) {
            $externalAirport = $resolver->resolve($code);

            if ($externalAirport) {
                $externalAirportIds[$code] = $externalAirport->id;
            }
        }

        return $externalAirportIds;
    }

    private function airportCode(mixed $code): ?string
    {
        $code = strtoupper(trim((string) $code));

        return $code === '' ? null : $code;
    }

    private function callsign(mixed $callsign): ?string
    {
        $callsign = trim((string) $callsign);

        return $callsign === '' ? null : $callsign;
    }

    private function timestamp(mixed $timestamp): ?CarbonImmutable
    {
        return is_numeric($timestamp) ? CarbonImmutable::createFromTimestampUTC((int) $timestamp) : null;
    }
}
