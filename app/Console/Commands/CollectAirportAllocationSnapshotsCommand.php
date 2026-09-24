<?php

namespace App\Console\Commands;

use App\Services\Analytics\AllocationSnapshotCollector;
use Illuminate\Console\Command;

class CollectAirportAllocationSnapshotsCommand extends Command
{
    protected $signature = 'analytics:snapshot';

    protected $description = 'Capture the previous UTC day at the matching time for every airport';

    /**
     * Execute the console command.
     */
    public function handle(AllocationSnapshotCollector $collector): int
    {
        $snapshotCount = $collector->collect();

        $this->info("Captured {$snapshotCount} airport allocation snapshots.");

        return self::SUCCESS;
    }
}
