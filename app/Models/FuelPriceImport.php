<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FuelPriceImport extends Model
{
    protected $fillable = [
        'status',
        'source',
        'import_scope',
        'source_url',
        'file_path',
        'file_name',
        'file_hash',
        'file_size_bytes',
        'stations_processed',
        'stations_created',
        'stations_updated',
        'prices_processed',
        'prices_created',
        'prices_skipped',
        'errors_count',
        'started_at',
        'finished_at',
        'error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'stations_processed' => 'integer',
            'stations_created' => 'integer',
            'stations_updated' => 'integer',
            'prices_processed' => 'integer',
            'prices_created' => 'integer',
            'prices_skipped' => 'integer',
            'errors_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
