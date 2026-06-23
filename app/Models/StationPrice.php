<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StationPrice extends Model
{
    protected $fillable = [
        'station_id',
        'fuel_type',
        'price',
        'reported_at',
        'imported_at',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'reported_at' => 'datetime',
            'imported_at' => 'datetime',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }
}
