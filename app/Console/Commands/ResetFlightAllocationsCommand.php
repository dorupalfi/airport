<?php

namespace App\Console\Commands;

use App\Models\Airport;
use App\Models\Flight;
use App\Models\GateSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetFlightAllocationsCommand extends Command
{
    protected $signature = 'flights:reset-allocation
        {airportId? : Optional managed airport ID}';

    protected $description = 'Mark flights as pending and remove their gate schedules.';

    public function handle(): int
    {
        $airport = $this->resolveAirport();

        if ($airport === false) {
            return self::FAILURE;
        }

        [$flightsReset, $schedulesDeleted] = DB::transaction(function () use ($airport): array {
            $flights = Flight::query();

            if ($airport) {
                $flights->where('airport_id', $airport->id);
            }

            $schedulesDeleted = GateSchedule::query()
                ->whereIn('flight_id', (clone $flights)->select('id'))
                ->delete();
            $flightsReset = $flights->update(['allocation_status' => 'pending']);

            return [$flightsReset, $schedulesDeleted];
        });

        $scope = $airport
            ? "airport {$airport->code} (ID {$airport->id})"
            : 'all airports';

        $this->info("Reset {$flightsReset} flights and deleted {$schedulesDeleted} schedules for {$scope}.");

        return self::SUCCESS;
    }

    private function resolveAirport(): Airport|false|null
    {
        $airportId = $this->argument('airportId');

        if ($airportId === null) {
            return null;
        }

        $airport = Airport::query()->find($airportId);

        if (! $airport) {
            $this->error("Managed airport [{$airportId}] was not found.");

            return false;
        }

        return $airport;
    }
}
