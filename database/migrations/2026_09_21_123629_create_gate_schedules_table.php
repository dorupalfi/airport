<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gate_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gate_id')->constrained()->restrictOnDelete();
            $table->foreignId('flight_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('occupied_from');
            $table->timestampTz('occupied_until');
            $table->integer('delay_minutes')->default(0);
            $table->timestampsTz();

            $table->unique('flight_id');
            $table->index(['gate_id', 'occupied_from']);
        });

        DB::statement(
            'ALTER TABLE gate_schedules ADD CONSTRAINT gate_schedules_valid_occupancy '
            .'CHECK (occupied_until > occupied_from)'
        );
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement(
            "ALTER TABLE gate_schedules ADD CONSTRAINT gate_schedules_no_overlapping_occupancy "
            ."EXCLUDE USING gist (gate_id WITH =, tstzrange(occupied_from, occupied_until, '[)') WITH &&)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_schedules');
    }
};
