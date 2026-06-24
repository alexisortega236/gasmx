<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FuelFillup;
use App\Models\Station;
use App\Models\StationPrice;
use App\Support\StationSearchCache;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StationController extends Controller
{
    public function nearby(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:1', 'max:50'],
            'fuel' => ['nullable', 'in:regular,premium,diesel'],
            'sort' => ['nullable', 'in:distance,price'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'has_fuel' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $latitude = (float) $validated['lat'];
        $longitude = (float) $validated['lng'];
        $radiusInMeters = ((float) ($validated['radius'] ?? 10)) * 1000;

        $selectedFuel = $validated['fuel'] ?? 'regular';
        $sort = $validated['sort'] ?? 'distance';

        $maxPrice = isset($validated['max_price'])
            ? (float) $validated['max_price']
            : null;

        $hasFuel = filter_var(
            $validated['has_fuel'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $limit = (int) ($validated['limit'] ?? 50);

        $cacheKey = StationSearchCache::key('nearby', [
            'lat' => round($latitude, 5),
            'lng' => round($longitude, 5),
            'radius_meters' => $radiusInMeters,
            'fuel' => $selectedFuel,
            'sort' => $sort,
            'max_price' => $maxPrice,
            'has_fuel' => $hasFuel,
            'limit' => $limit,
        ]);

        $payload = Cache::remember(
            $cacheKey,
            now()->addMinutes(5),
            function () use (
                $latitude,
                $longitude,
                $radiusInMeters,
                $selectedFuel,
                $sort,
                $maxPrice,
                $hasFuel,
                $limit
            ) {
                $latestSelectedFuelPrice = StationPrice::query()
                    ->select('price')
                    ->whereColumn(
                        'station_prices.station_id',
                        'stations.id'
                    )
                    ->where('station_prices.fuel_type', $selectedFuel)
                    ->where('station_prices.source', 'cne')
                    ->orderByDesc('station_prices.imported_at')
                    ->limit(1);

                $communitySummary = FuelFillup::communitySummaryQuery();

                $stationsQuery = Station::query()
                    ->leftJoinSub(
                        $communitySummary,
                        'community_summary',
                        function ($join) {
                            $join->on(
                                'community_summary.station_id',
                                '=',
                                'stations.id'
                            );
                        }
                    )
                    ->select('stations.*')
                    ->selectRaw(
                        'ST_Distance(
                            stations.location,
                            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
                        ) AS distance_meters',
                        [$longitude, $latitude]
                    )
                    ->selectSub(
                        $latestSelectedFuelPrice,
                        'selected_fuel_price'
                    )
                    ->selectRaw(
                        'community_summary.reports_count AS community_reports_count'
                    )
                    ->selectRaw(
                        'community_summary.performance_average AS community_performance_average'
                    )
                    ->selectRaw(
                        'community_summary.performance_weighted_average AS community_performance_weighted_average'
                    )
                    ->selectRaw(
                        'community_summary.last_reported_at AS community_last_reported_at'
                    )
                    ->whereNotNull('stations.location')
                    ->where('stations.is_active', true)
                    ->whereRaw(
                        'ST_DWithin(
                            stations.location,
                            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                            ?
                        )',
                        [$longitude, $latitude, $radiusInMeters]
                    );

                if ($maxPrice !== null) {
                    $stationsQuery->whereExists(function ($query) use (
                        $selectedFuel,
                        $maxPrice
                    ) {
                        $query->selectRaw('1')
                            ->from('station_prices as sp')
                            ->whereColumn('sp.station_id', 'stations.id')
                            ->where('sp.fuel_type', $selectedFuel)
                            ->where('sp.source', 'cne')
                            ->where('sp.price', '<=', $maxPrice)
                            ->whereRaw(
                                'sp.imported_at = (
                                    SELECT MAX(sp2.imported_at)
                                    FROM station_prices AS sp2
                                    WHERE sp2.station_id = stations.id
                                      AND sp2.fuel_type = ?
                                      AND sp2.source = ?
                                )',
                                [$selectedFuel, 'cne']
                            );
                    });
                }

                if ($hasFuel) {
                    $stationsQuery->whereExists(function ($query) use (
                        $selectedFuel
                    ) {
                        $query->selectRaw('1')
                            ->from('station_prices as sp')
                            ->whereColumn('sp.station_id', 'stations.id')
                            ->where('sp.fuel_type', $selectedFuel)
                            ->where('sp.source', 'cne');
                    });
                }

                if ($sort === 'price') {
                    $stationsQuery
                        ->orderByRaw('"selected_fuel_price" ASC NULLS LAST')
                        ->orderBy('distance_meters');
                } else {
                    $stationsQuery->orderBy('distance_meters');
                }

                $stations = $stationsQuery
                    ->with([
                        'prices' => function ($query) {
                            $query
                                ->whereIn('fuel_type', [
                                    'regular',
                                    'premium',
                                    'diesel',
                                ])
                                ->orderByDesc('imported_at');
                        },
                    ])
                    ->limit($limit)
                    ->get();

                return [
                    'data' => $stations->map(
                        function (Station $station) use ($selectedFuel) {
                            $latestPrices = $station->prices
                                ->unique('fuel_type')
                                ->keyBy('fuel_type');

                            $reportsCount = (int) (
                                $station->community_reports_count ?? 0
                            );

                            return [
                                'id' => $station->id,
                                'permit_number' => $station->permit_number,
                                'name' => $station->name,
                                'brand' => $station->brand,
                                'address' => $station->address,
                                'municipality' => $station->municipality,
                                'state' => $station->state,
                                'latitude' => (float) $station->latitude,
                                'longitude' => (float) $station->longitude,
                                'distance_meters' => round(
                                    (float) $station->distance_meters
                                ),

                                'selected_fuel' => $selectedFuel,
                                'selected_fuel_price' => $station->selected_fuel_price !== null
                                    ? (float) $station->selected_fuel_price
                                    : null,

                                'prices' => [
                                    'regular' => $this->formatPrice(
                                        $latestPrices->get('regular')
                                    ),
                                    'premium' => $this->formatPrice(
                                        $latestPrices->get('premium')
                                    ),
                                    'diesel' => $this->formatPrice(
                                        $latestPrices->get('diesel')
                                    ),
                                ],

                                'community' => [
                                    'performance_average' => $reportsCount > 0
                                        ? round(
                                            (float) $station->community_performance_average
                                        )
                                        : null,
                                    'performance_weighted_average' => $reportsCount > 0
                                        ? round(
                                            (float) $station->community_performance_weighted_average
                                        )
                                        : null,
                                    'reports_count' => $reportsCount,
                                    'confidence_level' => $this->communityConfidenceLevel(
                                        $reportsCount
                                    ),
                                    'last_reported_at' => $station->community_last_reported_at !== null
                                        ? Carbon::parse(
                                            $station->community_last_reported_at
                                        )->toISOString()
                                        : null,
                                ],

                                'rating_average' => $station->rating_average,
                                'reviews_count' => $station->reviews_count,
                                'trust_score' => $station->trust_score,
                                'last_official_update_at' => $station->last_official_update_at,
                            ];
                        }
                    ),

                    'meta' => [
                        'search' => [
                            'latitude' => $latitude,
                            'longitude' => $longitude,
                            'radius_km' => $radiusInMeters / 1000,
                            'fuel' => $selectedFuel,
                            'sort' => $sort,
                            'max_price' => $maxPrice,
                            'has_fuel' => $hasFuel,
                            'limit' => $limit,
                        ],
                        'count' => $stations->count(),
                    ],
                ];
            }
        );

        return response()->json($payload);
    }

    public function nearbySummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:1', 'max:50'],
        ]);

        $latitude = (float) $validated['lat'];
        $longitude = (float) $validated['lng'];
        $radiusInMeters = ((float) ($validated['radius'] ?? 10)) * 1000;

        $cacheKey = StationSearchCache::key('summary', [
            'lat' => round($latitude, 5),
            'lng' => round($longitude, 5),
            'radius_meters' => $radiusInMeters,
        ]);

        $payload = Cache::remember(
            $cacheKey,
            now()->addMinutes(10),
            function () use ($latitude, $longitude, $radiusInMeters) {
                $summaryRows = StationPrice::query()
                    ->join(
                        'stations',
                        'stations.id',
                        '=',
                        'station_prices.station_id'
                    )
                    ->selectRaw('
                        station_prices.fuel_type,
                        COUNT(*) AS stations_count,
                        MIN(station_prices.price) AS min_price,
                        AVG(station_prices.price) AS avg_price,
                        MAX(station_prices.price) AS max_price,
                        MAX(station_prices.imported_at) AS latest_imported_at
                    ')
                    ->where('station_prices.source', 'cne')
                    ->whereIn('station_prices.fuel_type', [
                        'regular',
                        'premium',
                        'diesel',
                    ])
                    ->whereNotNull('stations.location')
                    ->where('stations.is_active', true)
                    ->whereRaw(
                        'ST_DWithin(
                            stations.location,
                            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                            ?
                        )',
                        [$longitude, $latitude, $radiusInMeters]
                    )
                    ->whereRaw(
                        'station_prices.imported_at = (
                            SELECT MAX(sp2.imported_at)
                            FROM station_prices AS sp2
                            WHERE sp2.station_id = station_prices.station_id
                              AND sp2.fuel_type = station_prices.fuel_type
                              AND sp2.source = station_prices.source
                        )'
                    )
                    ->groupBy('station_prices.fuel_type')
                    ->get()
                    ->keyBy('fuel_type');

                $formatFuelSummary = function (?object $row): ?array {
                    if ($row === null) {
                        return null;
                    }

                    return [
                        'stations_count' => (int) $row->stations_count,
                        'min_price' => (float) $row->min_price,
                        'avg_price' => round((float) $row->avg_price, 2),
                        'max_price' => (float) $row->max_price,
                        'latest_imported_at' => $row->latest_imported_at,
                    ];
                };

                return [
                    'data' => [
                        'regular' => $formatFuelSummary(
                            $summaryRows->get('regular')
                        ),
                        'premium' => $formatFuelSummary(
                            $summaryRows->get('premium')
                        ),
                        'diesel' => $formatFuelSummary(
                            $summaryRows->get('diesel')
                        ),
                    ],
                    'meta' => [
                        'search' => [
                            'latitude' => $latitude,
                            'longitude' => $longitude,
                            'radius_km' => $radiusInMeters / 1000,
                        ],
                    ],
                ];
            }
        );

        return response()->json($payload);
    }

    public function recommendation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:1', 'max:50'],
            'fuel' => ['nullable', 'in:regular,premium,diesel'],
        ]);

        $latitude = (float) $validated['lat'];
        $longitude = (float) $validated['lng'];
        $radiusInMeters = ((float) ($validated['radius'] ?? 10)) * 1000;
        $selectedFuel = $validated['fuel'] ?? 'regular';

        $cacheKey = StationSearchCache::key('recommendation', [
            'lat' => round($latitude, 5),
            'lng' => round($longitude, 5),
            'radius_meters' => $radiusInMeters,
            'fuel' => $selectedFuel,
        ]);

        $payload = Cache::remember(
            $cacheKey,
            now()->addMinutes(5),
            function () use (
                $latitude,
                $longitude,
                $radiusInMeters,
                $selectedFuel
            ) {
                $latestSelectedFuelPrice = StationPrice::query()
                    ->select('price')
                    ->whereColumn(
                        'station_prices.station_id',
                        'stations.id'
                    )
                    ->where('station_prices.fuel_type', $selectedFuel)
                    ->where('station_prices.source', 'cne')
                    ->orderByDesc('station_prices.imported_at')
                    ->limit(1);

                $communitySummary = FuelFillup::communitySummaryQuery();

                $station = Station::query()
                    ->leftJoinSub(
                        $communitySummary,
                        'community_summary',
                        function ($join) {
                            $join->on(
                                'community_summary.station_id',
                                '=',
                                'stations.id'
                            );
                        }
                    )
                    ->select('stations.*')
                    ->selectRaw(
                        'ST_Distance(
                            stations.location,
                            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
                        ) AS distance_meters',
                        [$longitude, $latitude]
                    )
                    ->selectSub(
                        $latestSelectedFuelPrice,
                        'selected_fuel_price'
                    )
                    ->selectRaw(
                        'community_summary.reports_count AS community_reports_count'
                    )
                    ->selectRaw(
                        'community_summary.performance_average AS community_performance_average'
                    )
                    ->selectRaw(
                        'community_summary.performance_weighted_average AS community_performance_weighted_average'
                    )
                    ->selectRaw(
                        'community_summary.last_reported_at AS community_last_reported_at'
                    )
                    ->whereNotNull('stations.location')
                    ->where('stations.is_active', true)
                    ->whereRaw(
                        'ST_DWithin(
                            stations.location,
                            ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                            ?
                        )',
                        [$longitude, $latitude, $radiusInMeters]
                    )
                    ->whereExists(function ($query) use ($selectedFuel) {
                        $query->selectRaw('1')
                            ->from('station_prices as sp')
                            ->whereColumn('sp.station_id', 'stations.id')
                            ->where('sp.fuel_type', $selectedFuel)
                            ->where('sp.source', 'cne');
                    })
                    ->orderByRaw('
                        CASE
                            WHEN COALESCE(community_summary.reports_count, 0) >= 50 THEN 4
                            WHEN COALESCE(community_summary.reports_count, 0) >= 20 THEN 3
                            WHEN COALESCE(community_summary.reports_count, 0) >= 5 THEN 2
                            WHEN COALESCE(community_summary.reports_count, 0) >= 1 THEN 1
                            ELSE 0
                        END DESC
                    ')
                    ->orderByRaw(
                        'community_summary.performance_weighted_average DESC NULLS LAST'
                    )
                    ->orderBy('distance_meters')
                    ->orderByRaw('"selected_fuel_price" ASC NULLS LAST')
                    ->first();

                if ($station === null) {
                    return [
                        'not_found' => true,
                    ];
                }

                $reportsCount = (int) (
                    $station->community_reports_count ?? 0
                );

                $confidenceLevel = $this->communityConfidenceLevel(
                    $reportsCount
                );

                return [
                    'not_found' => false,
                    'data' => [
                        'station' => [
                            'id' => $station->id,
                            'permit_number' => $station->permit_number,
                            'name' => $station->name,
                            'brand' => $station->brand,
                            'latitude' => (float) $station->latitude,
                            'longitude' => (float) $station->longitude,
                            'distance_meters' => round(
                                (float) $station->distance_meters
                            ),
                        ],

                        'selected_fuel' => $selectedFuel,
                        'selected_fuel_price' => $station->selected_fuel_price !== null
                            ? (float) $station->selected_fuel_price
                            : null,

                        'community' => [
                            'performance_average' => $reportsCount > 0
                                ? round(
                                    (float) $station->community_performance_average
                                )
                                : null,
                            'performance_weighted_average' => $reportsCount > 0
                                ? round(
                                    (float) $station->community_performance_weighted_average
                                )
                                : null,
                            'reports_count' => $reportsCount,
                            'confidence_level' => $confidenceLevel,
                            'last_reported_at' => $station->community_last_reported_at !== null
                                ? Carbon::parse(
                                    $station->community_last_reported_at
                                )->toISOString()
                                : null,
                        ],

                        'reason' => $this->recommendationReason(
                            $confidenceLevel,
                            $reportsCount,
                            $station->community_performance_weighted_average,
                            round((float) $station->distance_meters)
                        ),
                    ],

                    'meta' => [
                        'search' => [
                            'latitude' => $latitude,
                            'longitude' => $longitude,
                            'radius_km' => $radiusInMeters / 1000,
                            'fuel' => $selectedFuel,
                        ],
                        'ranking_priority' => [
                            'confidence_level',
                            'performance_weighted_average',
                            'distance',
                            'price',
                        ],
                    ],
                ];
            }
        );

        if (($payload['not_found'] ?? false) === true) {
            return response()->json([
                'message' => 'No encontramos estaciones con ese combustible dentro del radio indicado.',
                'data' => null,
            ], 404);
        }

        unset($payload['not_found']);

        return response()->json($payload);
    }

    public function show(Station $station, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $distanceMeters = null;

        if (
            isset($validated['lat'], $validated['lng']) &&
            $station->location !== null
        ) {
            $result = DB::selectOne(
                'SELECT ST_Distance(
                    location,
                    ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
                ) AS distance_meters
                FROM stations
                WHERE id = ?',
                [
                    (float) $validated['lng'],
                    (float) $validated['lat'],
                    $station->id,
                ]
            );

            $distanceMeters = $result?->distance_meters !== null
                ? round((float) $result->distance_meters)
                : null;
        }

        $latestPrices = $station->prices()
            ->orderByDesc('imported_at')
            ->get()
            ->unique('fuel_type')
            ->keyBy('fuel_type');

        $communitySummary = FuelFillup::communitySummaryQuery()
            ->where('station_id', $station->id)
            ->first();

        $reportsCount = (int) ($communitySummary?->reports_count ?? 0);

        return response()->json([
            'data' => [
                'id' => $station->id,
                'permit_number' => $station->permit_number,
                'name' => $station->name,
                'brand' => $station->brand,
                'address' => $station->address,
                'neighborhood' => $station->neighborhood,
                'municipality' => $station->municipality,
                'state' => $station->state,
                'postal_code' => $station->postal_code,
                'latitude' => (float) $station->latitude,
                'longitude' => (float) $station->longitude,
                'distance_meters' => $distanceMeters,
                'is_active' => $station->is_active,

                'prices' => [
                    'regular' => $this->formatPrice(
                        $latestPrices->get('regular')
                    ),
                    'premium' => $this->formatPrice(
                        $latestPrices->get('premium')
                    ),
                    'diesel' => $this->formatPrice(
                        $latestPrices->get('diesel')
                    ),
                ],

                'community' => [
                    'performance_average' => $reportsCount > 0
                        ? round(
                            (float) $communitySummary->performance_average
                        )
                        : null,
                    'performance_weighted_average' => $reportsCount > 0
                        ? round(
                            (float) $communitySummary->performance_weighted_average
                        )
                        : null,
                    'reports_count' => $reportsCount,
                    'confidence_level' => $this->communityConfidenceLevel(
                        $reportsCount
                    ),
                    'last_reported_at' => $communitySummary?->last_reported_at !== null
                        ? Carbon::parse(
                            $communitySummary->last_reported_at
                        )->toISOString()
                        : null,
                ],

                'rating_average' => $station->rating_average,
                'reviews_count' => $station->reviews_count,
                'trust_score' => $station->trust_score,
                'last_official_update_at' => $station->last_official_update_at,
            ],
        ]);
    }

    private function formatPrice(?StationPrice $price): ?array
    {
        if ($price === null) {
            return null;
        }

        return [
            'price' => (float) $price->price,
            'reported_at' => $price->reported_at?->toISOString(),
            'imported_at' => $price->imported_at->toISOString(),
            'source' => $price->source,
        ];
    }

    private function communityConfidenceLevel(int $reportsCount): string
    {
        return match (true) {
            $reportsCount === 0 => 'sin_datos',
            $reportsCount <= 4 => 'initial',
            $reportsCount <= 19 => 'low',
            $reportsCount <= 49 => 'medium',
            default => 'high',
        };
    }

    private function recommendationReason(
        string $confidenceLevel,
        int $reportsCount,
        mixed $performanceAverage,
        int $distanceMeters
    ): string {
        if ($confidenceLevel === 'sin_datos') {
            return 'Aún no hay experiencias comunitarias cercanas; esta opción se eligió por cercanía y disponibilidad del combustible.';
        }

        $performance = round((float) $performanceAverage);

        return match ($confidenceLevel) {
            'initial' => "Tiene una primera experiencia comunitaria registrada: {$performance}/100. Está a {$distanceMeters} m de ti.",
            'low' => "Tiene percepción inicial de rendimiento de {$performance}/100, basada en {$reportsCount} experiencias.",
            'medium' => "Recomendada por la comunidad: rendimiento percibido de {$performance}/100, basado en {$reportsCount} experiencias.",
            'high' => "Alta confianza comunitaria: rendimiento percibido de {$performance}/100, basado en {$reportsCount} experiencias.",
            default => 'Recomendación basada en experiencias comunitarias recientes.',
        };
    }
}