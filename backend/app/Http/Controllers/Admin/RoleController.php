<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::withCount(['users as users_count'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $roles,
        ]);
    }

    public function show(Role $role): JsonResponse
    {
        $role->loadCount('users as users_count');
        $role->load(['users' => function ($q) {
            $q->select('users.id', 'users.name', 'users.email', 'users.status')
              ->limit(20);
        }]);

        return response()->json([
            'status' => 'success',
            'data' => $role,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:100|unique:roles,name',
        ]);

        $role = Role::create([
            'name' => strtolower(trim($request->name)),
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $role,
            'metadata' => ['message' => 'Rol creado exitosamente.'],
        ], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:100|unique:roles,name,' . $role->id,
        ]);

        $role->update([
            'name' => strtolower(trim($request->name)),
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $role,
            'metadata' => ['message' => 'Rol actualizado exitosamente.'],
        ]);
    }

    public function destroy(Role $role): JsonResponse
    {
        $usersCount = DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->count();

        if ($usersCount > 0) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_ROLE_HAS_USERS',
                'message' => "No se puede eliminar el rol porque pertenece a {$usersCount} usuario(s) activo(s); reasigne al personal primero.",
            ], 422);
        }

        $role->delete();

        return response()->json([
            'status' => 'success',
            'metadata' => ['message' => 'Rol eliminado exitosamente.'],
        ]);
    }

    public function permissions(): JsonResponse
    {
        $permissions = self::availablePermissions();

        return response()->json([
            'status' => 'success',
            'data' => $permissions,
        ]);
    }

    public static function availablePermissions(): array
    {
        return [
            ['key' => 'pos.operate', 'label' => 'Operar Punto de Venta', 'group' => 'Ventas'],
            ['key' => 'orders.view', 'label' => 'Ver Historial de Ventas', 'group' => 'Ventas'],
            ['key' => 'orders.cancel', 'label' => 'Cancelar Ventas', 'group' => 'Ventas'],
            ['key' => 'products.manage', 'label' => 'Gestionar Productos', 'group' => 'Catálogo'],
            ['key' => 'categories.manage', 'label' => 'Gestionar Categorías', 'group' => 'Catálogo'],
            ['key' => 'promotions.manage', 'label' => 'Gestionar Promociones', 'group' => 'Catálogo'],
            ['key' => 'stock.manage', 'label' => 'Gestionar Inventario', 'group' => 'Logística'],
            ['key' => 'petty_cash.operate', 'label' => 'Operar Caja Chica', 'group' => 'Logística'],
            ['key' => 'cash_register.close', 'label' => 'Cerrar Caja', 'group' => 'Logística'],
            ['key' => 'users.manage', 'label' => 'Gestionar Usuarios', 'group' => 'Administración'],
            ['key' => 'roles.manage', 'label' => 'Gestionar Roles', 'group' => 'Administración'],
            ['key' => 'settings.manage', 'label' => 'Configuración del Sistema', 'group' => 'Administración'],
            ['key' => 'finance.view', 'label' => 'Ver Panel Financiero', 'group' => 'Administración'],
            ['key' => 'trash.manage', 'label' => 'Gestionar Papelera', 'group' => 'Administración'],
            ['key' => 'notifications.manage', 'label' => 'Configurar Notificaciones', 'group' => 'Administración'],
        ];
    }
}
