<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrashController extends Controller
{
    private const MODELS = [
        'products' => Product::class,
        'categories' => Category::class,
        'users' => User::class,
        'promotions' => Promotion::class,
    ];

    public function index(Request $request, string $type): JsonResponse
    {
        if (!isset(self::MODELS[$type])) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_TRASH_INVALID_TYPE',
                'message' => 'Tipo de papelera no válido.',
                'errors' => [],
                'metadata' => ['valid_types' => array_keys(self::MODELS)],
            ], 422);
        }

        $model = self::MODELS[$type];
        $query = $model::onlyTrashed()->with('deletedByUser:id,name,email');

        if ($type === 'products') {
            $query->with('category:id,name');
        }

        if ($type === 'users') {
            $query->with('roles:id,name');
        }

        if ($type === 'promotions') {
            $query->with('products:id,name');
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search, $type) {
                $q->where('name', 'ilike', "%{$search}%");
                if ($type === 'products') {
                    $q->orWhere('sku', 'ilike', "%{$search}%");
                }
                if ($type === 'users') {
                    $q->orWhere('email', 'ilike', "%{$search}%");
                }
            });
        }

        $items = $query->orderByDesc('deleted_at')->paginate(15);

        return response()->json([
            'status' => 'success',
            'data' => $items,
        ]);
    }

    public function restore(Request $request, string $type, string $id): JsonResponse
    {
        if (!isset(self::MODELS[$type])) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_TRASH_INVALID_TYPE',
                'message' => 'Tipo de papelera no válido.',
                'errors' => [],
                'metadata' => null,
            ], 422);
        }

        $model = self::MODELS[$type];
        $record = $model::onlyTrashed()->find($id);

        if (!$record) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_TRASH_NOT_FOUND',
                'message' => 'Registro no encontrado en la papelera.',
                'errors' => [],
                'metadata' => null,
            ], 404);
        }

        $record->advancedRestore();

        return response()->json([
            'status' => 'success',
            'metadata' => ['message' => 'Registro restaurado exitosamente.'],
        ]);
    }

    public function forceDelete(Request $request, string $type, string $id): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('roles');
        $userRoles = $user->roles->pluck('name')->toArray();

        if (!in_array('admin', $userRoles)) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_TRASH_ADMIN_ONLY',
                'message' => 'Solo el administrador puede eliminar permanentemente.',
                'errors' => [],
                'metadata' => null,
            ], 403);
        }

        if (!isset(self::MODELS[$type])) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_TRASH_INVALID_TYPE',
                'message' => 'Tipo de papelera no válido.',
                'errors' => [],
                'metadata' => null,
            ], 422);
        }

        $request->validate([
            'confirmation' => 'required|string|in:ELIMINAR',
        ]);

        $model = self::MODELS[$type];
        $record = $model::onlyTrashed()->find($id);

        if (!$record) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_TRASH_NOT_FOUND',
                'message' => 'Registro no encontrado en la papelera.',
                'errors' => [],
                'metadata' => null,
            ], 404);
        }

        $record->forceDelete();

        return response()->json([
            'status' => 'success',
            'metadata' => ['message' => 'Registro eliminado permanentemente de la base de datos.'],
        ]);
    }
}
