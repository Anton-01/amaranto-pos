<?php

namespace App\Http\Controllers\Promotion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promotion\DeletePromotionRequest;
use App\Http\Requests\Promotion\StorePromotionRequest;
use App\Http\Requests\Promotion\UpdatePromotionRequest;
use App\Models\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromotionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Promotion::with('products:id,sku,name,sale_price');

        if ($request->has('include_deleted') && $request->boolean('include_deleted')) {
            $query->withTrashed();
        }

        if ($request->has('search')) {
            $query->where('name', 'ilike', '%' . $request->search . '%');
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        $query->orderBy('created_at', 'desc');

        return response()->json([
            'status' => 'success',
            'data' => $query->get(),
        ]);
    }

    public function active(): JsonResponse
    {
        $promotions = Promotion::with('products:id,sku,name,sale_price')
            ->where('is_active', true)
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now())
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $promotions,
        ]);
    }

    public function store(StorePromotionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $productIds = $validated['product_ids'] ?? [];
        unset($validated['product_ids']);

        $promotion = Promotion::create($validated);

        if (! empty($productIds)) {
            $promotion->products()->sync($productIds);
        }

        $promotion->load('products:id,sku,name,sale_price');

        return response()->json([
            'status' => 'success',
            'code' => 'PROMOTION_CREATED',
            'message' => 'Promocion creada exitosamente.',
            'data' => $promotion,
        ], 201);
    }

    public function show(Promotion $promotion): JsonResponse
    {
        $promotion->load('products:id,sku,name,sale_price');

        return response()->json([
            'status' => 'success',
            'data' => $promotion,
        ]);
    }

    public function update(UpdatePromotionRequest $request, Promotion $promotion): JsonResponse
    {
        $validated = $request->validated();
        $productIds = $validated['product_ids'] ?? null;
        unset($validated['product_ids']);

        $promotion->update($validated);

        if ($productIds !== null) {
            $promotion->products()->sync($productIds);
        }

        $promotion->load('products:id,sku,name,sale_price');

        return response()->json([
            'status' => 'success',
            'code' => 'PROMOTION_UPDATED',
            'message' => 'Promocion actualizada exitosamente.',
            'data' => $promotion,
        ]);
    }

    public function toggleStatus(Promotion $promotion): JsonResponse
    {
        $promotion->is_active = !$promotion->is_active;
        $promotion->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Estatus actualizado correctamente.',
            'is_active' => $promotion->is_active,
        ]);
    }

    public function destroy(DeletePromotionRequest $request, Promotion $promotion): JsonResponse
    {
        $promotion->advancedDelete(
            $request->user()->id,
            $request->validated('deletion_reason')
        );

        return response()->json([
            'status' => 'success',
            'code' => 'PROMOTION_DELETED',
            'message' => 'Promocion eliminada exitosamente.',
        ]);
    }
}
