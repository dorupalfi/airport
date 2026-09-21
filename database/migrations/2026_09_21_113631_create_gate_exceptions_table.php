<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gate_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gate_id')->constrained()->cascadeOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestampsTz();
        });

        DB::statement(
            'ALTER TABLE gate_exceptions ADD CONSTRAINT gate_exceptions_valid_date_range '
            .'CHECK (start_date IS NULL OR end_date IS NULL OR end_date >= start_date)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_exceptions');
    }
};
