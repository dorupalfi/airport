<?php

namespace Database\Factories;

use App\Models\ExternalAirport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalAirport>
 */
class ExternalAirportFactory extends Factory
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
        ];
    }
}
