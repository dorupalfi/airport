<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExternalAirport extends Model
{
    /** @use HasFactory<\Database\Factories\ExternalAirportFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'city',
        'country',
    ];

    public function departures()
    {
        return $this->hasMany(Flight::class, 'departure_external_airport_id');
    }

    public function arrivals()
    {
        return $this->hasMany(Flight::class, 'arrival_external_airport_id');
    }


}
