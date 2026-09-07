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
        /*
         * Recency first, because that is how a trail is read. The direction is
         * the only sort the viewer offers: every other column is a snapshot
         * whose ordering says nothing about the sequence of events.
         */
        $direction = $request->string('sort_order')->toString() === 'asc' ? 'asc' : 'desc';

        $query = MediaAuditLog::query()
            ->with(['user:id,name', 'mediaFile:id,name,extension,category'])
            ->orderBy('created_at', $direction);

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
         * Free-text needle across the SNAPSHOT columns plus the address.
         *
         * It deliberately does not join `media_files` or `users`: the trail is
         * read to answer "who touched that file", and the only spelling of the
         * file and of the operator that is guaranteed to survive a rename or a
         * user deletion is the one frozen on the row itself. Searching the
         * relation would silently stop matching the entries an investigation
         * cares most about.
         */
        if ($request->filled('search')) {
            $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($request->string('search')->toString())).'%';

            $query->where(function ($q) use ($needle) {
                $q->where('resource_name', 'ilike', $needle)
                    ->orWhere('user_name', 'ilike', $needle)
                    ->orWhere('user_email', 'ilike', $needle)
                    ->orWhere('ip_address', 'ilike', $needle)
                    ->orWhere('drive_file_id', 'ilike', $needle);
            });
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

    /** Catalogs feeding the viewer's filter block: actions and actors. */
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
                'operators' => $this->operators(),
                'default_window_days' => (int) config('media.audit.default_window_days', 30),
            ],
        ]);
    }

    /**
     * Actors that actually appear in the trail, read from the snapshot columns.
     *
     * Not `users`: listing the whole roster would offer filters that can only
     * ever return zero rows, and — the point of snapshotting — an operator
     * deleted six months ago must remain selectable, because those are exactly
     * the entries an investigation goes looking for.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function operators(): array
    {
        return MediaAuditLog::query()
            ->select('user_id', 'user_name')
            ->whereNotNull('user_id')
            ->distinct()
            ->orderBy('user_name')
            ->get()
            ->map(fn (MediaAuditLog $log) => [
                'value' => (string) $log->user_id,
                'label' => $log->user_name ?? 'Operador sin nombre',
            ])
            ->values()
            ->all();
    }
}
