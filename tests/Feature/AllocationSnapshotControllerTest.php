<?php

namespace Tests\Feature;

use App\Services\Analytics\AllocationSnapshotCollector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class AllocationSnapshotControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('airports', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
        });
        Schema::create('airport_allocation_snapshots', function ($table): void {
            $table->id();
            $table->foreignId('airport_id');
            $table->date('snapshot_date');
            $table->timestamp('captured_at');
            $table->unsignedInteger('total_gates');
            $table->unsignedInteger('inactive_gates');
            $table->unsignedInteger('exception_blocked_gates');
            $table->unsignedInteger('busy_gates');
            $table->unsignedInteger('free_gates');
            $table->unsignedInteger('on_time_flights');
            $table->unsignedInteger('delayed_flights');
            $table->unsignedInteger('unallocated_flights');
            $table->unsignedInteger('pending_flights');
            $table->unsignedInteger('active_exceptions');
            $table->unsignedInteger('inactive_gate_allocations');
            $table->unsignedInteger('exception_conflict_allocations');
            $table->unsignedInteger('invalid_allocations');
        });
        Schema::create('flights', function ($table): void {
            $table->id();
            $table->foreignId('airport_id');
            $table->timestamp('estimated_departure_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('airport_allocation_snapshots');
        Schema::dropIfExists('flights');
        Schema::dropIfExists('airports');

        parent::tearDown();
    }

    public function test_it_returns_the_selected_airport_snapshots_for_one_day(): void
    {
        DB::table('airports')->insert([
            ['id' => 1, 'name' => 'Frankfurt Airport', 'code' => 'EDDF'],
            ['id' => 2, 'name' => 'Heathrow Airport', 'code' => 'EGLL'],
        ]);
        DB::table('airport_allocation_snapshots')->insert([
            $this->snapshot(1, '2026-09-24 00:00:00', 12, 8),
            $this->snapshot(1, '2026-09-24 00:30:00', 11, 9),
            $this->snapshot(2, '2026-09-24 00:00:00', 15, 5),
        ]);

        $this->getJson('/api/analytics/filters')
            ->assertOk()
            ->assertJsonPath('dates.0', '2026-09-24')
            ->assertJsonCount(2, 'airports');

        $this->getJson('/api/analytics?airport_id=1&date=2026-09-24')
            ->assertOk()
            ->assertJsonCount(2, 'snapshots')
            ->assertJsonPath('snapshots.0.captured_at', '2026-09-24T00:00:00+00:00')
            ->assertJsonPath('snapshots.1.free_gates', 9)
            ->assertJsonPath('latest.captured_at', '2026-09-24T00:30:00+00:00');
    }

    public function test_it_rebuilds_the_selected_airport_day(): void
    {
        DB::table('airports')->insert([
            'id' => 1,
            'name' => 'Frankfurt Airport',
            'code' => 'EDDF',
        ]);

        $collector = Mockery::mock(AllocationSnapshotCollector::class);
        $collector->shouldReceive('rebuildDay')
            ->once()
            ->withArgs(function (CarbonImmutable $snapshotDate, int $airportId): bool {
                return $snapshotDate->toDateString() === '2026-09-24' && $airportId === 1;
            })
            ->andReturn(48);
        $this->app->instance(AllocationSnapshotCollector::class, $collector);

        $this->postJson('/api/analytics/rebuild', [
            'airport_id' => 1,
            'date' => '2026-09-24',
        ])
            ->assertOk()
            ->assertJsonPath('snapshot_count', 48);
    }

    /**
     * @return array<string, int|string>
     */
    private function snapshot(int $airportId, string $capturedAt, int $busyGates, int $freeGates): array
    {
        return [
            'airport_id' => $airportId,
            'snapshot_date' => '2026-09-24',
            'captured_at' => $capturedAt,
            'total_gates' => 20,
            'inactive_gates' => 0,
            'exception_blocked_gates' => 0,
            'busy_gates' => $busyGates,
            'free_gates' => $freeGates,
            'on_time_flights' => 10,
            'delayed_flights' => 2,
            'unallocated_flights' => 1,
            'pending_flights' => 0,
            'active_exceptions' => 0,
            'inactive_gate_allocations' => 0,
            'exception_conflict_allocations' => 0,
            'invalid_allocations' => 0,
        ];
    }
}
