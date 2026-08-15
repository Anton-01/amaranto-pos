<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\DeleteProductRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\CacheConfiguration;
use App\Models\Product;
use App\Support\ModuleCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Columns the product DataTable actually renders.
     *
     * Everything the grid does not paint stays in PostgreSQL: `maximum_stock`,
     * `image_url`, the audit trail of the soft delete (`deleted_by`,
     * `deletion_reason`) and the `created_at` / `updated_at` timestamps. With
     * a catalog of a few thousand rows that is the difference between a
     * payload the browser parses instantly and one it has to stream.
     *
     * `category_id` is not decorative: it is the foreign key the
     * `category:id,name` eager load matches on, and dropping it would leave
     * every row without its category label.
     */
    private const LIST_COLUMNS = [
        'id',
        'sku',
        'parent_sku',
        'name',
        'category_id',
        'cost_price',
        'sale_price',
        'current_stock',
        'minimum_stock',
        'is_active',
        'track_stock',
    ];

    public function index(Request $request): JsonResponse
    {
        $columns = self::LIST_COLUMNS;

        if ($request->has('include_deleted') && $request->boolean('include_deleted')) {
            // SoftDeletes decides `trashed()` by reading this column, so it
            // has to travel whenever the caller asks for deleted rows.
            $columns[] = 'deleted_at';
        }

        $query = Product::query()
            ->select($columns)
            ->with('category:id,name');

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

    public function toggleStatus(Product $product): JsonResponse
    {
        $product->is_active = !$product->is_active;
        $product->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Estatus actualizado correctamente.',
            'is_active' => $product->is_active,
        ]);
    }

    /**
     * Product grid of the POS and of the dine-in order modal.
     *
     * Payload discipline — this is the single largest response the cashier
     * downloads, and the terminal only paints a name, a SKU, a price and the
     * stock guard. `cost_price` is therefore deliberately absent: leaving it
     * in would publish the business margin of every article to whoever opens
     * the network tab of a POS terminal, which is a data leak, not a byte
     * count. The category relation is not loaded either — no consumer of this
     * endpoint renders it.
     *
     * Caching — the catalog changes far less often than it is read, so it is
     * memoized under the `product_catalog` module. Product::booted() drops the
     * entry on any write, including the stock decrement of a sale, so the grid
     * never advertises units that are no longer there.
     */
    public function grouped(): JsonResponse
    {
        $groups = ModuleCache::remember(
            CacheConfiguration::MODULE_PRODUCT_CATALOG,
            'active',
            function (): array {
                $products = Product::query()
                    ->select(['id', 'sku', 'parent_sku', 'name', 'sale_price', 'current_stock', 'minimum_stock', 'track_stock'])
                    ->where('is_active', true)
                    ->orderBy('parent_sku')
                    ->orderBy('name')
                    ->get()
                    ->groupBy(fn (Product $p) => $p->parent_sku ?? 'sin_grupo_' . $p->id);

                $groups = [];
                foreach ($products as $key => $items) {
                    $groups[] = [
                        'parent_sku' => str_starts_with($key, 'sin_grupo_') ? null : $key,
                        'display_name' => $items->first()->name,
                        'items' => $items->values()->toArray(),
                    ];
                }

                return $groups;
            }
        );

        return response()->json([
            'status' => 'success',
            'data' => $groups,
        ]);
    }
}
