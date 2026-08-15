<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CacheConfiguration\StoreCacheConfigurationRequest;
use App\Http\Requests\CacheConfiguration\UpdateCacheConfigurationRequest;
use App\Models\CacheConfiguration;
use App\Support\ModuleCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administration of the per-module cache policies.
 *
 * Writing a row here is the only way to change how long a heavy payload may be
 * served from Redis. The model's `saved` hook drops both the cached policy map
 * and the payloads stored under the previous window, so every response of this
 * controller already reflects a coherent cache state.
 */
class CacheConfigurationController extends Controller
{
    /**
     * Configured policies, plus a synthetic row for every module that has no
     * policy yet, so the admin table lists the whole catalog instead of hiding
     * the modules nobody has configured.
     */
    public function index(): JsonResponse
    {
        // Only the columns the table renders: the raw row also carries the
        // foreign key and timestamps the UI never reads.
        $configurations = CacheConfiguration::query()
            ->select(['id', 'module_name', 'duration_minutes', 'is_active', 'updated_by', 'updated_at'])
            ->with('updatedByUser:id,name')
            ->orderBy('module_name')
            ->get();

        $configured = $configurations->pluck('module_name')->all();

        $pending = collect(CacheConfiguration::MODULES)
            ->reject(fn (string $label, string $module) => in_array($module, $configured, true))
            ->map(fn (string $label, string $module) => [
                'id' => null,
                'module_name' => $module,
                // Surfaced as the default the form pre-selects, making it
                // obvious what the module does while unconfigured.
                'duration_minutes' => CacheConfiguration::DEFAULT_DURATION_MINUTES,
                'is_active' => true,
                'is_configured' => false,
                'updated_at' => null,
                'updated_by_user' => null,
            ])
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => $configurations
                ->map(fn (CacheConfiguration $row) => [
                    'id' => $row->id,
                    'module_name' => $row->module_name,
                    'duration_minutes' => $row->duration_minutes,
                    'is_active' => $row->is_active,
                    'is_configured' => true,
                    'updated_at' => $row->updated_at,
                    'updated_by_user' => $row->updatedByUser,
                ])
                ->concat($pending)
                ->values(),
        ]);
    }

    /** Catalogs consumed by the admin form (modules and refresh windows). */
    public function catalogs(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'modules' => collect(CacheConfiguration::MODULES)
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values(),
                'durations' => collect(CacheConfiguration::DURATION_OPTIONS)
                    ->map(fn (string $label, int $value) => ['value' => $value, 'label' => $label])
                    ->values(),
                'default_duration_minutes' => CacheConfiguration::DEFAULT_DURATION_MINUTES,
            ],
        ]);
    }

    public function store(StoreCacheConfigurationRequest $request): JsonResponse
    {
        $configuration = CacheConfiguration::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
            'updated_by' => $request->user()->id,
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $configuration->fresh('updatedByUser'),
            'metadata' => ['message' => 'Politica de cache creada correctamente.'],
        ], 201);
    }

    public function update(
        UpdateCacheConfigurationRequest $request,
        CacheConfiguration $cacheConfiguration
    ): JsonResponse {
        $cacheConfiguration->update([
            ...$request->validated(),
            'updated_by' => $request->user()->id,
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $cacheConfiguration->fresh('updatedByUser'),
            'metadata' => ['message' => 'Politica de cache actualizada correctamente.'],
        ]);
    }

    /** Inline kill switch used by the status toggle of the admin table. */
    public function toggleStatus(Request $request, CacheConfiguration $cacheConfiguration): JsonResponse
    {
        $cacheConfiguration->update([
            'is_active' => ! $cacheConfiguration->is_active,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $cacheConfiguration->fresh('updatedByUser'),
            'metadata' => [
                'message' => $cacheConfiguration->is_active
                    ? 'Cache activado para el modulo.'
                    : 'Cache desactivado: el modulo vuelve a leer en vivo.',
            ],
        ]);
    }

    /**
     * Manual purge without touching the policy.
     *
     * Escape hatch for the operator who knows the underlying data moved
     * through a path that does not invalidate on its own (a direct database
     * fix, a restored backup).
     */
    public function flush(CacheConfiguration $cacheConfiguration): JsonResponse
    {
        ModuleCache::flush($cacheConfiguration->module_name);

        return response()->json([
            'status' => 'success',
            'metadata' => ['message' => 'Cache del modulo purgado. La proxima consulta se reconstruye en vivo.'],
        ]);
    }

    public function destroy(CacheConfiguration $cacheConfiguration): JsonResponse
    {
        // Deleting the policy also purges its payloads (see the model's
        // `deleted` hook), so the module falls back to the default window
        // with no leftovers from the window that just disappeared.
        $cacheConfiguration->delete();

        return response()->json([
            'status' => 'success',
            'metadata' => ['message' => 'Politica de cache eliminada.'],
        ]);
    }
}
