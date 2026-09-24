<?php

namespace App\Console\Commands;

use App\Jobs\AllocatePendingFlightsJob;
use App\Models\Airport;
use Illuminate\Console\Command;

class AllocatePendingFlightsCommand extends Command
{
    protected $signature = 'flights:allocate-pending
        {airportId? : Optional managed airport ID}';

    protected $description = 'Queue allocation for pending flights.';

    public function handle(): int
    {
        $airportId = $this->argument('airportId');
        $airports = Airport::query()->orderBy('id');

        if ($airportId !== null) {
            $airport = $airports->find($airportId);

            if (! $airport) {
                $this->error("Managed airport [{$airportId}] was not found.");

                return self::FAILURE;
            }

            $airports = collect([$airport]);
        } else {
            $airports = $airports->get();
        }

        foreach ($airports as $airport) {
            AllocatePendingFlightsJob::dispatch($airport->id);
        }

        $codes = $airports->pluck('code')->implode(', ');

        $this->info("Queued pending-flight allocation for {$airports->count()} airport(s): {$codes}.");
        $this->line('Jobs were dispatched to the queue and will be processed asynchronously.');

        return self::SUCCESS;
    }
}
