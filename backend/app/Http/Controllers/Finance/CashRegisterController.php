<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\CashRegister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashRegisterController extends Controller
{
    public function active(Request $request): JsonResponse
    {
        $user = $request->user();

        $cashRegister = CashRegister::with('user:id,name')
            ->where('user_id', $user->id)
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->first();

        return response()->json([
            'status' => 'success',
            'data' => $cashRegister,
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $request->validate([
            'opening_balance' => 'required|numeric|min:0|max:9999999999.99',
        ], [
            'opening_balance.required' => 'El monto inicial de caja es obligatorio.',
            'opening_balance.numeric' => 'El monto inicial debe ser un número.',
            'opening_balance.min' => 'El monto inicial no puede ser negativo.',
        ]);

        $user = $request->user();

        $existing = CashRegister::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->first();

        if ($existing) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_POS_CASH_REGISTER_ALREADY_OPEN',
                'message' => 'Ya tienes una caja abierta. Ciérrala antes de abrir una nueva.',
                'data' => $existing,
            ], 422);
        }

        $cashRegister = CashRegister::create([
            'user_id' => $user->id,
            'opening_balance' => $request->opening_balance,
            'opened_at' => now(),
        ]);

        $auditMeta = [
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'token_id' => $request->user()->currentAccessToken()?->id,
            'opened_by' => $user->id,
            'opened_by_name' => $user->name,
            'opened_by_email' => $user->email,
        ];

        logger()->channel('daily')->info('Cash register opened', [
            'cash_register_id' => $cashRegister->id,
            'audit' => $auditMeta,
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $cashRegister,
            'metadata' => [
                'message' => 'Caja abierta exitosamente.',
                'audit' => $auditMeta,
            ],
        ], 201);
    }
}
