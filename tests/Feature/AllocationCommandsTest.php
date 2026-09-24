<?php

namespace Tests\Feature;

use App\Jobs\AllocatePendingFlightsJob;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\GateSchedule;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AllocationCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('gate_schedules');
        Schema::dropIfExists('flights');
        Schema::dropIfExists('airports');

        parent::tearDown();
    }

    public function test_resets_only_the_requested_airport_flights_and_schedules(): void
    {
        $selectedAirport = Airport::factory()->create(['code' => 'EDDF']);
        $otherAirport = Airport::factory()->create(['code' => 'EGLL']);
        $selectedFlight = $this->flightFor($selectedAirport, 'allocated');
        $otherFlight = $this->flightFor($otherAirport, 'unallocated');
        GateSchedule::query()->create(['flight_id' => $selectedFlight->id]);
        GateSchedule::query()->create(['flight_id' => $otherFlight->id]);

        $this->artisan('flights:reset-allocation', ['airportId' => $selectedAirport->id])
            ->expectsOutput("Reset 1 flights and deleted 1 schedules for airport EDDF (ID {$selectedAirport->id}).")
            ->assertExitCode(0);

        $this->assertSame('pending', $selectedFlight->fresh()->allocation_status);
        $this->assertDatabaseMissing('gate_schedules', ['flight_id' => $selectedFlight->id]);
        $this->assertSame('unallocated', $otherFlight->fresh()->allocation_status);
        $this->assertDatabaseHas('gate_schedules', ['flight_id' => $otherFlight->id]);
    }

    public function test_resets_all_flights_and_schedules_when_no_airport_is_provided(): void
    {
        $firstAirport = Airport::factory()->create();
        $secondAirport = Airport::factory()->create();
        $firstFlight = $this->flightFor($firstAirport, 'allocated');
        $secondFlight = $this->flightFor($secondAirport, 'unallocated');
        GateSchedule::query()->create(['flight_id' => $firstFlight->id]);
        GateSchedule::query()->create(['flight_id' => $secondFlight->id]);

        $this->artisan('flights:reset-allocation')
            ->expectsOutput('Reset 2 flights and deleted 2 schedules for all airports.')
            ->assertExitCode(0);

        $this->assertSame('pending', $firstFlight->fresh()->allocation_status);
        $this->assertSame('pending', $secondFlight->fresh()->allocation_status);
        $this->assertDatabaseCount('gate_schedules', 0);
    }

    public function test_does_not_reset_flights_when_the_requested_airport_does_not_exist(): void
    {
        $airport = Airport::factory()->create();
        $flight = $this->flightFor($airport, 'allocated');
        GateSchedule::query()->create(['flight_id' => $flight->id]);

        $this->artisan('flights:reset-allocation', ['airportId' => 999_999])
            ->expectsOutput('Managed airport [999999] was not found.')
            ->assertExitCode(1);

        $this->assertSame('allocated', $flight->fresh()->allocation_status);
        $this->assertDatabaseHas('gate_schedules', ['flight_id' => $flight->id]);
    }

    public function test_queues_allocation_only_for_the_requested_airport(): void
    {
        $selectedAirport = Airport::factory()->create(['code' => 'EDDF']);
        $otherAirport = Airport::factory()->create(['code' => 'EGLL']);
        Queue::fake([AllocatePendingFlightsJob::class]);

        $this->artisan('flights:allocate-pending', ['airportId' => $selectedAirport->id])
            ->assertExitCode(0);

        Queue::assertPushed(
            AllocatePendingFlightsJob::class,
            fn (AllocatePendingFlightsJob $job): bool => $job->airportId === $selectedAirport->id,
        );
        Queue::assertNotPushed(
            AllocatePendingFlightsJob::class,
            fn (AllocatePendingFlightsJob $job): bool => $job->airportId === $otherAirport->id,
        );
    }

    public function test_queues_allocation_for_each_airport_when_no_airport_is_provided(): void
    {
        $firstAirport = Airport::factory()->create(['code' => 'EDDF']);
        $secondAirport = Airport::factory()->create(['code' => 'EGLL']);
        Queue::fake([AllocatePendingFlightsJob::class]);

        $this->artisan('flights:allocate-pending')
            ->assertExitCode(0);

        Queue::assertPushed(
            AllocatePendingFlightsJob::class,
            fn (AllocatePendingFlightsJob $job): bool => $job->airportId === $firstAirport->id,
        );
        Queue::assertPushed(
            AllocatePendingFlightsJob::class,
            fn (AllocatePendingFlightsJob $job): bool => $job->airportId === $secondAirport->id,
        );
    }

    public function test_does_not_queue_allocation_when_the_requested_airport_does_not_exist(): void
    {
        Queue::fake([AllocatePendingFlightsJob::class]);

        $this->artisan('flights:allocate-pending', ['airportId' => 999_999])
            ->expectsOutput('Managed airport [999999] was not found.')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    private function flightFor(Airport $airport, string $allocationStatus): Flight
    {
        return Flight::factory()
            ->for($airport)
            ->create([
                'departure_external_airport_id' => null,
                'arrival_external_airport_id' => null,
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
