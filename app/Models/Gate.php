<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Gate extends Model
{
    /** @use HasFactory<\Database\Factories\GateFactory> */
    use HasFactory;

    protected $fillable = [
        'airport_id',
        'code',
        'occupancy_minutes',
        'is_active',
    ];

    public function airport()
    {
        return $this->belongsTo(Airport::class);
    }

    public function exceptions()
    {
        return $this->hasMany(GateException::class);
    }

    public function gateSchedules()
    {
        return $this->hasMany(GateSchedule::class);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'occupancy_minutes' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }
}
