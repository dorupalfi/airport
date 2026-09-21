<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FrankfurtAirportSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $now = now();

            DB::table('airports')->upsert(
                [[
                    'name' => 'Frankfurt Airport',
                    'code' => 'EDDF',
                    'city' => 'Frankfurt',
                    'country' => 'Germany',
                    'default_gate_occupancy_minutes' => 90,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['code'],
                [
                    'name',
                    'city',
                    'country',
                    'default_gate_occupancy_minutes',
                    'updated_at',
                ],
            );

            $airportId = DB::table('airports')
                ->where('code', 'EDDF')
                ->value('id');

            $gates = array_map(
                fn (int $number): array => [
                    'airport_id' => $airportId,
                    'code' => "A{$number}",
                    'occupancy_minutes' => 90,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                range(1, 20),
            );

            DB::table('gates')->upsert(
                $gates,
                ['airport_id', 'code'],
                ['occupancy_minutes', 'is_active', 'updated_at'],
            );
        });
    }
}
