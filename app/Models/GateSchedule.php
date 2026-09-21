<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GateSchedule extends Model
{
    /** @use HasFactory<\Database\Factories\GateScheduleFactory> */
    use HasFactory;

    protected $fillable = [
        'gate_id',
        'flight_id',
        'occupied_from',
        'occupied_until',
        'delay_minutes',
    ];

    public function gate()
    {
        return $this->belongsTo(Gate::class);
    }

    public function flight()
    {
        return $this->belongsTo(Flight::class);
    }

    protected function casts(): array
    {
        return [
            'occupied_from' => 'datetime',
            'occupied_until' => 'datetime',
            'delay_minutes' => 'integer',
        ];
    }
}
