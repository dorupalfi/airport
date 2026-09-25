<?php

namespace Tests\Feature;

use App\Services\Analytics\AllocationSnapshotCollector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AllocationSnapshotCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('airport_allocation_snapshots');
        Schema::dropIfExists('gate_schedules');
        Schema::dropIfExists('gate_exceptions');
        Schema::dropIfExists('gates');
        Schema::dropIfExists('flights');
        Schema::dropIfExists('airports');

        parent::tearDown();
    }

    public function test_it_captures_gate_availability_flight_statuses_and_allocation_validity(): void
    {
        DB::table('airports')->insert([
            'id' => 1,
            'name' => 'Frankfurt Airport',
            'code' => 'EDDF',
            'city' => 'Frankfurt',
            'country' => 'Germany',
            'default_gate_occupancy_minutes' => 90,
        ]);

        DB::table('gates')->insert([
            ['id' => 1, 'airport_id' => 1, 'code' => 'A1', 'is_active' => true],
            ['id' => 2, 'airport_id' => 1, 'code' => 'A2', 'is_active' => true],
            ['id' => 3, 'airport_id' => 1, 'code' => 'A3', 'is_active' => true],
            ['id' => 4, 'airport_id' => 1, 'code' => 'A4', 'is_active' => false],
        ]);
        DB::table('gate_exceptions')->insert([
            ['gate_id' => 2, 'start_date' => '2026-09-24', 'end_date' => '2026-09-24', 'reason' => 'Maintenance'],
        ]);
        DB::table('flights')->insert([
            ['id' => 1, 'airport_id' => 1, 'icao24' => 'one', 'estimated_departure_at' => '2026-09-24 12:00:00', 'allocation_status' => 'allocated'],
            ['id' => 2, 'airport_id' => 1, 'icao24' => 'two', 'estimated_departure_at' => '2026-09-24 12:00:00', 'allocation_status' => 'allocated'],
            ['id' => 3, 'airport_id' => 1, 'icao24' => 'three', 'estimated_departure_at' => '2026-09-24 12:00:00', 'allocation_status' => 'unallocated'],
            ['id' => 4, 'airport_id' => 1, 'icao24' => 'four', 'estimated_departure_at' => '2026-09-24 12:15:00', 'allocation_status' => 'pending'],
            ['id' => 5, 'airport_id' => 1, 'icao24' => 'five', 'estimated_departure_at' => '2026-09-24 16:00:00', 'allocation_status' => 'allocated'],
        ]);
        DB::table('gate_schedules')->insert([
            ['gate_id' => 1, 'flight_id' => 1, 'occupied_from' => '2026-09-24 11:30:00', 'occupied_until' => '2026-09-24 12:30:00', 'delay_minutes' => 0, 'unallocation_reason' => null],
            ['gate_id' => 2, 'flight_id' => 2, 'occupied_from' => '2026-09-24 12:45:00', 'occupied_until' => '2026-09-24 14:15:00', 'delay_minutes' => 15, 'unallocation_reason' => null],
            ['gate_id' => null, 'flight_id' => 3, 'occupied_from' => null, 'occupied_until' => null, 'delay_minutes' => 0, 'unallocation_reason' => 'No available gate'],
            ['gate_id' => 4, 'flight_id' => 5, 'occupied_from' => '2026-09-24 15:00:00', 'occupied_until' => '2026-09-24 16:30:00', 'delay_minutes' => 0, 'unallocation_reason' => null],
        ]);

        $collectedAt = CarbonImmutable::parse('2026-09-25 12:00:00', 'UTC');
        $collector = app(AllocationSnapshotCollector::class);

        $this->assertSame(1, $collector->collect($collectedAt));
        $this->assertSame(1, $collector->collect($collectedAt));

        $this->assertDatabaseCount('airport_allocation_snapshots', 1);
        $this->assertDatabaseHas('airport_allocation_snapshots', [
            'airport_id' => 1,
            'snapshot_date' => '2026-09-24',
            'captured_at' => '2026-09-24 12:00:00',
            'total_gates' => 4,
            'inactive_gates' => 1,
            'exception_blocked_gates' => 1,
            'busy_gates' => 1,
            'free_gates' => 1,
            'on_time_flights' => 1,
            'delayed_flights' => 1,
            'unallocated_flights' => 1,
            'pending_flights' => 0,
            'active_exceptions' => 1,
            'inactive_gate_allocations' => 1,
            'exception_conflict_allocations' => 1,
            'invalid_allocations' => 2,
        ]);

        $this->assertSame(
            22,
            $collector->rebuildDay(
                CarbonImmutable::parse('2026-09-24', 'UTC'),
                1,
                CarbonImmutable::parse('2026-09-25 10:30:00', 'UTC'),
            ),
        );
        $this->assertDatabaseCount('airport_allocation_snapshots', 22);

        $this->assertSame(
            48,
            $collector->rebuildDay(
                CarbonImmutable::parse('2026-09-24', 'UTC'),
                1,
                CarbonImmutable::parse('2026-09-26 10:30:00', 'UTC'),
            ),
        );
        $this->assertDatabaseCount('airport_allocation_snapshots', 48);
        $this->assertDatabaseHas('airport_allocation_snapshots', [
            'airport_id' => 1,
            'snapshot_date' => '2026-09-24',
            'captured_at' => '2026-09-24 00:00:00',
        ]);
    }

    private function createTestTables(): void
    {
        Schema::create('airports', function ($table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->string('city');
            $table->string('country');
            $table->integer('default_gate_occupancy_minutes');
        });

        Schema::create('gates', function ($table): void {
            $table->id();
            $table->foreignId('airport_id');
            $table->string('code');
            $table->boolean('is_active');
        });

        Schema::create('gate_exceptions', function ($table): void {
            $table->id();
            $table->foreignId('gate_id');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('reason')->nullable();
        });

        Schema::create('flights', function ($table): void {
            $table->id();
            $table->foreignId('airport_id');
            $table->string('icao24');
            $table->timestamp('estimated_departure_at')->nullable();
            $table->string('allocation_status');
        });

        Schema::create('gate_schedules', function ($table): void {
            $table->id();
            $table->foreignId('gate_id')->nullable();
            $table->foreignId('flight_id');
            $table->timestamp('occupied_from')->nullable();
            $table->timestamp('occupied_until')->nullable();
            $table->integer('delay_minutes')->default(0);
            $table->string('unallocation_reason')->nullable();
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
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['airport_id', 'captured_at']);
        });
    }
}
