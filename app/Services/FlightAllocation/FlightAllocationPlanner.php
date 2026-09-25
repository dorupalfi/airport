<?php

namespace App\Services\FlightAllocation;

use Carbon\CarbonImmutable;

final class FlightAllocationPlanner
{
    public const NO_ACTIVE_GATES = 'No active gates';

    public const INVALID_GATE_OCCUPANCY = 'Invalid gate occupancy';

    public const NO_AVAILABLE_GATE = 'No available gate';

    public const NO_AVAILABLE_GATES_IN_CURRENT_DAY = 'No available gates in the current day';

    /**
     * @param  list<array{id: int, code: string, is_active: bool, occupancy_minutes: int}>  $gates
     * @param  list<array{gate_id: int, occupied_from: CarbonImmutable, occupied_until: CarbonImmutable}>  $schedules
     * @param  list<array{gate_id: int, start_date: ?CarbonImmutable, end_date: ?CarbonImmutable}>  $exceptions
     */
    public function plan(
        CarbonImmutable $plannedDeparture,
        int $defaultOccupancyMinutes,
        array $gates,
        array $schedules,
        array $exceptions,
    ): AllocationDecision {
        $activeGates = array_values(array_filter(
            $gates,
            fn (array $gate): bool => $gate['is_active'],
        ));

        if ($activeGates === []) {
            return AllocationDecision::unallocated(self::NO_ACTIVE_GATES);
        }

        $eligibleGates = [];

        foreach ($activeGates as $gate) {
            $occupancyMinutes = $gate['occupancy_minutes'] > 0
                ? $gate['occupancy_minutes']
                : max(0, $defaultOccupancyMinutes);

            if ($occupancyMinutes <= 0) {
                continue;
            }

            $gate['occupancy_minutes'] = $occupancyMinutes;
            $eligibleGates[] = $gate;
        }

        if ($eligibleGates === []) {
            return AllocationDecision::unallocated(self::INVALID_GATE_OCCUPANCY);
        }

        $schedulesByGate = $this->groupByGateId($schedules);
        $exceptionsByGate = $this->groupByGateId($exceptions);
        $bestCandidate = null;
        $hasCandidateOnFutureDay = false;

        foreach ($eligibleGates as $gate) {
            $desiredStart = $plannedDeparture->subMinutes($gate['occupancy_minutes']);
            $slot = $this->findFirstAvailableSlot(
                $desiredStart,
                $gate['occupancy_minutes'],
                $schedulesByGate[$gate['id']] ?? [],
                $exceptionsByGate[$gate['id']] ?? [],
            );

            if ($slot === null) {
                continue;
            }

            if ($slot['occupied_from']->greaterThanOrEqualTo($desiredStart->startOfDay()->addDay())) {
                $hasCandidateOnFutureDay = true;

                continue;
            }

            if (
                $bestCandidate === null
                || $slot['occupied_until']->lt($bestCandidate['occupied_until'])
                || (
                    $slot['occupied_until']->equalTo($bestCandidate['occupied_until'])
                    && strcmp($gate['code'], $bestCandidate['gate']['code']) < 0
                )
            ) {
                $bestCandidate = [
                    'gate' => $gate,
                    'occupied_from' => $slot['occupied_from'],
                    'occupied_until' => $slot['occupied_until'],
                ];
            }
        }

        if ($bestCandidate === null) {
            return AllocationDecision::unallocated(
                $hasCandidateOnFutureDay
                    ? self::NO_AVAILABLE_GATES_IN_CURRENT_DAY
                    : self::NO_AVAILABLE_GATE,
            );
        }

        $delaySeconds = max(
            0,
            $bestCandidate['occupied_until']->getTimestamp() - $plannedDeparture->getTimestamp(),
        );

        return AllocationDecision::allocated(
            $bestCandidate['gate']['id'],
            $bestCandidate['gate']['code'],
            $bestCandidate['occupied_from'],
            $bestCandidate['occupied_until'],
            (int) ceil($delaySeconds / 60),
        );
    }

    /**
     * @param  list<array{gate_id: int, occupied_from: CarbonImmutable, occupied_until: CarbonImmutable}>  $schedules
     * @param  list<array{gate_id: int, start_date: ?CarbonImmutable, end_date: ?CarbonImmutable}>  $exceptions
     * @return array{occupied_from: CarbonImmutable, occupied_until: CarbonImmutable}|null
     */
    private function findFirstAvailableSlot(
        CarbonImmutable $desiredStart,
        int $occupancyMinutes,
        array $schedules,
        array $exceptions,
    ): ?array {
        $blockedIntervals = [];

        foreach ($schedules as $schedule) {
            $blockedIntervals[] = [
                'from' => $schedule['occupied_from'],
                'until' => $schedule['occupied_until'],
            ];
        }

        foreach ($exceptions as $exception) {
            $blockedIntervals[] = [
                'from' => $exception['start_date']?->startOfDay(),
                'until' => $exception['end_date']?->startOfDay()->addDay(),
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

            if ($blockingInterval === null) {
                return [
                    'occupied_from' => $candidateFrom,
                    'occupied_until' => $candidateUntil,
                ];
            }

            if ($blockingInterval['until'] === null) {
                return null;
            }

            $candidateFrom = $blockingInterval['until'];
        }
    }

    /**
     * @param  list<array{gate_id: int, occupied_from?: CarbonImmutable, occupied_until?: CarbonImmutable, start_date?: ?CarbonImmutable, end_date?: ?CarbonImmutable}>  $items
     * @return array<int, list<array{gate_id: int, occupied_from?: CarbonImmutable, occupied_until?: CarbonImmutable, start_date?: ?CarbonImmutable, end_date?: ?CarbonImmutable}>>
     */
    private function groupByGateId(array $items): array
    {
        $groupedItems = [];

        foreach ($items as $item) {
            $groupedItems[$item['gate_id']][] = $item;
        }

        return $groupedItems;
    }
}
