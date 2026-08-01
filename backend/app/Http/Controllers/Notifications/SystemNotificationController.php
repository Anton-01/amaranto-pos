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
}
