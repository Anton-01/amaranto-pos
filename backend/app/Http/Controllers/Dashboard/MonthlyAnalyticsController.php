<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\MonthlyAnalyticsService;
use App\Support\Timezone;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/dashboard/monthly-analytics — role:admin,manager
 *
 * Alimenta la Modal de Analitica Financiera del Dashboard en una sola
 * respuesta: totales del mes, comparativa contra el mes anterior,
 * distribucion por metodo de pago, top de productos, horas pico y tendencia
 * diaria.
 *
 * Las agregaciones viven en MonthlyAnalyticsService porque la exportacion
 * .xlsx lee exactamente el mismo payload: el archivo que se descarga no puede
 * discrepar de la pantalla desde la que se pidio.
 */
class MonthlyAnalyticsController extends Controller
{
    public function __invoke(Request $request, MonthlyAnalyticsService $analytics): JsonResponse
    {
        $request->validate([
            'month' => 'nullable|date_format:Y-m',
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $analytics->forMonth(self::resolveMonth($request)),
        ]);
    }

    /**
     * Mes solicitado, anclado al dia 1 de forma explicita.
     *
     * `createFromFormat('Y-m')` hereda el dia actual, y un 31 de enero pediria
     * "2026-02" como 2 de marzo.
     */
    public static function resolveMonth(Request $request): Carbon
    {
        return $request->filled('month')
            ? Carbon::createFromFormat('Y-m-d', $request->string('month')->toString().'-01', Timezone::app())->startOfMonth()
            : Carbon::now()->startOfMonth();
    }
}
