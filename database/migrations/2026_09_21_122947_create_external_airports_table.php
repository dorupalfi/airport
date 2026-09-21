<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_airports', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('code', 10)->unique();
            $table->string('city', 100);
            $table->string('country', 100);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_airports');
    }
};
