<?php

namespace Database\Factories;

use App\Models\GateException;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GateException>
 */
class GateExceptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'gate_id' => \App\Models\Gate::factory(),
            'start_date' => $this->faker->dateTimeBetween('-1 month', '+1 month')->format('Y-m-d'),
            'end_date' => $this->faker->dateTimeBetween('+1 month', '+2 months')->format('Y-m-d'),
            'reason' => $this->faker->sentence,
        ];
    }
}
