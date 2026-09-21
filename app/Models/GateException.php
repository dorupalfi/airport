<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GateException extends Model
{
    /** @use HasFactory<\Database\Factories\GateExceptionFactory> */
    use HasFactory;

    protected $fillable = [
        'gate_id',
        'start_date',
        'end_date',
        'reason',
    ];

    public function gate()
    {
        return $this->belongsTo(Gate::class);
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }
}
