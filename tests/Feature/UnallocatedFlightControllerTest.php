<?php

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\ExternalAirport;
use App\Models\Flight;
use App\Models\GateSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UnallocatedFlightControllerTest extends TestCase
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
        Schema::dropIfExists('external_airports');
        Schema::dropIfExists('airports');

        parent::tearDown();
    }

    public function test_returns_only_unallocated_flights_for_the_selected_airport_and_date(): void
    {
        $airport = Airport::factory()->create(['code' => 'EDDF']);
        $otherAirport = Airport::factory()->create(['code' => 'EGLL']);
        $destination = ExternalAirport::factory()->create([
            'code' => 'LRCL',
            'name' => 'Cluj International Airport',
            'city' => 'Cluj-Napoca',
            'country' => 'RO',
        ]);
        $unallocatedFlight = $this->flightFor($airport, $destination, 'UNALLOC1', 'unallocated');
        $allocatedFlight = $this->flightFor($airport, $destination, 'ALLOCATE1', 'allocated');
        $otherAirportFlight = $this->flightFor($otherAirport, $destination, 'OTHER1', 'unallocated');
        GateSchedule::query()->create([
            'flight_id' => $unallocatedFlight->id,
            'unallocation_reason' => 'No available gates in the current day',
        ]);
        GateSchedule::query()->create(['flight_id' => $allocatedFlight->id]);
        GateSchedule::query()->create([
            'flight_id' => $otherAirportFlight->id,
            'unallocation_reason' => 'No active gates',
        ]);

        $response = $this->getJson("/api/unallocated-flights?airport_id={$airport->id}&date=2026-09-20");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.callsign', 'UNALLOC1')
            ->assertJsonPath('data.0.destination.code', 'LRCL')
            ->assertJsonPath('data.0.unallocation_reason', 'No available gates in the current day');
    }

    public function test_returns_only_dates_with_unallocated_flights_for_filters(): void
    {
        $airport = Airport::factory()->create();
        $destination = ExternalAirport::factory()->create();
        $unallocatedFlight = $this->flightFor($airport, $destination, 'UNALLOC1', 'unallocated');
        $allocatedFlight = $this->flightFor($airport, $destination, 'ALLOCATE1', 'allocated');
        GateSchedule::query()->create([
            'flight_id' => $unallocatedFlight->id,
            'unallocation_reason' => 'No available gates in the current day',
        ]);
        GateSchedule::query()->create(['flight_id' => $allocatedFlight->id]);

        $response = $this->getJson('/api/unallocated-flights/filters');

        $response
            ->assertOk()
            ->assertJsonPath('dates', ['2026-09-20']);
    }

    public function test_paginates_unallocated_flights(): void
    {
        $airport = Airport::factory()->create();
        $destination = ExternalAirport::factory()->create();

        foreach (range(1, 16) as $number) {
            $flight = $this->flightFor($airport, $destination, "FLIGHT{$number}", 'unallocated');
            GateSchedule::query()->create([
                'flight_id' => $flight->id,
                'unallocation_reason' => 'No available gates in the current day',
            ]);
        }

        $firstPage = $this->getJson("/api/unallocated-flights?airport_id={$airport->id}&date=2026-09-20");
        $secondPage = $this->getJson("/api/unallocated-flights?airport_id={$airport->id}&date=2026-09-20&page=2");

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

    private function flightFor(Airport $airport, ExternalAirport $destination, string $callsign, string $allocationStatus): Flight
    {
        return Flight::factory()
            ->for($airport)
            ->create([
                'callsign' => $callsign,
                'icao24' => strtolower($callsign),
                'departure_external_airport_id' => null,
                'arrival_external_airport_id' => $destination->id,
                'arrival_external_airport_code' => $destination->code,
                'estimated_departure_at' => CarbonImmutable::parse('2026-09-20 14:00:00', 'UTC'),
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

        Schema::create('external_airports', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('city');
            $table->string('country');
            $table->timestamps();
        });

        Schema::create('flights', function ($table): void {
            $table->id();
            $table->foreignId('airport_id');
            $table->foreignId('departure_external_airport_id')->nullable();
            $table->foreignId('arrival_external_airport_id')->nullable();
            $table->string('arrival_external_airport_code')->nullable();
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
