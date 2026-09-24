<?php

namespace App\Services\FlightAllocation;

use App\Jobs\AllocatePendingFlightsJob;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\GateSchedule;

class FlightAllocationResetter
{
    /**
     * @return array{flights_reset: int, schedules_deleted: int}
     */
    public function reset(Airport $airport): array
    {
        $flights = Flight::query()->where('airport_id', $airport->id);
        $schedulesDeleted = GateSchedule::query()
            ->whereIn('flight_id', (clone $flights)->select('id'))
            ->delete();
        $flightsReset = $flights->update(['allocation_status' => 'pending']);

        return [
            'flights_reset' => $flightsReset,
            'schedules_deleted' => $schedulesDeleted,
        ];
    }

    public function queue(Airport $airport): void
    {
        AllocatePendingFlightsJob::dispatch($airport->id)->afterCommit();
    }
}
