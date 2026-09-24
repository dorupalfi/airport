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

class GateReallocationTest extends TestCase
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

    public function test_restarts_allocation_when_a_gate_status_changes(): void
    {
        [$airport, $gate, $flight] = $this->allocatedSchedule();
        Queue::fake([AllocatePendingFlightsJob::class]);

        $response = $this->putJson("/api/gates/{$gate->id}", $this->gatePayload($gate, false));

        $response->assertOk()->assertJsonPath('reallocation_queued', true);
        $this->assertSame('pending', $flight->fresh()->allocation_status);
        $this->assertDatabaseCount('gate_schedules', 0);
        Queue::assertPushed(
            AllocatePendingFlightsJob::class,
            fn (AllocatePendingFlightsJob $job): bool => $job->airportId === $airport->id,
        );
    }

    public function test_does_not_restart_allocation_for_a_future_exception_that_overlaps_no_schedule(): void
    {
        [, $gate, $flight] = $this->allocatedSchedule();
        Queue::fake([AllocatePendingFlightsJob::class]);

        $response = $this->putJson("/api/gates/{$gate->id}", $this->gatePayload($gate, true, [[
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-02',
            'reason' => 'Maintenance',
        ]]));

        $response->assertOk()->assertJsonPath('reallocation_queued', false);
        $this->assertSame('allocated', $flight->fresh()->allocation_status);
        $this->assertDatabaseCount('gate_schedules', 1);
        Queue::assertNothingPushed();
    }

    public function test_restarts_allocation_for_an_exception_that_overlaps_a_schedule(): void
    {
        [$airport, $gate, $flight] = $this->allocatedSchedule();
        Queue::fake([AllocatePendingFlightsJob::class]);

        $response = $this->putJson("/api/gates/{$gate->id}", $this->gatePayload($gate, true, [[
            'start_date' => '2026-09-20',
            'end_date' => '2026-09-20',
            'reason' => 'Maintenance',
        ]]));

        $response->assertOk()->assertJsonPath('reallocation_queued', true);
        $this->assertSame('pending', $flight->fresh()->allocation_status);
        $this->assertDatabaseCount('gate_schedules', 0);
        Queue::assertPushed(
            AllocatePendingFlightsJob::class,
            fn (AllocatePendingFlightsJob $job): bool => $job->airportId === $airport->id,
        );
    }

    /**
     * @return array{Airport, Gate, Flight}
     */
    private function allocatedSchedule(): array
    {
        $airport = Airport::factory()->create();
        $gate = Gate::factory()->for($airport)->create(['code' => 'A1', 'is_active' => true]);
        $flight = Flight::factory()
            ->for($airport)
            ->create([
                'departure_external_airport_id' => null,
                'arrival_external_airport_id' => null,
                'allocation_status' => 'allocated',
            ]);
        GateSchedule::query()->create([
            'gate_id' => $gate->id,
            'flight_id' => $flight->id,
            'occupied_from' => CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'),
            'occupied_until' => CarbonImmutable::parse('2026-09-20 13:30:00', 'UTC'),
        ]);

        return [$airport, $gate, $flight];
    }

    private function gatePayload(Gate $gate, bool $isActive, array $exceptions = []): array
    {
        return [
            'code' => $gate->code,
            'occupancy_minutes' => $gate->occupancy_minutes,
            'is_active' => $isActive,
            'exceptions' => $exceptions,
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
