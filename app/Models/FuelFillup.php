<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    public static function communitySummaryQuery(): Builder
    {
        $recencyWeight = "
            CASE
                WHEN performance_reported_at >= NOW() - INTERVAL '30 days' THEN 1.00
                WHEN performance_reported_at >= NOW() - INTERVAL '90 days' THEN 0.75
                WHEN performance_reported_at >= NOW() - INTERVAL '180 days' THEN 0.50
                WHEN performance_reported_at >= NOW() - INTERVAL '365 days' THEN 0.25
                ELSE 0.10
            END
        ";

        return static::query()
            ->whereNotNull('performance_score')
            ->whereNotNull('performance_reported_at')
            ->select('station_id')
            ->selectRaw('COUNT(*) AS reports_count')
            ->selectRaw(
                'ROUND(AVG(performance_score)::numeric, 2) AS performance_average'
            )
            ->selectRaw(
                "ROUND(
                    AVG(performance_score::numeric * ({$recencyWeight}))
                    / NULLIF(AVG(({$recencyWeight})), 0),
                    2
                ) AS performance_weighted_average"
            )
            ->selectRaw('MAX(performance_reported_at) AS last_reported_at')
            ->groupBy('station_id');
    }
}