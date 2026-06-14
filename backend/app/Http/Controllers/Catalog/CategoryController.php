<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\DeleteCategoryRequest;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Category::query();

        if ($request->has('include_deleted') && $request->boolean('include_deleted')) {
            $query->withTrashed();
        }

        if ($request->has('search')) {
            $query->where('name', 'ilike', '%' . $request->search . '%');
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $categories = $query->withCount('products')->orderBy('name')->get();

        return response()->json([
            'status' => 'success',
            'data' => $categories,
        ]);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = Category::create($request->validated());

        return response()->json([
            'status' => 'success',
            'code' => 'CATEGORY_CREATED',
            'message' => 'Categoria creada exitosamente.',
            'data' => $category,
        ], 201);
    }

    public function show(Category $category): JsonResponse
    {
        $category->loadCount('products');

        return response()->json([
            'status' => 'success',
            'data' => $category,
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $category->update($request->validated());

        return response()->json([
            'status' => 'success',
            'code' => 'CATEGORY_UPDATED',
            'message' => 'Categoria actualizada exitosamente.',
            'data' => $category,
        ]);
    }

    public function destroy(DeleteCategoryRequest $request, Category $category): JsonResponse
    {
        $activeProducts = $category->products()->whereNull('deleted_at')->count();

        if ($activeProducts > 0) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_CAT_HAS_ACTIVE_PRODUCTS',
                'message' => "No se puede eliminar la categoria porque tiene {$activeProducts} producto(s) activo(s).",
                'errors' => [],
                'metadata' => ['active_products_count' => $activeProducts],
            ], 409);
        }

        $category->advancedDelete(
            $request->user()->id,
            $request->validated('deletion_reason')
        );

        return response()->json([
            'status' => 'success',
            'code' => 'CATEGORY_DELETED',
            'message' => 'Categoria eliminada exitosamente.',
        ]);
    }
}
