<?php

namespace App\Models;

use Database\Factories\FlightFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Flight extends Model
{
    /** @use HasFactory<FlightFactory> */
    use HasFactory;

    protected $fillable = [
        'airport_id',
        'callsign',
        'icao24',
        'departure_external_airport_id',
        'arrival_external_airport_id',
        'arrival_external_airport_code',
        'estimated_arrival_at',
        'estimated_departure_at',
        'allocation_status',
    ];

    public function airport()
    {
        return $this->belongsTo(Airport::class);
    }

    public function departureExternalAirport()
    {
        return $this->belongsTo(ExternalAirport::class, 'departure_external_airport_id');
    }

    public function arrivalExternalAirport()
    {
        return $this->belongsTo(ExternalAirport::class, 'arrival_external_airport_id');
    }

    public function gateSchedule()
    {
        return $this->hasOne(GateSchedule::class);
    }

    protected function casts(): array
    {
        return [
            'estimated_arrival_at' => 'datetime',
            'estimated_departure_at' => 'datetime',
        ];
    }
}
