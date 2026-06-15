<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockMovement\StoreStockMovementRequest;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockMovementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StockMovement::with(['product:id,name,sku', 'user:id,name'])
            ->orderByDesc('created_at');

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('reason')) {
            $query->where('reason', $request->reason);
        }
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to);
        }

        $movements = $query->paginate($request->input('per_page', 20));

        return response()->json([
            'status' => 'success',
            'data' => $movements->items(),
            'metadata' => [
                'current_page' => $movements->currentPage(),
                'last_page' => $movements->lastPage(),
                'total' => $movements->total(),
            ],
        ]);
    }

    public function store(StoreStockMovementRequest $request): JsonResponse
    {
        $movement = DB::transaction(function () use ($request) {
            $product = Product::lockForUpdate()->findOrFail($request->product_id);
            $quantity = (int) $request->quantity;

            if ($request->type === 'purchase_input') {
                $product->increment('current_stock', $quantity);
            } elseif ($request->type === 'merma_output') {
                if ($product->current_stock < $quantity) {
                    abort(422, 'Stock insuficiente para registrar la merma. Stock actual: ' . $product->current_stock);
                }
                $product->decrement('current_stock', $quantity);
            } elseif ($request->type === 'adjustment') {
                $product->update(['current_stock' => $quantity]);
            }

            return StockMovement::create([
                'product_id' => $request->product_id,
                'user_id' => $request->user()->id,
                'type' => $request->type,
                'quantity' => $quantity,
                'cost_price_at_movement' => $request->cost_price_at_movement,
                'reason' => $request->reason,
            ]);
        });

        $movement->load(['product:id,name,sku,current_stock', 'user:id,name']);

        return response()->json([
            'status' => 'success',
            'data' => $movement,
            'metadata' => ['message' => 'Movimiento de stock registrado exitosamente.'],
        ], 201);
    }

    public function summary(Request $request): JsonResponse
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->input('date_to', now()->endOfDay()->toDateTimeString());

        $byType = DB::table('stock_movements')
            ->select('type', DB::raw('COUNT(*) as count'), DB::raw('SUM(quantity) as total_quantity'), DB::raw('SUM(cost_price_at_movement * quantity) as total_cost'))
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->groupBy('type')
            ->get();

        $byReason = DB::table('stock_movements')
            ->select('reason', DB::raw('COUNT(*) as count'), DB::raw('SUM(quantity) as total_quantity'), DB::raw('SUM(cost_price_at_movement * quantity) as total_cost'))
            ->where('type', 'merma_output')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->whereNotNull('reason')
            ->groupBy('reason')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'by_type' => $byType,
                'merma_by_reason' => $byReason,
                'period' => ['from' => $dateFrom, 'to' => $dateTo],
            ],
        ]);
    }
}
