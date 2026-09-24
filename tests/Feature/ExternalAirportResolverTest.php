<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExternalAirportResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.api_ninjas.base_url' => 'https://api-ninjas.test/v1',
            'services.api_ninjas.api_key' => 'test-api-key',
            'services.api_ninjas.airports_max_attempts' => 5,
            'services.api_ninjas.airports_decay_seconds' => 60,
            'services.api_ninjas.airport_not_found_cache_seconds' => 604800,
            'services.api_ninjas.airports_429_fallback_delay_seconds' => 60,
            'services.opensky.token_url' => 'https://opensky.test/token',
            'services.opensky.base_url' => 'https://opensky.test/api',
            'services.opensky.client_id' => 'test-client',
            'services.opensky.client_secret' => 'test-secret',
        ]);
        Cache::flush();
        RateLimiter::clear('api-ninjas:airports');
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

    public function test_local_external_airport_is_reused_without_an_api_request(): void
    {
        $existing = ExternalAirport::factory()->create(['code' => 'EDDF']);
        Http::fake();

        $resolved = app(ExternalAirportResolver::class)->resolve('eddf');

        $this->assertTrue($existing->is($resolved));
        Http::assertNothingSent();
    }

    public function test_missing_airport_persists_an_exact_api_ninjas_match(): void
    {
        $this->fakeApiNinjas([$this->apiAirport('EDDF')]);

        $resolved = app(ExternalAirportResolver::class)->resolve('eddf');

        $this->assertNotNull($resolved);
        $this->assertSame('EDDF', $resolved->code);
        $this->assertDatabaseHas('external_airports', [
            'code' => 'EDDF',
            'name' => 'Frankfurt Airport',
            'country' => 'Germany',
            'city' => 'Frankfurt',
        ]);
    }

    public function test_partial_api_result_is_rejected(): void
    {
        $this->fakeApiNinjas([$this->apiAirport('EGLL')]);

        $resolved = app(ExternalAirportResolver::class)->resolve('EDDF');

        $this->assertNull($resolved);
        $this->assertDatabaseCount('external_airports', 0);
        $this->assertTrue(Cache::has('api-ninjas:airport-not-found:EDDF'));
    }

    public function test_empty_response_is_negative_cached_and_not_requested_again(): void
    {
        $this->fakeApiNinjas([]);
        $resolver = app(ExternalAirportResolver::class);

        $this->assertNull($resolver->resolve('EDDF'));
        $this->assertNull($resolver->resolve('EDDF'));

        $this->assertTrue(Cache::has('api-ninjas:airport-not-found:EDDF'));
        $requests = Http::recorded(
            fn (Request $request) => str_starts_with($request->url(), 'https://api-ninjas.test/v1/airports'),
        );
        $this->assertCount(1, $requests);
    }

    public function test_api_request_includes_api_key_header(): void
    {
        $this->fakeApiNinjas([$this->apiAirport('EDDF')]);

        app(ExternalAirportResolver::class)->resolve('EDDF');

        Http::assertSent(
            fn (Request $request) => str_starts_with($request->url(), 'https://api-ninjas.test/v1/airports')
                && $request->hasHeader('X-Api-Key', 'test-api-key'),
        );
    }

    public function test_import_resolves_a_shared_external_airport_once_and_attaches_it_to_flights(): void
    {
        $airport = Airport::factory()->create(['code' => 'EDDF']);
        $departureAt = CarbonImmutable::create(2026, 9, 22, 13, 10, 0, 'UTC');
        Http::fake([
            'https://opensky.test/token' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
            'https://opensky.test/api/flights/departure*' => Http::response([
                $this->openSkyFlight('abc123', $departureAt->timestamp, 'EGLL'),
                $this->openSkyFlight('abc124', $departureAt->addMinutes(10)->timestamp, 'EGLL'),
            ]),
            'https://api-ninjas.test/v1/airports*' => Http::response([$this->apiAirport('EGLL')]),
        ]);

        $job = new ImportAirportFlightsJob($airport->id, '2026-09-22T13:00:00+00:00');
        $job->handle(app(OpenSkyClient::class), app(ExternalAirportResolver::class));

        $externalAirport = ExternalAirport::query()->where('code', 'EGLL')->sole();
        $this->assertDatabaseCount('flights', 2);
        $this->assertSame(2, Flight::query()->where('arrival_external_airport_id', $externalAirport->id)->count());
        $requests = Http::recorded(
            fn (Request $request) => str_starts_with($request->url(), 'https://api-ninjas.test/v1/airports'),
        );
        $this->assertCount(1, $requests);
    }

    public function test_local_rate_limit_releases_import_job_without_an_api_request(): void
    {
        config(['services.api_ninjas.airports_max_attempts' => 1]);
        RateLimiter::hit('api-ninjas:airports', 60);
        $airport = Airport::factory()->create(['code' => 'EDDF']);
        $this->fakeOpenSkyWithExternalAirport('EGLL');

        $job = (new ImportAirportFlightsJob($airport->id, '2026-09-22T13:00:00+00:00'))
            ->withFakeQueueInteractions();
        $job->handle(app(OpenSkyClient::class), app(ExternalAirportResolver::class));

        $job->assertReleased(60);
        Http::assertNotSent(
            fn (Request $request) => str_starts_with($request->url(), 'https://api-ninjas.test/v1/airports'),
        );
        $this->assertDatabaseCount('flights', 0);
    }

    public function test_remote_rate_limit_releases_import_job_using_retry_after(): void
    {
        $airport = Airport::factory()->create(['code' => 'EDDF']);
        $this->fakeOpenSkyWithExternalAirport('EGLL', Http::response([], 429, ['Retry-After' => '123']));

        $job = (new ImportAirportFlightsJob($airport->id, '2026-09-22T13:00:00+00:00'))
            ->withFakeQueueInteractions();
        $job->handle(app(OpenSkyClient::class), app(ExternalAirportResolver::class));

        $job->assertReleased(123);
        $this->assertDatabaseCount('flights', 0);
    }

    private function fakeApiNinjas(array $response): void
    {
        Http::fake([
            'https://api-ninjas.test/v1/airports*' => Http::response($response),
        ]);
    }

    private function fakeOpenSkyWithExternalAirport(string $externalIcao, mixed $apiResponse = null): void
    {
        $apiResponse ??= Http::response([$this->apiAirport($externalIcao)]);
        Http::fake([
            'https://opensky.test/token' => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
            'https://opensky.test/api/flights/departure*' => Http::response([
                $this->openSkyFlight('abc123', CarbonImmutable::create(2026, 9, 22, 13, 10, 0, 'UTC')->timestamp, $externalIcao),
            ]),
            'https://api-ninjas.test/v1/airports*' => $apiResponse,
        ]);
    }

    private function apiAirport(string $icao): array
    {
        return [
            'icao' => $icao,
            'name' => $icao === 'EDDF' ? 'Frankfurt Airport' : 'London Heathrow Airport',
            'country' => $icao === 'EDDF' ? 'Germany' : 'United Kingdom',
            'city' => $icao === 'EDDF' ? 'Frankfurt' : 'London',
        ];
    }

    private function openSkyFlight(string $icao24, int $firstSeen, string $arrivalAirport): array
    {
        return [
            'icao24' => $icao24,
            'callsign' => 'TEST123',
            'firstSeen' => $firstSeen,
            'lastSeen' => $firstSeen + 3600,
            'estDepartureAirport' => null,
            'estArrivalAirport' => $arrivalAirport,
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
            $table->string('icao24');
            $table->string('callsign')->nullable();
            $table->timestamp('estimated_arrival_at')->nullable();
            $table->timestamp('estimated_departure_at')->nullable();
            $table->string('allocation_status')->default('pending');
            $table->timestamps();
        });

        Schema::create('gate_schedules', function ($table): void {
            $table->id();
        });
    }
}
