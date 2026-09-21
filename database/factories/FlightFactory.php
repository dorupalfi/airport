<?php

namespace Database\Factories;

use App\Models\Flight;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Flight>
 */
class FlightFactory extends Factory
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
            'callsign' => strtoupper($this->faker->unique()->bothify('??###')),
            'icao24' => strtoupper($this->faker->unique()->bothify('????')),
            'departure_external_airport_id' => \App\Models\ExternalAirport::factory(),
            'arrival_external_airport_id' => \App\Models\ExternalAirport::factory(),
            'estimated_departure_at' => $this->faker->dateTimeBetween('+1 days', '+30 days'),
            'estimated_arrival_at' => $this->faker->dateTimeBetween('+1 days', '+30 days'),
        ];
    }
}
