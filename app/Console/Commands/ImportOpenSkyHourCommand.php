<?php

namespace App\Console\Commands;

use App\Jobs\ImportAirportFlightsJob;
use App\Models\Airport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ImportOpenSkyHourCommand extends Command
{
    protected $signature = 'opensky:import-hour
        {start : UTC hour start in "Y-m-d H:i:s" format}
        {--airport= : Optional managed-airport ICAO code}';

    protected $description = 'Queue an OpenSky departure import for one UTC hour.';

    public function handle(): int
    {
        $windowStart = $this->parseWindowStart((string) $this->argument('start'));

        if (! $windowStart) {
            return self::FAILURE;
        }

        $airportCode = trim((string) $this->option('airport'));
        $airports = Airport::query()->orderBy('id');

        if ($airportCode !== '') {
            $airport = $airports
                ->whereRaw('LOWER(code) = ?', [strtolower($airportCode)])
                ->first();

            if (! $airport) {
                $this->error("Managed airport [{$airportCode}] was not found.");

                return self::FAILURE;
            }

            $airports = collect([$airport]);
        } else {
            // Airports currently have no active/inactive field, so all managed airports are selected.
            $airports = $airports->get();
        }

        foreach ($airports as $airport) {
            ImportAirportFlightsJob::dispatch($airport->id, $windowStart->toIso8601String());
        }

        $codes = $airports->pluck('code')->implode(', ');

        $this->info("Queued OpenSky imports for {$windowStart->format('Y-m-d H:i:s')} UTC.");
        $this->line("Airports selected: {$airports->count()} ({$codes})");
        $this->line('Jobs were dispatched to the queue; flights may not have been imported yet.');

        return self::SUCCESS;
    }

    private function parseWindowStart(string $value): ?CarbonImmutable
    {
        try {
            $windowStart = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, 'UTC');
            $errors = CarbonImmutable::getLastErrors();
        } catch (\Throwable) {
            $windowStart = false;
            $errors = ['warning_count' => 1, 'error_count' => 1];
        }

        if (! $windowStart
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $windowStart->format('Y-m-d H:i:s') !== $value) {
            $this->error('The start argument must be a valid UTC date in Y-m-d H:i:s format.');

            return null;
        }

        if ($windowStart->minute !== 0 || $windowStart->second !== 0) {
            $this->error('The start argument must be the exact beginning of an hour (minutes and seconds must be 00).');

            return null;
        }

        return $windowStart;
    }
}
