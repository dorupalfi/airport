<?php

namespace App\Services\Analytics;

use App\Models\Airport;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateException;
use App\Models\GateSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AllocationSnapshotCollector
{
    /**
     * @return int Number of airport snapshots stored.
     */
    public function collect(?CarbonImmutable $collectedAt = null): int
    {
        $snapshotAt = ($collectedAt ?? CarbonImmutable::now('UTC'))
            ->utc()
            ->startOfMinute()
            ->subDay();
        $snapshotDate = $snapshotAt->toDateString();
        $dayStart = $snapshotAt->startOfDay();
        $dayEnd = $dayStart->addDay();
        $timestamps = now('UTC');
        $snapshots = [];

        Airport::query()
            ->select('id')
            ->orderBy('id')
            ->each(function (Airport $airport) use (
                &$snapshots,
                $snapshotAt,
                $snapshotDate,
                $dayStart,
                $dayEnd,
                $timestamps,
            ): void {
                $gates = Gate::query()
                    ->where('airport_id', $airport->id)
                    ->get(['id', 'is_active']);

                $gateIds = $gates->pluck('id');
                $activeGateIds = $gates->where('is_active', true)->pluck('id');
                $inactiveGateIds = $gates->where('is_active', false)->pluck('id');

                $activeExceptions = GateException::query()
                    ->whereIn('gate_id', $activeGateIds)
                    ->where(function ($query) use ($snapshotDate): void {
                        $query->whereNull('start_date')
                            ->orWhereDate('start_date', '<=', $snapshotDate);
                    })
                    ->where(function ($query) use ($snapshotDate): void {
                        $query->whereNull('end_date')
                            ->orWhereDate('end_date', '>=', $snapshotDate);
                    });

                $exceptionBlockedGateIds = (clone $activeExceptions)
                    ->distinct()
                    ->pluck('gate_id');

                $busyGateIds = GateSchedule::query()
                    ->whereIn('gate_id', $activeGateIds)
                    ->where('occupied_from', '<=', $snapshotAt)
                    ->where('occupied_until', '>', $snapshotAt)
                    ->whereNotIn('gate_id', $exceptionBlockedGateIds)
                    ->distinct()
                    ->pluck('gate_id');

                $flightCounts = Flight::query()
                    ->leftJoin('gate_schedules', 'gate_schedules.flight_id', '=', 'flights.id')
                    ->where('flights.airport_id', $airport->id)
                    ->where('flights.estimated_departure_at', '>=', $dayStart)
                    ->where('flights.estimated_departure_at', '<', $dayEnd)
                    ->selectRaw(
                        "COUNT(*) as total_flights,
                        SUM(CASE WHEN flights.allocation_status = 'allocated' AND COALESCE(gate_schedules.delay_minutes, 0) = 0 THEN 1 ELSE 0 END) as on_time_flights,
                        SUM(CASE WHEN flights.allocation_status = 'allocated' AND COALESCE(gate_schedules.delay_minutes, 0) > 0 THEN 1 ELSE 0 END) as delayed_flights,
                        SUM(CASE WHEN flights.allocation_status = 'unallocated' THEN 1 ELSE 0 END) as unallocated_flights,
                        SUM(CASE WHEN flights.allocation_status = 'pending' THEN 1 ELSE 0 END) as pending_flights"
                    )
                    ->first();

                $allocatedSchedules = GateSchedule::query()
                    ->join('flights', 'flights.id', '=', 'gate_schedules.flight_id')
                    ->where('flights.airport_id', $airport->id)
                    ->where('flights.allocation_status', 'allocated')
                    ->where('flights.estimated_departure_at', '>=', $dayStart)
                    ->where('flights.estimated_departure_at', '<', $dayEnd);

                $inactiveGateAllocations = (clone $allocatedSchedules)
                    ->whereIn('gate_schedules.gate_id', $inactiveGateIds)
                    ->count();

                $exceptionConflictAllocations = (clone $allocatedSchedules)
                    ->whereIn('gate_schedules.gate_id', $exceptionBlockedGateIds)
                    ->count();

                $invalidGateIds = $inactiveGateIds
                    ->merge($exceptionBlockedGateIds)
                    ->unique()
                    ->values();

                $invalidAllocations = (clone $allocatedSchedules)
                    ->whereIn('gate_schedules.gate_id', $invalidGateIds)
                    ->count();

                $exceptionBlockedGateCount = $exceptionBlockedGateIds->count();
                $busyGateCount = $busyGateIds->count();
                $activeGateCount = $activeGateIds->count();

                $snapshots[] = [
                    'airport_id' => $airport->id,
                    'snapshot_date' => $snapshotDate,
                    'captured_at' => $snapshotAt,
                    'total_gates' => $gateIds->count(),
                    'inactive_gates' => $inactiveGateIds->count(),
                    'exception_blocked_gates' => $exceptionBlockedGateCount,
                    'busy_gates' => $busyGateCount,
                    'free_gates' => max(0, $activeGateCount - $exceptionBlockedGateCount - $busyGateCount),
                    'on_time_flights' => (int) ($flightCounts->on_time_flights ?? 0),
                    'delayed_flights' => (int) ($flightCounts->delayed_flights ?? 0),
                    'unallocated_flights' => (int) ($flightCounts->unallocated_flights ?? 0),
                    'pending_flights' => (int) ($flightCounts->pending_flights ?? 0),
                    'active_exceptions' => (clone $activeExceptions)->count(),
                    'inactive_gate_allocations' => $inactiveGateAllocations,
                    'exception_conflict_allocations' => $exceptionConflictAllocations,
                    'invalid_allocations' => $invalidAllocations,
                    'created_at' => $timestamps,
                    'updated_at' => $timestamps,
                ];
            });

        if ($snapshots === []) {
            return 0;
        }

        DB::table('airport_allocation_snapshots')->upsert(
            $snapshots,
            ['airport_id', 'captured_at'],
            [
                'snapshot_date',
                'total_gates',
                'inactive_gates',
                'exception_blocked_gates',
                'busy_gates',
                'free_gates',
                'on_time_flights',
                'delayed_flights',
                'unallocated_flights',
                'pending_flights',
                'active_exceptions',
                'inactive_gate_allocations',
                'exception_conflict_allocations',
                'invalid_allocations',
                'updated_at',
            ],
        );

        return count($snapshots);
    }
}
