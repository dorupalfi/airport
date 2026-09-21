<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('airport_id')->constrained()->cascadeOnDelete();
            $table->foreignId('departure_external_airport_id')
                ->nullable()
                ->constrained('external_airports')
                ->nullOnDelete();
            $table->foreignId('arrival_external_airport_id')
                ->nullable()
                ->constrained('external_airports')
                ->nullOnDelete();
            $table->string('icao24');
            $table->string('callsign')->nullable();
            $table->timestampTz('estimated_arrival_at')->nullable();
            $table->timestampTz('estimated_departure_at')->nullable();
            $table->timestampsTz();

            $table->index(['airport_id', 'estimated_departure_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flights');
    }
};
