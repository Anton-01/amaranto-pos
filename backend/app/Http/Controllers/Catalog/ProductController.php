<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\DeleteProductRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::with('category:id,name');

        if ($request->has('include_deleted') && $request->boolean('include_deleted')) {
            $query->withTrashed();
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('sku', 'ilike', "%{$search}%")
                  ->orWhere('parent_sku', 'ilike', "%{$search}%");
            });
        }

        if ($request->has('category_id')) {
            $ids = is_array($request->category_id) ? $request->category_id : [$request->category_id];
            $query->whereIn('category_id', $ids);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('sale_price_min')) {
            $query->where('sale_price', '>=', $request->sale_price_min);
        }

        if ($request->has('sale_price_max')) {
            $query->where('sale_price', '<=', $request->sale_price_max);
        }

        if ($request->has('parent_sku')) {
            $query->where('parent_sku', $request->parent_sku);
        }

        if ($request->has('low_stock') && $request->boolean('low_stock')) {
            $query->whereColumn('current_stock', '<=', 'minimum_stock');
        }

        $sortField = $request->get('sort_field', 'name');
        $sortOrder = $request->get('sort_order', 'asc');
        $allowedSorts = ['name', 'sku', 'sale_price', 'current_stock', 'created_at'];
        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortOrder === 'desc' ? 'desc' : 'asc');
        }

        $products = $query->get();

        return response()->json([
            'status' => 'success',
            'data' => $products,
        ]);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create($request->validated());
        $product->load('category:id,name');

        return response()->json([
            'status' => 'success',
            'code' => 'PRODUCT_CREATED',
            'message' => 'Producto creado exitosamente.',
            'data' => $product,
        ], 201);
    }

    public function show(Product $product): JsonResponse
    {
        $product->load('category:id,name');

        $variations = null;
        if ($product->parent_sku) {
            $variations = Product::where('parent_sku', $product->parent_sku)
                ->where('id', '!=', $product->id)
                ->select('id', 'sku', 'name', 'sale_price', 'current_stock', 'is_active')
                ->get();
        }

        return response()->json([
            'status' => 'success',
            'data' => $product,
            'metadata' => [
                'variations' => $variations,
            ],
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product->update($request->validated());
        $product->load('category:id,name');

        return response()->json([
            'status' => 'success',
            'code' => 'PRODUCT_UPDATED',
            'message' => 'Producto actualizado exitosamente.',
            'data' => $product,
        ]);
    }

    public function destroy(DeleteProductRequest $request, Product $product): JsonResponse
    {
        $product->advancedDelete(
            $request->user()->id,
            $request->validated('deletion_reason')
        );

        return response()->json([
            'status' => 'success',
            'code' => 'PRODUCT_DELETED',
            'message' => 'Producto eliminado exitosamente.',
        ]);
    }

    public function grouped(): JsonResponse
    {
        $products = Product::with('category:id,name')
            ->where('is_active', true)
            ->orderBy('parent_sku')
            ->orderBy('name')
            ->get()
            ->groupBy(fn ($p) => $p->parent_sku ?? 'sin_grupo_' . $p->id);

        $groups = [];
        foreach ($products as $key => $items) {
            $groups[] = [
                'parent_sku' => str_starts_with($key, 'sin_grupo_') ? null : $key,
                'display_name' => $items->first()->name,
                'items' => $items->values(),
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => $groups,
        ]);
    }
}
