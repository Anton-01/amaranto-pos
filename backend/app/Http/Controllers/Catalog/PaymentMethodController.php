<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PaymentMethod::orderBy('name');

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:100|unique:payment_methods,name',
            'slug' => 'required|string|max:50|unique:payment_methods,slug',
            'status' => 'sometimes|string|in:active,inactive',
        ]);

        $method = PaymentMethod::create([
            'name' => $request->name,
            'slug' => $request->slug,
            'status' => $request->input('status', 'active'),
            'is_system' => false,
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $method,
            'metadata' => ['message' => 'Método de pago creado exitosamente.'],
        ], 201);
    }

    public function update(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|string|max:100|unique:payment_methods,name,' . $paymentMethod->id,
            'slug' => 'sometimes|string|max:50|unique:payment_methods,slug,' . $paymentMethod->id,
            'status' => 'sometimes|string|in:active,inactive',
        ]);

        $paymentMethod->update($request->only(['name', 'slug', 'status']));

        return response()->json([
            'status' => 'success',
            'data' => $paymentMethod->fresh(),
            'metadata' => ['message' => 'Método de pago actualizado exitosamente.'],
        ]);
    }

    public function destroy(PaymentMethod $paymentMethod): JsonResponse
    {
        $salesCount = $paymentMethod->orders()->count();

        if ($salesCount > 0) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_PAYMENT_METHOD_HAS_SALES',
                'message' => 'No se puede eliminar el método de pago porque tiene transacciones históricas asociadas.',
                'metadata' => [
                    'sales_count' => $salesCount,
                    'suggestion' => 'Cambie el estatus a "inactive" en lugar de eliminar.',
                ],
            ], 422);
        }

        $paymentMethod->delete();

        return response()->json([
            'status' => 'success',
            'metadata' => ['message' => 'Método de pago eliminado exitosamente.'],
        ]);
    }
}
