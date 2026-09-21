<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('airports', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('city', 100);
            $table->string('country', 100);
            $table->integer('default_gate_occupancy_minutes')->default(90);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('airports');
    }
};
