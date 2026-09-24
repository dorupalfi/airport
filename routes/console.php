<?php

use App\Jobs\ImportAirportFlightsJob;
use App\Models\Airport;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    $windowStart = now('UTC')
        ->subHours(config('services.opensky.operational_delay_hours'))
        ->startOfHour();

    // Airports currently have no active/inactive field, so all managed airports are imported.
    Airport::query()->orderBy('id')->each(
        fn (Airport $airport) => ImportAirportFlightsJob::dispatch($airport->id, $windowStart->toIso8601String()),
    );
})
    ->name('opensky-import-departures')
    ->hourlyAt(5)
    ->timezone('UTC')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('analytics:snapshot')
    ->everyThirtyMinutes()
    ->timezone('UTC')
    ->withoutOverlapping()
    ->onOneServer();
