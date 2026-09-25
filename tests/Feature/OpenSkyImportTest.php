<?php

namespace Tests\Feature;

use App\Jobs\AllocatePendingFlightsJob;
use App\Jobs\ImportAirportFlightsJob;
use App\Models\Airport;
use App\Models\ExternalAirport;
use App\Models\Flight;
use App\Services\Airports\ExternalAirportResolver;
use App\Services\OpenSky\OpenSkyClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class OpenSkyImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.opensky.token_url' => 'https://opensky.test/token',
            'services.opensky.base_url' => 'https://opensky.test/api',
            'services.opensky.client_id' => 'test-client',
            'services.opensky.client_secret' => 'test-secret',
        ]);
        Cache::flush();
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

    public function test_access_token_is_cached_and_reused(): void
    {
        $this->fakeOpenSky([]);
        $client = app(OpenSkyClient::class);
        $start = CarbonImmutable::create(2026, 9, 22, 13, 0, 0, 'UTC');

        $client->getDepartureFlights('EDDF', $start, $start->endOfHour());
        $client->getDepartureFlights('EDDF', $start, $start->endOfHour());

        Http::assertSentCount(3);
        $tokenRequests = Http::recorded(
            fn (Request $request) => $request->url() === 'https://opensky.test/token',
        );

        $this->assertCount(1, $tokenRequests);
    }

    public function test_successful_import_creates_a_pending_flight_and_dispatches_allocation(): void
    {
        Queue::fake();
        $airport = Airport::factory()->create(['code' => 'EDDF']);
        $departureAirport = ExternalAirport::factory()->create(['code' => 'EDDF']);
        $arrivalAirport = ExternalAirport::factory()->create(['code' => 'EGLL']);
        $departureAt = CarbonImmutable::create(2026, 9, 22, 13, 10, 0, 'UTC');
        $arrivalAt = CarbonImmutable::create(2026, 9, 22, 15, 5, 0, 'UTC');

        $this->fakeOpenSky([[
            'icao24' => 'abc123',
            'callsign' => '  TEST123  ',
            'firstSeen' => $departureAt->timestamp,
            'lastSeen' => $arrivalAt->timestamp,
            'estDepartureAirport' => 'EDDF',
            'estArrivalAirport' => 'EGLL',
        ]]);

        $this->importJob($airport)->handle(app(OpenSkyClient::class), app(ExternalAirportResolver::class));

        $flight = Flight::query()->sole();
        $this->assertSame('TEST123', $flight->callsign);
        $this->assertSame($departureAt->timestamp, $flight->estimated_departure_at->utc()->timestamp);
        $this->assertSame($arrivalAt->timestamp, $flight->estimated_arrival_at->utc()->timestamp);
        $this->assertSame('pending', $flight->allocation_status);
        $this->assertSame($departureAirport->id, $flight->departure_external_airport_id);
        $this->assertSame($arrivalAirport->id, $flight->arrival_external_airport_id);
        $this->assertSame('EGLL', $flight->arrival_external_airport_code);
        Queue::assertPushed(AllocatePendingFlightsJob::class, fn (AllocatePendingFlightsJob $job) => $job->airportId === $airport->id);
    }

    public function test_reimport_does_not_duplicate_or_reset_an_allocated_flight(): void
    {
        Queue::fake();
        $airport = Airport::factory()->create(['code' => 'EDDF']);
        $departureAt = CarbonImmutable::create(2026, 9, 22, 13, 10, 0, 'UTC');
        $payload = [[
            'icao24' => 'abc123',
            'callsign' => 'FIRST',
            'firstSeen' => $departureAt->timestamp,
            'lastSeen' => null,
            'estDepartureAirport' => null,
            'estArrivalAirport' => null,
        ]];

        $this->fakeOpenSky($payload);
        $this->importJob($airport)->handle(app(OpenSkyClient::class), app(ExternalAirportResolver::class));
        $flight = Flight::query()->sole();
        $flight->update(['allocation_status' => 'allocated']);

        $this->importJob($airport)->handle(app(OpenSkyClient::class), app(ExternalAirportResolver::class));

        $this->assertDatabaseCount('flights', 1);
        $this->assertSame('allocated', $flight->fresh()->allocation_status);
        Queue::assertPushed(AllocatePendingFlightsJob::class, 1);
    }

    public function test_import_keeps_an_unresolved_arrival_airport_code(): void
    {
        Queue::fake();
        $airport = Airport::factory()->create(['code' => 'EDDF']);
        $departureAt = CarbonImmutable::create(2026, 9, 22, 13, 10, 0, 'UTC');
        $this->fakeOpenSky([[
            'icao24' => 'abc123',
            'callsign' => 'TEST123',
            'firstSeen' => $departureAt->timestamp,
            'lastSeen' => null,
            'estDepartureAirport' => null,
            'estArrivalAirport' => 'zzzz',
        ]]);
        $resolver = Mockery::mock(ExternalAirportResolver::class);
        $resolver->shouldReceive('resolve')->once()->with('ZZZZ')->andReturn(null);

        $this->importJob($airport)->handle(app(OpenSkyClient::class), $resolver);

        $flight = Flight::query()->sole();
        $this->assertNull($flight->arrival_external_airport_id);
        $this->assertSame('ZZZZ', $flight->arrival_external_airport_code);
    }

    public function test_not_found_flight_response_is_an_empty_successful_import(): void
    {
        $airport = Airport::factory()->create(['code' => 'EDDF']);
        Http::fake([
            'https://opensky.test/token' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
            'https://opensky.test/api/flights/departure*' => Http::response([], 404),
        ]);

        $this->importJob($airport)->handle(app(OpenSkyClient::class), app(ExternalAirportResolver::class));

        $this->assertDatabaseCount('flights', 0);
    }

    public function test_allocation_placeholder_does_not_write_allocations(): void
    {
        $airport = Airport::factory()->create();

        (new AllocatePendingFlightsJob($airport->id))->handle();

        $this->assertDatabaseCount('flights', 0);
        $this->assertDatabaseCount('gate_schedules', 0);
    }

    private function importJob(Airport $airport): ImportAirportFlightsJob
    {
        return new ImportAirportFlightsJob(
            $airport->id,
            CarbonImmutable::create(2026, 9, 22, 13, 0, 0, 'UTC')->toIso8601String(),
        );
    }

    private function fakeOpenSky(array $flights): void
    {
        Http::fake([
            'https://opensky.test/token' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
            'https://opensky.test/api/flights/departure*' => Http::response($flights),
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
            $table->foreignId('flight_id');
        });
    }
}
