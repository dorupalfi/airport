<?php

namespace Database\Factories;

use App\Models\Gate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Gate>
 */
class GateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'airport_id' => \App\Models\Airport::factory(),
            'code' => strtoupper($this->faker->unique()->bothify('A-##??')),
            'occupancy_minutes' => $this->faker->numberBetween(60, 180),
            'is_active' => $this->faker->boolean(80), // 80% chance of being active
        ];
    }
}
