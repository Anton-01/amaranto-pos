<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Mail\UserPasswordResetMail;
use App\Mail\UserWelcomeMail;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /*
         * The grid paints a name, an email, a role badge, a status and the
         * count of live sessions. Everything else the accounts table holds —
         * the two-factor state, `password_restored_at`, the phone, the avatar
         * and the soft-delete trail — stays where it is. `password` and the
         * two-factor secrets are already stripped by the model's $hidden, but
         * relying on a denylist means the next column added to the table is
         * exposed by default; the explicit select inverts that.
         *
         * `roles:id,name` narrows the pivot join to the single attribute the
         * badge reads, instead of hydrating each role with its permission set.
         */
        $query = User::query()
            ->select(['id', 'name', 'email', 'status', 'created_at'])
            ->with('roles:id,name')
            ->withCount(['sessionsLog as active_tokens_count' => function ($q) {
                $q->whereNull('logout_at');
            }])
            ->orderBy('name');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('role')) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('name', $request->role);
            });
        }

        if ($request->filled('has_active_session')) {
            if ($request->boolean('has_active_session')) {
                $query->whereHas('sessionsLog', function ($q) {
                    $q->whereNull('logout_at');
                });
            } else {
                $query->whereDoesntHave('sessionsLog', function ($q) {
                    $q->whereNull('logout_at');
                });
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $users = $query->get();

        return response()->json([
            'status' => 'success',
            'data' => $users,
        ]);
    }

    public function show(User $user): JsonResponse
    {
        $user->load('roles:id,name');

        $sessions = $user->sessionsLog()
            ->orderByDesc('login_at')
            ->limit(3)
            ->get();

        $cashRegisters = $user->cashRegisters()
            ->orderByDesc('opened_at')
            ->limit(10)
            ->get();

        $tokenCount = DB::table('personal_access_tokens')
            ->where('tokenable_id', $user->id)
            ->where('tokenable_type', User::class)
            ->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => $user,
                'security' => [
                    'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                    'two_factor_confirmed_at' => $user->two_factor_confirmed_at,
                    'password_restored_at' => $user->password_restored_at,
                    'active_tokens' => $tokenCount,
                ],
                'sessions' => $sessions,
                'cash_registers' => $cashRegisters,
            ],
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $temporaryPassword = Str::random(12);

        $user = DB::transaction(function () use ($request, $temporaryPassword) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => $temporaryPassword,
                'status' => 'active',
                'password_restored_at' => now(),
            ]);

            $role = Role::where('name', $request->role)->firstOrFail();
            $user->roles()->attach($role->id);

            return $user;
        });

        $user->load('roles:id,name');

        Mail::to($user->email)->queue(new UserWelcomeMail($user, $temporaryPassword));

        return response()->json([
            'status' => 'success',
            'data' => $user,
            'metadata' => ['message' => 'Usuario creado exitosamente. Se ha enviado un correo de bienvenida con las credenciales de acceso.'],
        ], 201);
    }

    public function toggleStatus(User $user, Request $request): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            abort(422, 'Operación no permitida sobre el usuario autenticado actualmente.');
        }

        $newStatus = $user->status === 'active' ? 'suspended' : 'active';

        DB::statement("UPDATE users SET status = ? WHERE id = ?", [$newStatus, $user->id]);

        if ($newStatus === 'suspended') {
            $user->tokens()->delete();
        }

        $user->refresh()->load('roles');

        return response()->json([
            'status' => 'success',
            'message' => 'Estatus actualizado correctamente.',
            'is_active' => $newStatus === 'active',
            'data' => $user,
            'metadata' => [
                'message' => $newStatus === 'suspended'
                    ? 'Usuario suspendido. Todos sus tokens han sido revocados.'
                    : 'Usuario reactivado exitosamente.',
            ],
        ]);
    }

    public function destroy(User $user, Request $request): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            abort(422, 'Operación no permitida sobre el usuario autenticado actualmente.');
        }

        $request->validate([
            'deletion_reason' => 'required|string|max:255',
        ]);

        $user->tokens()->delete();
        $user->advancedDelete($request->user()->id, $request->deletion_reason);

        return response()->json([
            'status' => 'success',
            'metadata' => ['message' => 'Usuario eliminado logicamente.'],
        ]);
    }

    public function sendPasswordReset(User $user, Request $request): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            abort(422, 'Operación no permitida sobre el usuario autenticado actualmente.');
        }

        $resetUrl = URL::temporarySignedRoute(
            'password.reset',
            now()->addHours(4),
            ['user' => $user->id]
        );

        Mail::to($user->email)->queue(new UserPasswordResetMail($user, $resetUrl));

        return response()->json([
            'status' => 'success',
            'metadata' => [
                'message' => 'Enlace de restauracion enviado a ' . $user->email,
                'expires_at' => now()->addHours(4)->toISOString(),
            ],
        ]);
    }

    public function revokeSession(User $user, string $sessionId): JsonResponse
    {
        $session = $user->sessionsLog()->findOrFail($sessionId);

        $session->update(['logout_at' => now()]);

        $user->tokens()->delete();

        return response()->json([
            'status' => 'success',
            'metadata' => ['message' => 'Sesion cerrada forzosamente y tokens revocados.'],
        ]);
    }

    public function roles(): JsonResponse
    {
        $roles = Role::orderBy('name')->get();

        return response()->json([
            'status' => 'success',
            'data' => $roles,
        ]);
    }
}
