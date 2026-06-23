<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FuelFillup extends Model
{
    protected $fillable = [
        'user_id',
        'station_id',
        'fuel_type',
        'filled_at',
        'reminder_eligible_at',
        'performance_score',
        'performance_reported_at',
        'reminder_sent_at',
        'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'filled_at' => 'datetime',
            'reminder_eligible_at' => 'datetime',
            'performance_score' => 'integer',
            'performance_reported_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }
}
