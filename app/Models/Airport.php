<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Airport extends Model
{
    /** @use HasFactory<\Database\Factories\AirportFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'city',
        'country',
        'default_gate_occupancy_minutes',
    ];

    public function gates()
    {
        return $this->hasMany(Gate::class);
    }

    public function flights()
    {
        return $this->hasMany(Flight::class);
    }
}
