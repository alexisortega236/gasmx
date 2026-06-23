<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Relations\HasMany;

use Illuminate\Database\Eloquent\Model;

class Station extends Model
{
    protected $fillable = [
        'place_id',
        'permit_number',
        'name',
        'brand',
        'address',
        'neighborhood',
        'municipality',
        'state',
        'postal_code',
        'latitude',
        'longitude',
        'is_active',
        'last_official_update_at',
        'rating_average',
        'reviews_count',
        'trust_score',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_active' => 'boolean',
            'last_official_update_at' => 'datetime',
            'rating_average' => 'decimal:2',
            'reviews_count' => 'integer',
            'trust_score' => 'integer',
        ];
    }

public function prices(): HasMany
{
    return $this->hasMany(StationPrice::class);
}

}
