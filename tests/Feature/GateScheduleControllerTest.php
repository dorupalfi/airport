<?php

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GateScheduleControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('gate_schedules');
        Schema::dropIfExists('gates');
        Schema::dropIfExists('flights');
        Schema::dropIfExists('airports');

        parent::tearDown();
    }

    public function test_paginates_allocated_gate_schedules(): void
    {
        $airport = Airport::factory()->create();
        $gate = Gate::factory()->for($airport)->create([
            'code' => 'A1',
            'is_active' => true,
        ]);

        foreach (range(1, 16) as $number) {
            $flight = Flight::factory()
                ->for($airport)
                ->create([
                    'callsign' => "FLIGHT{$number}",
                    'icao24' => "flight{$number}",
                    'departure_external_airport_id' => null,
                    'arrival_external_airport_id' => null,
                    'allocation_status' => 'allocated',
                ]);
            $occupiedFrom = CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC')->addMinutes($number);
            GateSchedule::query()->create([
                'gate_id' => $gate->id,
                'flight_id' => $flight->id,
                'occupied_from' => $occupiedFrom,
                'occupied_until' => $occupiedFrom->addMinutes(90),
            ]);
        }

        $firstPage = $this->getJson("/api/gate-schedules?airport_id={$airport->id}&date=2026-09-20");
        $secondPage = $this->getJson("/api/gate-schedules?airport_id={$airport->id}&date=2026-09-20&page=2");

        $firstPage
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('total', 16);
        $secondPage
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('current_page', 2);
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
