<?php

namespace App\Jobs;

use App\Models\Airport;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateException;
use App\Models\GateSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
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

    private const NO_AVAILABLE_GATES_IN_CURRENT_DAY = 'No available gates in the current day';

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $airportId,
    ) {}

    public function handle(): void
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
                $this->processFlight((int) $flightId);
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

    private function processFlight(int $flightId): void
    {
        DB::transaction(function () use ($flightId): void {
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
                ->where('is_active', true)
                ->orderBy('code')
                ->lockForUpdate()
                ->get();

            if ($gates->isEmpty()) {
                $this->markAsUnallocated($flight, 'No active gates');

                return;
            }

            $occupancyByGateId = $gates
                ->mapWithKeys(fn (Gate $gate): array => [
                    $gate->id => $this->effectiveOccupancyMinutes($gate, $airport),
                ])
                ->filter(fn (int $minutes): bool => $minutes > 0);

            $gates = $gates
                ->filter(fn (Gate $gate): bool => $occupancyByGateId->has($gate->id))
                ->values();

            if ($gates->isEmpty()) {
                $this->markAsUnallocated($flight, 'Invalid gate occupancy');

                return;
            }

            $plannedDeparture = CarbonImmutable::parse($flight->estimated_departure_at)->utc();

            $earliestDesiredStart = $plannedDeparture
                ->subMinutes($occupancyByGateId->max());

            $gateIds = $gates->modelKeys();

            $schedulesByGate = GateSchedule::query()
                ->whereIn('gate_id', $gateIds)
                ->where('occupied_until', '>', $earliestDesiredStart)
                ->orderBy('occupied_from')
                ->get()
                ->groupBy('gate_id');

            $exceptionsByGate = GateException::query()
                ->whereIn('gate_id', $gateIds)
                ->where(function (Builder $query) use ($earliestDesiredStart): void {
                    $query->whereNull('end_date')
                        ->orWhereDate('end_date', '>=', $earliestDesiredStart->toDateString());
                })
                ->orderBy('start_date')
                ->get()
                ->groupBy('gate_id');

            $bestCandidate = null;
            $hasCandidateOnFutureDay = false;

            foreach ($gates as $gate) {
                $occupancyMinutes = $occupancyByGateId->get($gate->id);

                $desiredStart = $plannedDeparture->subMinutes($occupancyMinutes);

                $slot = $this->findFirstAvailableSlot(
                    desiredStart: $desiredStart,
                    occupancyMinutes: $occupancyMinutes,
                    schedules: $schedulesByGate->get($gate->id, collect()),
                    exceptions: $exceptionsByGate->get($gate->id, collect()),
                );

                if (! $slot) {
                    continue;
                }

                $nextDayStartsAt = $desiredStart->startOfDay()->addDay();

                if ($slot['occupied_from']->greaterThanOrEqualTo($nextDayStartsAt)) {
                    $hasCandidateOnFutureDay = true;

                    continue;
                }

                if (
                    $bestCandidate === null
                    || $slot['occupied_until']->lt($bestCandidate['occupied_until'])
                    || (
                        $slot['occupied_until']->equalTo($bestCandidate['occupied_until'])
                        && strcmp($gate->code, $bestCandidate['gate']->code) < 0
                    )
                ) {
                    $bestCandidate = [
                        'gate' => $gate,
                        'occupied_from' => $slot['occupied_from'],
                        'occupied_until' => $slot['occupied_until'],
                    ];
                }
            }

            if (! $bestCandidate) {
                $this->markAsUnallocated(
                    $flight,
                    $hasCandidateOnFutureDay
                        ? self::NO_AVAILABLE_GATES_IN_CURRENT_DAY
                        : 'No available gate',
                );

                return;
            }

            $delaySeconds = max(
                0,
                $bestCandidate['occupied_until']->getTimestamp()
                    - $plannedDeparture->getTimestamp(),
            );

            GateSchedule::query()->create([
                'gate_id' => $bestCandidate['gate']->id,
                'flight_id' => $flight->id,
                'occupied_from' => $bestCandidate['occupied_from'],
                'occupied_until' => $bestCandidate['occupied_until'],
                'delay_minutes' => (int) ceil($delaySeconds / 60),
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

    /**
     * @return array{occupied_from: CarbonImmutable, occupied_until: CarbonImmutable}|null
     */
    private function findFirstAvailableSlot(
        CarbonImmutable $desiredStart,
        int $occupancyMinutes,
        Collection $schedules,
        Collection $exceptions,
    ): ?array {
        $blockedIntervals = [];

        foreach ($schedules as $schedule) {
            $blockedIntervals[] = [
                'from' => CarbonImmutable::parse($schedule->occupied_from)->utc(),
                'until' => CarbonImmutable::parse($schedule->occupied_until)->utc(),
            ];
        }

        foreach ($exceptions as $exception) {
            $blockedIntervals[] = [
                'from' => $exception->start_date
                    ? CarbonImmutable::parse($exception->start_date, 'UTC')->startOfDay()
                    : null,
                'until' => $exception->end_date
                    ? CarbonImmutable::parse($exception->end_date, 'UTC')
                        ->startOfDay()
                        ->addDay()
                    : null,
            ];
        }

        usort($blockedIntervals, function (array $left, array $right): int {
            if ($left['from'] === null) {
                return $right['from'] === null ? 0 : -1;
            }

            if ($right['from'] === null) {
                return 1;
            }

            return $left['from']->getTimestamp() <=> $right['from']->getTimestamp();
        });

        $candidateFrom = $desiredStart;

        while (true) {
            $candidateUntil = $candidateFrom->addMinutes($occupancyMinutes);
            $blockingInterval = null;

            foreach ($blockedIntervals as $interval) {
                $startsBeforeCandidateEnds = $interval['from'] === null
                    || $interval['from']->lt($candidateUntil);

                $endsAfterCandidateStarts = $interval['until'] === null
                    || $interval['until']->gt($candidateFrom);

                if ($startsBeforeCandidateEnds && $endsAfterCandidateStarts) {
                    $blockingInterval = $interval;

                    break;
                }
            }

            if (! $blockingInterval) {
                return [
                    'occupied_from' => $candidateFrom,
                    'occupied_until' => $candidateUntil,
                ];
            }

            // An exception without end_date blocks this gate indefinitely.
            if ($blockingInterval['until'] === null) {
                return null;
            }

            $candidateFrom = $blockingInterval['until'];
        }
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
