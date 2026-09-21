<?php

namespace Database\Factories;

use App\Models\Airport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Airport>
 */
class AirportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company . ' Airport',
            'code' => strtoupper($this->faker->unique()->lexify('???')),
            'city' => $this->faker->city,
            'country' => $this->faker->country,
            'default_gate_occupancy_minutes' => $this->faker->numberBetween(60, 180),
        ];
    }
}
