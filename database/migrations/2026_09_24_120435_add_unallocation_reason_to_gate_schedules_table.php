<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gate_schedules', function (Blueprint $table) {
            $table->string('unallocation_reason')->nullable();
        });

        DB::statement('ALTER TABLE gate_schedules DROP CONSTRAINT gate_schedules_valid_occupancy');
        DB::statement('ALTER TABLE gate_schedules ALTER COLUMN gate_id DROP NOT NULL');
        DB::statement('ALTER TABLE gate_schedules ALTER COLUMN occupied_from DROP NOT NULL');
        DB::statement('ALTER TABLE gate_schedules ALTER COLUMN occupied_until DROP NOT NULL');
        DB::statement(
            'ALTER TABLE gate_schedules ADD CONSTRAINT gate_schedules_allocation_or_unallocated '
            .'CHECK ((unallocation_reason IS NULL AND gate_id IS NOT NULL AND occupied_from IS NOT NULL '
            .'AND occupied_until IS NOT NULL AND occupied_until > occupied_from) '
            .'OR (unallocation_reason IS NOT NULL AND gate_id IS NULL AND occupied_from IS NULL '
            .'AND occupied_until IS NULL))'
        );
    }

    public function down(): void
    {
        DB::table('gate_schedules')->whereNotNull('unallocation_reason')->delete();
        DB::statement('ALTER TABLE gate_schedules DROP CONSTRAINT gate_schedules_allocation_or_unallocated');
        DB::statement('ALTER TABLE gate_schedules ALTER COLUMN gate_id SET NOT NULL');
        DB::statement('ALTER TABLE gate_schedules ALTER COLUMN occupied_from SET NOT NULL');
        DB::statement('ALTER TABLE gate_schedules ALTER COLUMN occupied_until SET NOT NULL');

        Schema::table('gate_schedules', function (Blueprint $table) {
            $table->dropColumn('unallocation_reason');
        });

        DB::statement(
            'ALTER TABLE gate_schedules ADD CONSTRAINT gate_schedules_valid_occupancy '
            .'CHECK (occupied_until > occupied_from)'
        );
    }
};
