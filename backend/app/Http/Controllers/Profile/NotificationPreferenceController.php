<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Models\NotificationType;
use App\Models\UserNotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationPreferenceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userRoles = $user->roles()->pluck('name')->toArray();

        $types = NotificationType::all()->filter(function ($type) use ($userRoles) {
            $allowed = $type->allowed_roles ?? [];
            return count(array_intersect($userRoles, $allowed)) > 0;
        })->values();

        $preferences = UserNotificationPreference::where('user_id', $user->id)
            ->get()
            ->groupBy('notification_type_id')
            ->map(function ($group) {
                $result = [];
                foreach ($group as $pref) {
                    $result[$pref->channel] = $pref->is_enabled;
                }
                return $result;
            });

        $data = $types->map(function ($type) use ($preferences) {
            $prefs = $preferences->get($type->id, []);
            return [
                'id' => $type->id,
                'slug' => $type->slug,
                'name' => $type->name,
                'description' => $type->description,
                'channels' => [
                    'mail' => $prefs['mail'] ?? false,
                    'database' => $prefs['database'] ?? false,
                ],
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'preferences' => 'required|array|min:1',
            'preferences.*.notification_type_id' => 'required|uuid|exists:notification_types,id',
            'preferences.*.channel' => 'required|string|in:mail,database',
            'preferences.*.is_enabled' => 'required|boolean',
        ]);

        $user = $request->user();
        $userRoles = $user->roles()->pluck('name')->toArray();

        $allowedTypeIds = NotificationType::all()
            ->filter(function ($type) use ($userRoles) {
                return count(array_intersect($userRoles, $type->allowed_roles ?? [])) > 0;
            })
            ->pluck('id')
            ->toArray();

        DB::transaction(function () use ($request, $user, $allowedTypeIds) {
            foreach ($request->preferences as $pref) {
                if (!in_array($pref['notification_type_id'], $allowedTypeIds)) {
                    continue;
                }

                DB::table('user_notification_preferences')->upsert(
                    [
                        'user_id' => $user->id,
                        'notification_type_id' => $pref['notification_type_id'],
                        'channel' => $pref['channel'],
                        'is_enabled' => $pref['is_enabled'],
                    ],
                    ['user_id', 'notification_type_id', 'channel'],
                    ['is_enabled']
                );
            }
        });

        return response()->json([
            'status' => 'success',
            'metadata' => ['message' => 'Preferencias actualizadas exitosamente.'],
        ]);
    }
}
