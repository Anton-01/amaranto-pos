<?php

namespace App\Http\Controllers\Finance;

use App\Events\PettyCashTransactionRegistered;
use App\Http\Controllers\Controller;
use App\Http\Requests\PettyCash\StorePettyCashRequest;
use App\Models\PettyCashTransaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PettyCashController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PettyCashTransaction::with('user:id,name,email');

        if ($request->has('reason')) {
            $query->where('reason', $request->reason);
        }

        if ($request->has('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->where('created_at', '<=', $request->date_to);
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $transactions = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'data' => $transactions,
        ]);
    }

    public function store(StorePettyCashRequest $request): JsonResponse
    {
        $user = $request->user();

        $hasOpenRegister = \App\Models\CashRegister::where('user_id', $user->id)
            ->whereNull('closed_at')
            ->exists();

        if (!$hasOpenRegister) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_POS_CASH_REGISTER_REQUIRED',
                'message' => 'Debes abrir una caja antes de registrar retiros de caja chica.',
                'errors' => null,
                'metadata' => null,
            ], 422);
        }

        $validated = $request->validated();

        $transaction = DB::transaction(function () use ($user, $validated) {
            $previousBalance = PettyCashTransaction::sum('amount');
            $newBalance = $previousBalance + $validated['amount'];
            $timestamp = now()->toIso8601String();

            $dataString = implode('|', [
                $user->id,
                $user->name,
                $validated['amount'],
                $validated['reason'],
                $validated['description'],
                $previousBalance,
                $newBalance,
                $timestamp,
            ]);

            $securitySeal = hash('sha256', $dataString . config('app.key'));

            $snapshot = [
                'operator_id' => $user->id,
                'operator_name' => $user->name,
                'operator_email' => $user->email,
                'amount' => $validated['amount'],
                'reason' => $validated['reason'],
                'description' => $validated['description'],
                'balance_before' => round($previousBalance, 2),
                'balance_after' => round($newBalance, 2),
                'timestamp' => $timestamp,
                'security_seal' => $securitySeal,
            ];

            return PettyCashTransaction::create([
                'user_id' => $user->id,
                'amount' => $validated['amount'],
                'reason' => $validated['reason'],
                'description' => $validated['description'],
                'immutable_snapshot' => $snapshot,
            ]);
        });

        $transaction->load('user:id,name,email');

        event(new PettyCashTransactionRegistered($transaction));

        return response()->json([
            'status' => 'success',
            'code' => 'PETTY_CASH_REGISTERED',
            'message' => 'Retiro de caja chica registrado exitosamente.',
            'data' => $transaction,
        ], 201);
    }

    public function show(PettyCashTransaction $pettyCashTransaction): JsonResponse
    {
        $pettyCashTransaction->load('user:id,name,email');

        $isValid = $pettyCashTransaction->verifyIntegrity();

        return response()->json([
            'status' => 'success',
            'data' => $pettyCashTransaction,
            'metadata' => [
                'integrity_valid' => $isValid,
            ],
        ]);
    }

    public function summary(): JsonResponse
    {
        $totalWithdrawals = PettyCashTransaction::sum('amount');
        $todayStart = Carbon::now('America/Mexico_City')->startOfDay();
        $todayEnd = Carbon::now('America/Mexico_City')->endOfDay();
        $countToday = PettyCashTransaction::whereBetween('created_at', [$todayStart, $todayEnd])->count();
        $totalToday = PettyCashTransaction::whereBetween('created_at', [$todayStart, $todayEnd])->sum('amount');

        $byReason = PettyCashTransaction::select('reason')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('SUM(amount) as total')
            ->groupBy('reason')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_withdrawals' => round($totalWithdrawals, 2),
                'transactions_today' => $countToday,
                'total_today' => round($totalToday, 2),
                'by_reason' => $byReason,
            ],
        ]);
    }

    public function verify(PettyCashTransaction $pettyCashTransaction): JsonResponse
    {
        $isValid = $pettyCashTransaction->verifyIntegrity();

        return response()->json([
            'status' => 'success',
            'data' => [
                'transaction_id' => $pettyCashTransaction->id,
                'integrity_valid' => $isValid,
                'snapshot' => $pettyCashTransaction->immutable_snapshot,
            ],
        ]);
    }
}
