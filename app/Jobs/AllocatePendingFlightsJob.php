<?php

namespace App\Jobs;

use App\Models\Airport;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateException;
use App\Models\GateSchedule;
use App\Services\FlightAllocation\FlightAllocationPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AllocatePendingFlightsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    private const BATCH_SIZE = 10;

    private const LOCK_TTL_SECONDS = 180;

    private const LOCK_RETRY_SECONDS = 5;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $airportId,
    ) {}

    public function handle(FlightAllocationPlanner $planner): void
    {
        $lock = Cache::lock($this->lockKey(), self::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            $this->release(self::LOCK_RETRY_SECONDS);

            return;
        }

        $shouldDispatchNextJob = false;

        try {
            $airport = Airport::query()->find($this->airportId);

            if (! $airport) {
                return;
            }

            $flightIds = $this->pendingFlightsQuery()
                ->orderBy('estimated_departure_at')
                ->orderBy('id')
                ->limit(self::BATCH_SIZE)
                ->pluck('id');

            foreach ($flightIds as $flightId) {
                $this->processFlight((int) $flightId, $planner);
            }

            $shouldDispatchNextJob = $this->pendingFlightsQuery()->exists();
        } finally {
            $lock->release();
        }

        if ($shouldDispatchNextJob) {
            self::dispatch($this->airportId);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->airportId;
    }

    private function processFlight(int $flightId, FlightAllocationPlanner $planner): void
    {
        DB::transaction(function () use ($flightId, $planner): void {
            $flight = Flight::query()
                ->whereKey($flightId)
                ->where('airport_id', $this->airportId)
                ->where('allocation_status', 'pending')
                ->lockForUpdate()
                ->first();

            if (! $flight) {
                return;
            }

            // Self-healing in case a schedule exists but the flight status was not updated.
            $existingSchedule = GateSchedule::query()
                ->where('flight_id', $flight->id)
                ->first();

            if ($existingSchedule) {
                $flight->update([
                    'allocation_status' => $existingSchedule->unallocation_reason ? 'unallocated' : 'allocated',
                ]);

                return;
            }

            if (! $flight->estimated_departure_at) {
                $this->markAsUnallocated($flight, 'Missing departure time');

                return;
            }

            $airport = Airport::query()->find($this->airportId);

            if (! $airport) {
                return;
            }

            $gates = Gate::query()
                ->where('airport_id', $airport->id)
                ->orderBy('code')
                ->lockForUpdate()
                ->get();

            $occupancyByGateId = $gates
                ->where('is_active', true)
                ->mapWithKeys(fn (Gate $gate): array => [
                    $gate->id => $this->effectiveOccupancyMinutes($gate, $airport),
                ])
                ->filter(fn (int $minutes): bool => $minutes > 0);

            $plannedDeparture = CarbonImmutable::parse($flight->estimated_departure_at)->utc();
            $gateIds = $gates->modelKeys();

            $schedules = collect();
            $exceptions = collect();

            if ($occupancyByGateId->isNotEmpty()) {
                $earliestDesiredStart = $plannedDeparture->subMinutes($occupancyByGateId->max());

                $schedules = GateSchedule::query()
                    ->whereIn('gate_id', $gateIds)
                    ->where('occupied_until', '>', $earliestDesiredStart)
                    ->orderBy('occupied_from')
                    ->get();

                $exceptions = GateException::query()
                    ->whereIn('gate_id', $gateIds)
                    ->where(function (Builder $query) use ($earliestDesiredStart): void {
                        $query->whereNull('end_date')
                            ->orWhereDate('end_date', '>=', $earliestDesiredStart->toDateString());
                    })
                    ->orderBy('start_date')
                    ->get();
            }

            $decision = $planner->plan(
                $plannedDeparture,
                $airport->default_gate_occupancy_minutes,
                $gates->map(fn (Gate $gate): array => [
                    'id' => $gate->id,
                    'code' => $gate->code,
                    'is_active' => $gate->is_active,
                    'occupancy_minutes' => $gate->occupancy_minutes,
                ])->all(),
                $schedules->map(fn (GateSchedule $schedule): array => [
                    'gate_id' => $schedule->gate_id,
                    'occupied_from' => CarbonImmutable::parse($schedule->occupied_from)->utc(),
                    'occupied_until' => CarbonImmutable::parse($schedule->occupied_until)->utc(),
                ])->all(),
                $exceptions->map(fn (GateException $exception): array => [
                    'gate_id' => $exception->gate_id,
                    'start_date' => $exception->start_date
                        ? CarbonImmutable::parse($exception->start_date, 'UTC')
                        : null,
                    'end_date' => $exception->end_date
                        ? CarbonImmutable::parse($exception->end_date, 'UTC')
                        : null,
                ])->all(),
            );

            if (! $decision->isAllocated()) {
                $this->markAsUnallocated($flight, $decision->unallocationReason);

                return;
            }

            GateSchedule::query()->create([
                'gate_id' => $decision->gateId,
                'flight_id' => $flight->id,
                'occupied_from' => $decision->occupiedFrom,
                'occupied_until' => $decision->occupiedUntil,
                'delay_minutes' => $decision->delayMinutes,
            ]);

            $flight->update([
                'allocation_status' => 'allocated',
            ]);
        });
    }

    private function pendingFlightsQuery(): Builder
    {
        return Flight::query()
            ->where('airport_id', $this->airportId)
            ->where('allocation_status', 'pending')
            ->whereDoesntHave('gateSchedule');
    }

    private function lockKey(): string
    {
        return "flight-allocation:{$this->airportId}";
    }

    private function effectiveOccupancyMinutes(Gate $gate, Airport $airport): int
    {
        if ($gate->occupancy_minutes > 0) {
            return $gate->occupancy_minutes;
        }

        return max(0, $airport->default_gate_occupancy_minutes);
    }

    private function markAsUnallocated(Flight $flight, string $reason): void
    {
        $flight->update([
            'allocation_status' => 'unallocated',
        ]);

        GateSchedule::query()->create([
            'flight_id' => $flight->id,
            'unallocation_reason' => $reason,
        ]);

        Log::warning('Flight could not be allocated to a gate.', [
            'flight_id' => $flight->id,
            'airport_id' => $flight->airport_id,
            'reason' => $reason,
        ]);
    }
}
