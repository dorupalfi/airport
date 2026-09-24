<?php

namespace Tests\Feature;

use App\Jobs\AllocatePendingFlightsJob;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AirportReallocationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_rebuilds_gates_and_restarts_allocation_when_the_gate_count_changes(): void
    {
        $airport = Airport::factory()->create(['code' => 'EDDF']);
        $gate = Gate::factory()->for($airport)->create(['code' => 'OLD1']);
        $flight = $this->allocatedFlight($airport);
        GateSchedule::query()->create([
            'gate_id' => $gate->id,
            'flight_id' => $flight->id,
            'occupied_from' => CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'),
            'occupied_until' => CarbonImmutable::parse('2026-09-20 13:30:00', 'UTC'),
        ]);
        Queue::fake([AllocatePendingFlightsJob::class]);

        $response = $this->putJson("/api/airports/{$airport->id}", $this->airportPayload($airport, 3));

        $response
            ->assertOk()
            ->assertJsonPath('reallocation_queued', true)
            ->assertJsonCount(3, 'gates');
        $this->assertSame('pending', $flight->fresh()->allocation_status);
        $this->assertDatabaseCount('gate_schedules', 0);
        $this->assertDatabaseHas('gates', ['airport_id' => $airport->id, 'code' => 'A1']);
        $this->assertDatabaseHas('gates', ['airport_id' => $airport->id, 'code' => 'A3']);
        Queue::assertPushed(
            AllocatePendingFlightsJob::class,
            fn (AllocatePendingFlightsJob $job): bool => $job->airportId === $airport->id,
        );
    }

    private function allocatedFlight(Airport $airport): Flight
    {
        return Flight::factory()
            ->for($airport)
            ->create([
                'departure_external_airport_id' => null,
                'arrival_external_airport_id' => null,
                'allocation_status' => 'allocated',
            ]);
    }

    private function airportPayload(Airport $airport, int $gateCount): array
    {
        return [
            'name' => $airport->name,
            'code' => $airport->code,
            'city' => $airport->city,
            'country' => $airport->country,
            'default_gate_occupancy_minutes' => $airport->default_gate_occupancy_minutes,
            'gate_count' => $gateCount,
        ];
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
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('reason')->nullable();
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
