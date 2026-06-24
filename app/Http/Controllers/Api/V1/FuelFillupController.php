<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FuelFillup;
use App\Models\Station;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FuelFillupController extends Controller
{
   public function store(Request $request): JsonResponse
{
    $validated = $request->validate([
        'station_id' => ['required', 'integer', 'exists:stations,id'],
        'fuel_type' => ['required', 'in:regular,premium,diesel'],
        'filled_at' => ['required', 'date', 'before_or_equal:now'],
    ]);

    $filledAt = Carbon::parse($validated['filled_at']);

    if ($filledAt->lt(now()->subDays(90))) {
        return response()->json([
            'message' => 'Solo puedes registrar cargas realizadas durante los últimos 90 días.',
        ], 422);
    }

    $duplicateExists = FuelFillup::query()
        ->where('user_id', $request->user()->id)
        ->where('station_id', $validated['station_id'])
        ->where('fuel_type', $validated['fuel_type'])
        ->whereBetween('filled_at', [
            $filledAt->copy()->subHours(6),
            $filledAt->copy()->addHours(6),
        ])
        ->exists();

    if ($duplicateExists) {
        return response()->json([
            'message' => 'Ya existe una carga muy similar registrada para esta estación y combustible.',
            'meta' => [
                'duplicate_window_hours' => 6,
            ],
        ], 422);
    }

    $reminderEligibleAt = $filledAt->copy()->addDays(5);

    $fillup = FuelFillup::create([
        'user_id' => $request->user()->id,
        'station_id' => $validated['station_id'],
        'fuel_type' => $validated['fuel_type'],
        'filled_at' => $filledAt,
        'reminder_eligible_at' => $reminderEligibleAt,
    ]);

    $station = Station::findOrFail($fillup->station_id);

    return response()->json([
        'data' => [
            'id' => $fillup->id,
            'station' => [
                'id' => $station->id,
                'name' => $station->name,
                'permit_number' => $station->permit_number,
            ],
            'fuel_type' => $fillup->fuel_type,
            'filled_at' => $fillup->filled_at->toISOString(),
            'reminder_eligible_at' => $fillup->reminder_eligible_at->toISOString(),
            'performance_score' => $fillup->performance_score,
        ],
        'meta' => [
            'performance_question_available' => now()->greaterThanOrEqualTo(
                $fillup->reminder_eligible_at
            ),
        ],
    ], 201);
}

    public function pendingPerformance(Request $request): JsonResponse
    {
        $fillups = FuelFillup::query()
            ->with('station:id,name,permit_number')
            ->where('user_id', $request->user()->id)
            ->whereNull('performance_score')
            ->whereNull('dismissed_at')
            ->where('reminder_eligible_at', '<=', now())
            ->orderByDesc('filled_at')
            ->get();

        return response()->json([
            'data' => $fillups->map(function (FuelFillup $fillup) {
                return [
                    'id' => $fillup->id,
                    'station' => [
                        'id' => $fillup->station->id,
                        'name' => $fillup->station->name,
                        'permit_number' => $fillup->station->permit_number,
                    ],
                    'fuel_type' => $fillup->fuel_type,
                    'filled_at' => $fillup->filled_at->toISOString(),
                    'reminder_eligible_at' => $fillup->reminder_eligible_at->toISOString(),
                    'question' => '¿Cómo te rindió la gasolina de tu última carga?',
                ];
            }),
            'meta' => [
                'count' => $fillups->count(),
            ],
        ]);
    }

    public function storePerformance(
        Request $request,
        FuelFillup $fillup
    ): JsonResponse {
        if ($fillup->user_id !== $request->user()->id) {
            abort(403, 'No puedes calificar una carga de otro usuario.');
        }

        if ($fillup->dismissed_at !== null) {
            return response()->json([
                'message' => 'Esta carga fue descartada y ya no puede evaluarse.',
            ], 422);
        }

        if ($fillup->performance_score !== null) {
            return response()->json([
                'message' => 'Esta carga ya fue evaluada.',
            ], 422);
        }

        if (now()->lt($fillup->reminder_eligible_at)) {
            return response()->json([
                'message' => 'Aún no es momento de evaluar esta carga.',
                'meta' => [
                    'reminder_eligible_at' => $fillup->reminder_eligible_at->toISOString(),
                ],
            ], 422);
        }
        $todayReportsCount = FuelFillup::query()
            ->where('user_id', $request->user()->id)
            ->whereNotNull('performance_score')
            ->whereDate('performance_reported_at', now()->toDateString())
            ->count();

        if ($todayReportsCount >= 10) {
            return response()->json([
                'message' => 'Alcanzaste el límite diario de 10 evaluaciones de rendimiento.',
                'meta' => [
                    'daily_limit' => 10,
                ],
            ], 429);
        }
        $validated = $request->validate([
            'performance_score' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $fillup->update([
            'performance_score' => $validated['performance_score'],
            'performance_reported_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'id' => $fillup->id,
                'station_id' => $fillup->station_id,
                'fuel_type' => $fillup->fuel_type,
                'performance_score' => $fillup->performance_score,
                'performance_reported_at' => $fillup->performance_reported_at->toISOString(),
            ],
            'message' => 'Gracias. Tu experiencia ayudará a otros conductores.',
        ]);
    }

    public function dismissPerformance(
        Request $request,
        FuelFillup $fillup
    ): JsonResponse {
        if ($fillup->user_id !== $request->user()->id) {
            abort(403, 'No puedes descartar una carga de otro usuario.');
        }

        if ($fillup->performance_score !== null) {
            return response()->json([
                'message' => 'Esta carga ya fue evaluada.',
            ], 422);
        }

        if ($fillup->dismissed_at !== null) {
            return response()->json([
                'message' => 'Esta carga ya había sido descartada.',
            ], 422);
        }

        $fillup->update([
            'dismissed_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'id' => $fillup->id,
                'dismissed_at' => $fillup->dismissed_at->toISOString(),
            ],
            'message' => 'Evaluación descartada. No volveremos a preguntarte por esta carga.',
        ]);
    }
}