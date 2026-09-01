<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Read-only viewer of the media module's forensic trail.
 *
 * There is no store, no update and no destroy, and their absence is the
 * feature: the trail is written exclusively by App\Services\Media\
 * MediaAuditLogger as a side effect of real actions. An endpoint able to add or
 * remove a line would turn evidence into an opinion.
 */
class MediaAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MediaAuditLog::query()
            ->with(['user:id,name', 'mediaFile:id,name,extension,category'])
            ->orderByDesc('created_at');

        if ($request->filled('media_file_id')) {
            $query->where('media_file_id', $request->string('media_file_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->string('action'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->string('user_id'));
        }

        if ($request->boolean('critical_only')) {
            $query->whereIn('action', MediaAuditLog::CRITICAL_ACTIONS);
        }

        /*
         * The viewer opens on a bounded window rather than on the whole table.
         * A trail is append-only and grows forever; an unbounded default would
         * make the first page load slower every month until somebody calls it
         * a bug.
         */
        $from = $request->filled('from')
            ? Carbon::parse($request->string('from')->toString())->startOfDay()
            : Carbon::now()->subDays((int) config('media.audit.default_window_days', 30))->startOfDay();

        $to = $request->filled('to')
            ? Carbon::parse($request->string('to')->toString())->endOfDay()
            : Carbon::now()->endOfDay();

        $query->whereBetween('created_at', [$from, $to]);

        $logs = $query->paginate(
            min((int) $request->integer('per_page', (int) config('media.audit.page_size', 25)), 100)
        );

        return response()->json([
            'status' => 'success',
            'data' => $logs->items(),
            'metadata' => [
                'pagination' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                ],
                'window' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ],
            ],
        ]);
    }

    /** Catalog of auditable actions, for the viewer's filter dropdown. */
    public function catalogs(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'actions' => collect(MediaAuditLog::ACTIONS)
                    ->map(fn (string $label, string $value) => [
                        'value' => $value,
                        'label' => $label,
                        'is_critical' => in_array($value, MediaAuditLog::CRITICAL_ACTIONS, true),
                    ])
                    ->values(),
                'default_window_days' => (int) config('media.audit.default_window_days', 30),
            ],
        ]);
    }
}
