<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('airport_allocation_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airport_id')->constrained()->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->timestampTz('captured_at');
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
            $table->timestampsTz();

            $table->unique(['airport_id', 'captured_at']);
            $table->index(['airport_id', 'snapshot_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('airport_allocation_snapshots');
    }
};
