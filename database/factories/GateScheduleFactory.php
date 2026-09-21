<?php

namespace Database\Factories;

use App\Models\GateSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GateSchedule>
 */
class GateScheduleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'occupied_from' => now()->subDay(),
            'occupied_until' => now()->subDay()->addMinutes(90),
            'delay_minutes' => 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (GateSchedule $schedule): void {
            $gate = Gate::factory()->create();

            $flight = Flight::factory()
                ->for($gate->airport, 'airport')
                ->create();

            $schedule->gate()->associate($gate);
            $schedule->flight()->associate($flight);
        });
    }
}
