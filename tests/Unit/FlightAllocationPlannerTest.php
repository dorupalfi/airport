<?php

namespace Tests\Unit;

use App\Services\FlightAllocation\FlightAllocationPlanner;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class FlightAllocationPlannerTest extends TestCase
{
    /**
     * Chooses the candidate whose occupation finishes first, even when it is not the first gate by code.
     */
    public function test_selects_the_gate_with_the_earliest_available_slot(): void
    {
        $decision = $this->planner()->plan(
            $this->utc('2026-09-20 12:00:00'),
            90,
            [$this->gate(1, 'A1'), $this->gate(2, 'A2')],
            [
                $this->schedule(1, '2026-09-20 10:00:00', '2026-09-20 12:30:00'),
                $this->schedule(2, '2026-09-20 10:00:00', '2026-09-20 12:00:00'),
            ],
            [],
        );

        $this->assertTrue($decision->isAllocated());
        $this->assertSame(2, $decision->gateId);
        $this->assertSame('2026-09-20 12:00:00', $decision->occupiedFrom->toDateTimeString());
    }

    /**
     * Calculates delay from the selected interval's end, which is the actual departure time from the gate.
     */
    public function test_calculates_the_exact_delay_from_the_selected_slot(): void
    {
        $decision = $this->planner()->plan(
            $this->utc('2026-09-20 12:00:00'),
            90,
            [$this->gate(1, 'A1')],
            [$this->schedule(1, '2026-09-20 10:00:00', '2026-09-20 11:00:00')],
            [],
        );

        $this->assertSame('2026-09-20 11:00:00', $decision->occupiedFrom->toDateTimeString());
        $this->assertSame('2026-09-20 12:30:00', $decision->occupiedUntil->toDateTimeString());
        $this->assertSame(30, $decision->delayMinutes);
    }

    /**
     * Uses half-open intervals: overlapping blocks are skipped, but a slot may begin at a prior block's end.
     */
    public function test_skips_overlapping_intervals_but_allows_a_slot_touching_a_previous_end(): void
    {
        // Consecutive schedules jointly block the desired interval until 12:00 UTC.
        $overlappingDecision = $this->planner()->plan(
            $this->utc('2026-09-20 12:00:00'),
            90,
            [$this->gate(1, 'A1')],
            [
                $this->schedule(1, '2026-09-20 10:00:00', '2026-09-20 11:00:00'),
                $this->schedule(1, '2026-09-20 11:00:00', '2026-09-20 12:00:00'),
            ],
            [],
        );

        // The prior occupancy ends at the exact desired start, so the new flight has no delay.
        $touchingDecision = $this->planner()->plan(
            $this->utc('2026-09-20 12:00:00'),
            90,
            [$this->gate(1, 'A1')],
            [$this->schedule(1, '2026-09-20 09:00:00', '2026-09-20 10:30:00')],
            [],
        );

        $this->assertSame('2026-09-20 12:00:00', $overlappingDecision->occupiedFrom->toDateTimeString());
        $this->assertSame('2026-09-20 10:30:00', $touchingDecision->occupiedFrom->toDateTimeString());
        $this->assertSame(0, $touchingDecision->delayMinutes);
    }

    /**
     * Confirms that an exception with an end date does not block the gate after its final UTC day.
     */
    public function test_a_finite_exception_blocks_only_its_calendar_dates(): void
    {
        $decision = $this->planner()->plan(
            $this->utc('2026-09-21 12:00:00'),
            90,
            [$this->gate(1, 'A1')],
            [],
            [$this->exception(1, '2026-09-20', '2026-09-20')],
        );

        $this->assertTrue($decision->isAllocated());
        $this->assertSame('2026-09-21 10:30:00', $decision->occupiedFrom->toDateTimeString());
    }

    /**
     * Confirms that the same finite exception prevents allocation during the blocked UTC day.
     */
    public function test_a_finite_exception_blocks_a_gate_during_its_calendar_dates(): void
    {
        $decision = $this->planner()->plan(
            $this->utc('2026-09-20 12:00:00'),
            90,
            [$this->gate(1, 'A1')],
            [],
            [$this->exception(1, '2026-09-20', '2026-09-20')],
        );

        $this->assertFalse($decision->isAllocated());
        $this->assertSame(FlightAllocationPlanner::NO_AVAILABLE_GATES_IN_CURRENT_DAY, $decision->unallocationReason);
    }

    /**
     * Treats a missing end date as a permanent blockage, so no candidate slot exists for that gate.
     */
    public function test_an_exception_without_an_end_date_blocks_the_gate_indefinitely(): void
    {
        $decision = $this->planner()->plan(
            $this->utc('2026-09-20 12:00:00'),
            90,
            [$this->gate(1, 'A1')],
            [],
            [$this->exception(1, '2026-09-20')],
        );

        $this->assertFalse($decision->isAllocated());
        $this->assertSame(FlightAllocationPlanner::NO_AVAILABLE_GATE, $decision->unallocationReason);
    }

    /**
     * Excludes inactive gates from candidate selection when at least one active gate remains.
     */
    public function test_ignores_inactive_gates_when_an_active_gate_is_available(): void
    {
        $decision = $this->planner()->plan(
            $this->utc('2026-09-20 12:00:00'),
            90,
            [$this->gate(1, 'A1', false), $this->gate(2, 'A2')],
            [],
            [],
        );

        $this->assertTrue($decision->isAllocated());
        $this->assertSame(2, $decision->gateId);
    }

    /**
     * Returns the distinct no-active-gates reason when every gate is disabled.
     */
    public function test_marks_a_flight_unallocated_when_all_gates_are_inactive(): void
    {
        $decision = $this->planner()->plan(
            $this->utc('2026-09-20 12:00:00'),
            90,
            [$this->gate(1, 'A1', false), $this->gate(2, 'A2', false)],
            [],
            [],
        );

        $this->assertFalse($decision->isAllocated());
        $this->assertSame(FlightAllocationPlanner::NO_ACTIVE_GATES, $decision->unallocationReason);
    }

    /**
     * Prevents allocation from carrying over when the only candidate begins after the current UTC day ends.
     */
    public function test_marks_a_flight_unallocated_when_the_first_available_slot_starts_on_the_next_day(): void
    {
        $decision = $this->planner()->plan(
            $this->utc('2026-09-20 23:30:00'),
            90,
            [$this->gate(1, 'A1')],
            [$this->schedule(1, '2026-09-20 22:00:00', '2026-09-21 00:15:00')],
            [],
        );

        $this->assertFalse($decision->isAllocated());
        $this->assertSame(FlightAllocationPlanner::NO_AVAILABLE_GATES_IN_CURRENT_DAY, $decision->unallocationReason);
    }

    /**
     * Ensures midnight is evaluated in UTC and occupancy may correctly begin on the preceding UTC day.
     */
    public function test_handles_a_departure_at_the_utc_day_boundary_without_a_timezone_shift(): void
    {
        $decision = $this->planner()->plan(
            $this->utc('2026-09-21 00:00:00'),
            90,
            [$this->gate(1, 'A1')],
            [],
            [],
        );

        $this->assertTrue($decision->isAllocated());
        $this->assertSame('2026-09-20 22:30:00', $decision->occupiedFrom->toDateTimeString());
        $this->assertSame('2026-09-21 00:00:00', $decision->occupiedUntil->toDateTimeString());
        $this->assertSame(0, $decision->delayMinutes);
    }

    /**
     * Creates the pure system under test without booting Laravel or connecting to a database.
     */
    private function planner(): FlightAllocationPlanner
    {
        return new FlightAllocationPlanner;
    }

    /**
     * Builds a minimal gate input accepted by the planner.
     *
     * @return array{id: int, code: string, is_active: bool, occupancy_minutes: int}
     */
    private function gate(int $id, string $code, bool $isActive = true, int $occupancyMinutes = 90): array
    {
        return [
            'id' => $id,
            'code' => $code,
            'is_active' => $isActive,
            'occupancy_minutes' => $occupancyMinutes,
        ];
    }

    /**
     * Builds an occupied interval for one gate; timestamps are deliberately normalized to UTC.
     *
     * @return array{gate_id: int, occupied_from: CarbonImmutable, occupied_until: CarbonImmutable}
     */
    private function schedule(int $gateId, string $occupiedFrom, string $occupiedUntil): array
    {
        return [
            'gate_id' => $gateId,
            'occupied_from' => $this->utc($occupiedFrom),
            'occupied_until' => $this->utc($occupiedUntil),
        ];
    }

    /**
     * Builds a calendar-date gate exception; a null end date represents an indefinite exception.
     *
     * @return array{gate_id: int, start_date: ?CarbonImmutable, end_date: ?CarbonImmutable}
     */
    private function exception(int $gateId, ?string $startDate, ?string $endDate = null): array
    {
        return [
            'gate_id' => $gateId,
            'start_date' => $startDate ? $this->utc($startDate) : null,
            'end_date' => $endDate ? $this->utc($endDate) : null,
        ];
    }

    /**
     * Parses every fixture timestamp explicitly in UTC to prevent the machine timezone affecting a test.
     */
    private function utc(string $dateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($dateTime, 'UTC');
    }
}
