<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flights', function (Blueprint $table) {
            $table->string('allocation_status')->default('pending');
            $table->index(
                ['airport_id', 'allocation_status', 'estimated_departure_at'],
                'flights_airport_allocation_departure_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('flights', function (Blueprint $table) {
            $table->dropIndex('flights_airport_allocation_departure_index');
            $table->dropColumn('allocation_status');
        });
    }
};
