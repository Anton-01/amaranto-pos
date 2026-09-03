<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Models\SystemNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemNotificationController extends Controller
{
    /**
     * GET /api/notifications
     *
     * Campana del header: todas las no leidas mas las leidas recientes,
     * limitadas para que la carga sea constante sin importar el historico.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $unread = SystemNotification::where('user_id', $userId)
            ->whereNull('read_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $recentRead = SystemNotification::where('user_id', $userId)
            ->whereNotNull('read_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'unread' => $unread,
                'recent' => $recentRead,
                'unread_count' => SystemNotification::where('user_id', $userId)
                    ->whereNull('read_at')
                    ->count(),
            ],
        ]);
    }

    /**
     * POST /api/notifications/{notification}/read
     *
     * Unica mutacion permitida sobre una notificacion: el acuse de lectura.
     */
    public function markRead(Request $request, SystemNotification $notification): JsonResponse
    {
        // Propiedad estricta: nadie marca como leidas notificaciones ajenas.
        if ($notification->user_id !== $request->user()->id) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_NOTIFICATION_FORBIDDEN',
                'message' => 'Esta notificacion no pertenece a tu cuenta.',
            ], 403);
        }

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json([
            'status' => 'success',
            'data' => $notification->fresh(),
        ]);
    }

    /**
     * POST /api/notifications/read-all
     *
     * Acuse masivo: marca como leidas TODAS las pendientes del usuario.
     *
     * WHY A SINGLE STATEMENT AND NOT A LOOP OF SAVES. A tray that has gone
     * unattended for a week holds hundreds of rows; hydrating each one to write
     * a single timestamp would be hundreds of round trips for one click. This
     * is one UPDATE with a WHERE, which is also what makes it atomic: a
     * notification arriving mid-operation is either inside the window or
     * outside it, never half-marked.
     *
     * WHY BYPASSING THE MODEL GUARD IS SAFE HERE. SystemNotification blocks
     * every mutation except `read_at`, and that guard lives in an Eloquent
     * event a query-builder update does not fire. The guard exists to protect
     * the immutable payload of a notification — `type`, `data`, `created_at` —
     * and this statement touches none of them. It writes the one column the
     * guard itself permits, so the invariant is upheld by construction rather
     * than by the event.
     *
     * WHY THE SCOPE IS THE CALLER AND NOTHING ELSE. `user_id` is taken from the
     * authenticated session and never from the request body. There is no
     * parameter on this endpoint that could widen it: the worst a caller can do
     * is clear their own tray.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $marked = SystemNotification::where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'marked' => $marked,
                // Always zero after the update, but returned as a fact rather
                // than an assumption: the bell sets its badge from this value,
                // so a future change to the scope cannot silently leave the
                // counter lying.
                'unread_count' => SystemNotification::where('user_id', $userId)
                    ->whereNull('read_at')
                    ->count(),
            ],
            'metadata' => [
                'message' => $marked === 0
                    ? 'No habia notificaciones pendientes.'
                    : $marked.' notificacion(es) marcada(s) como leida(s).',
            ],
        ]);
    }
}
