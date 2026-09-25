<?php

namespace Tests\Feature;

use App\Jobs\AllocatePendingFlightsJob;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateSchedule;
use App\Services\FlightAllocation\FlightAllocationPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AllocatePendingFlightsJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->createTestTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('gate_schedules');
        Schema::dropIfExists('gate_exceptions');
        Schema::dropIfExists('gates');
        Schema::dropIfExists('flights');
        Schema::dropIfExists('airports');

        parent::tearDown();
    }

    public function test_marks_a_flight_unallocated_when_the_next_slot_starts_on_the_next_day(): void
    {
        $airport = Airport::factory()->create(['default_gate_occupancy_minutes' => 90]);
        $gate = Gate::factory()->for($airport)->create([
            'code' => 'A1',
            'is_active' => true,
            'occupancy_minutes' => 90,
        ]);
        $existingFlight = $this->flightFor($airport, 'OCCUPIED', '2026-09-20 22:00:00', 'allocated');
        $pendingFlight = $this->flightFor($airport, 'NEXTDAY', '2026-09-20 23:30:00');

        GateSchedule::query()->create([
            'gate_id' => $gate->id,
            'flight_id' => $existingFlight->id,
            'occupied_from' => CarbonImmutable::parse('2026-09-20 21:30:00', 'UTC'),
            'occupied_until' => CarbonImmutable::parse('2026-09-21 00:15:00', 'UTC'),
            'delay_minutes' => 0,
        ]);

        // Run the job synchronously so its allocation outcome can be asserted directly.
        (new AllocatePendingFlightsJob($airport->id))->handle(app(FlightAllocationPlanner::class));

        $this->assertSame('unallocated', $pendingFlight->fresh()->allocation_status);
        $this->assertDatabaseHas('gate_schedules', [
            'flight_id' => $pendingFlight->id,
            'gate_id' => null,
            'unallocation_reason' => 'No available gates in the current day',
        ]);
    }

    public function test_allocates_a_flight_when_a_gate_is_available_before_midnight(): void
    {
        $airport = Airport::factory()->create(['default_gate_occupancy_minutes' => 90]);
        $gate = Gate::factory()->for($airport)->create([
            'code' => 'A1',
            'is_active' => true,
            'occupancy_minutes' => 90,
        ]);
        $pendingFlight = $this->flightFor($airport, 'SAMEDAY', '2026-09-20 18:00:00');

        // This control case proves that an available slot on the same UTC day is allocated.
        (new AllocatePendingFlightsJob($airport->id))->handle(app(FlightAllocationPlanner::class));

        $this->assertSame('allocated', $pendingFlight->fresh()->allocation_status);
        $this->assertDatabaseHas('gate_schedules', [
            'gate_id' => $gate->id,
            'flight_id' => $pendingFlight->id,
            'unallocation_reason' => null,
        ]);
    }

    private function flightFor(Airport $airport, string $callsign, string $departureAt, string $allocationStatus = 'pending'): Flight
    {
        return Flight::factory()
            ->for($airport)
            ->create([
                'callsign' => $callsign,
                'icao24' => strtolower($callsign),
                'departure_external_airport_id' => null,
                'arrival_external_airport_id' => null,
                'estimated_departure_at' => CarbonImmutable::parse($departureAt, 'UTC'),
                'allocation_status' => $allocationStatus,
            ]);
    }

    private function createTestTables(): void
    {
        Schema::create('airports', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('city');
            $table->string('country');
            $table->integer('default_gate_occupancy_minutes')->default(90);
            $table->timestamps();
        });

        Schema::create('flights', function ($table): void {
            $table->id();
            $table->foreignId('airport_id');
            $table->foreignId('departure_external_airport_id')->nullable();
            $table->foreignId('arrival_external_airport_id')->nullable();
            $table->string('icao24');
            $table->string('callsign')->nullable();
            $table->timestamp('estimated_arrival_at')->nullable();
            $table->timestamp('estimated_departure_at')->nullable();
            $table->string('allocation_status')->default('pending');
            $table->timestamps();
        });

        Schema::create('gates', function ($table): void {
            $table->id();
            $table->foreignId('airport_id');
            $table->string('code');
            $table->integer('occupancy_minutes')->default(90);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('gate_exceptions', function ($table): void {
            $table->id();
            $table->foreignId('gate_id');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('reason');
            $table->timestamps();
        });

        Schema::create('gate_schedules', function ($table): void {
            $table->id();
            $table->foreignId('gate_id')->nullable();
            $table->foreignId('flight_id');
            $table->timestamp('occupied_from')->nullable();
            $table->timestamp('occupied_until')->nullable();
            $table->integer('delay_minutes')->default(0);
            $table->string('unallocation_reason')->nullable();
            $table->timestamps();
        });
    }
}
