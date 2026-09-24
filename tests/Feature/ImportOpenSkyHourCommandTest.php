<?php

namespace Tests\Feature;

use App\Jobs\ImportAirportFlightsJob;
use App\Models\Airport;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImportOpenSkyHourCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('airports', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('city');
            $table->string('country');
            $table->integer('default_gate_occupancy_minutes')->default(90);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('airports');

        parent::tearDown();
    }

    public function test_valid_hour_dispatches_one_import_for_each_managed_airport(): void
    {
        $firstAirport = Airport::factory()->create(['code' => 'EDDF']);
        $secondAirport = Airport::factory()->create(['code' => 'LRCL']);
        Queue::fake();

        $this->artisan('opensky:import-hour', ['start' => '2026-09-20 14:00:00'])
            ->expectsOutputToContain('Queued OpenSky imports for 2026-09-20 14:00:00 UTC.')
            ->assertExitCode(0);

        Queue::assertPushed(ImportAirportFlightsJob::class, 2);
        Queue::assertPushed(
            ImportAirportFlightsJob::class,
            fn (ImportAirportFlightsJob $job) => $job->airportId === $firstAirport->id
                && $job->windowStart === '2026-09-20T14:00:00+00:00',
        );
        Queue::assertPushed(
            ImportAirportFlightsJob::class,
            fn (ImportAirportFlightsJob $job) => $job->airportId === $secondAirport->id
                && $job->windowStart === '2026-09-20T14:00:00+00:00',
        );
    }

    public function test_airport_option_dispatches_only_the_requested_airport_case_insensitively(): void
    {
        $selectedAirport = Airport::factory()->create(['code' => 'LRCL']);
        Airport::factory()->create(['code' => 'EDDF']);
        Queue::fake();

        $this->artisan('opensky:import-hour', [
            'start' => '2026-09-20 14:00:00',
            '--airport' => 'lrcl',
        ])->assertExitCode(0);

        Queue::assertPushed(ImportAirportFlightsJob::class, 1);
        Queue::assertPushed(
            ImportAirportFlightsJob::class,
            fn (ImportAirportFlightsJob $job) => $job->airportId === $selectedAirport->id
                && $job->windowStart === '2026-09-20T14:00:00+00:00',
        );
    }

    public function test_unknown_airport_returns_failure_without_dispatching_jobs(): void
    {
        Queue::fake();

        $this->artisan('opensky:import-hour', [
            'start' => '2026-09-20 14:00:00',
            '--airport' => 'LRCL',
        ])->expectsOutputToContain('Managed airport [LRCL] was not found.')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    public function test_invalid_date_format_returns_failure_without_dispatching_jobs(): void
    {
        Queue::fake();

        $this->artisan('opensky:import-hour', ['start' => '20-09-2026 14:00:00'])
            ->expectsOutputToContain('The start argument must be a valid UTC date in Y-m-d H:i:s format.')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    public function test_non_hour_timestamp_returns_failure_without_dispatching_jobs(): void
    {
        Queue::fake();

        $this->artisan('opensky:import-hour', ['start' => '2026-09-20 14:05:00'])
            ->expectsOutputToContain('The start argument must be the exact beginning of an hour (minutes and seconds must be 00).')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }
}
