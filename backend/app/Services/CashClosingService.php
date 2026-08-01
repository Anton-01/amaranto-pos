<?php

namespace App\Services;

use App\Models\CashRegister;
use App\Models\CashRegisterClosing;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PettyCashTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Motor unico del arqueo de caja.
 *
 * Extraido de CashRegisterClosingController::store para que el cierre manual
 * del cajero y el cierre automatico del scheduler compartan exactamente la
 * misma aritmetica: Esperado = Fondo + Ventas('completed') - Caja Chica.
 * Dos copias de esa formula es como se llega a un arqueo que no cuadra.
 *
 * El registro resultante (cash_register_closings) es un ledger insert-only:
 * el modelo bloquea todo update y delete a nivel de Eloquent.
 */
class CashClosingService
{
    /**
     * Radiografia financiera de una caja abierta, sin efectos secundarios.
     *
     * @return array{payment_methods: \Illuminate\Support\Collection, expected_by_method: \Illuminate\Support\Collection, opening_balance: float, petty_cash_total: float, sales_total: float, expected_total: float}
     */
    public function snapshot(CashRegister $cashRegister): array
    {
        $paymentMethods = PaymentMethod::where('status', 'active')
            ->orderBy('name')
            ->get();

        $expectedByMethod = Order::where('cash_register_id', $cashRegister->id)
            ->where('status', 'completed')
            ->selectRaw('payment_method_id, SUM(total) as total')
            ->groupBy('payment_method_id')
            ->get()
            ->keyBy('payment_method_id');

        $openingBalance = (float) $cashRegister->opening_balance;

        $pettyCashTotal = (float) PettyCashTransaction::where('user_id', $cashRegister->user_id)
            ->where('created_at', '>=', $cashRegister->opened_at)
            ->sum('amount');

        $salesTotal = round((float) $expectedByMethod->sum('total'), 2);

        return [
            'payment_methods' => $paymentMethods,
            'expected_by_method' => $expectedByMethod,
            'opening_balance' => $openingBalance,
            'petty_cash_total' => round($pettyCashTotal, 2),
            'sales_total' => $salesTotal,
            'expected_total' => round($openingBalance + $salesTotal - $pettyCashTotal, 2),
        ];
    }

    /**
     * Cierra la caja y genera su registro de arqueo inmutable.
     *
     * @param  array<string, float|int|string>  $declarations  Montos declarados por payment_method_id.
     *         En el cierre automatico se pasa null: el sistema no cuenta dinero
     *         fisico, por lo que asume declarado = esperado y lo deja asentado
     *         en `notes` para la auditoria.
     */
    public function close(
        CashRegister $cashRegister,
        User $closedBy,
        ?array $declarations,
        bool $automated = false,
        ?string $notes = null,
    ): CashRegisterClosing {
        return DB::transaction(function () use ($cashRegister, $closedBy, $declarations, $automated, $notes) {
            // Serializa cierres concurrentes sobre la misma caja (cajero
            // cerrando manualmente a las 20:59 contra el job de las 21:00).
            $locked = CashRegister::whereKey($cashRegister->id)->lockForUpdate()->firstOrFail();

            if ($locked->closed_at !== null || $locked->closing()->exists()) {
                throw new \RuntimeException('ERR_REGISTER_ALREADY_CLOSED: La caja ya fue cerrada.');
            }

            $snapshot = $this->snapshot($locked);

            $breakdown = [];
            $declaredTotal = 0.0;

            foreach ($snapshot['payment_methods'] as $pm) {
                $expected = round((float) ($snapshot['expected_by_method'][$pm->id]->total ?? 0), 2);
                $declared = $declarations === null
                    ? $expected
                    : round((float) ($declarations[$pm->id] ?? 0), 2);

                $breakdown[] = [
                    'payment_method_id' => $pm->id,
                    'name' => $pm->name,
                    'slug' => $pm->slug,
                    'expected' => $expected,
                    'declared' => $declared,
                    'difference' => round($declared - $expected, 2),
                ];

                $declaredTotal += $declared;
            }

            // Con declarations null el declarado por metodo no incluye fondo ni
            // caja chica, asi que el total declarado se iguala al esperado
            // completo: el cierre automatico asienta diferencia cero declarada.
            $declaredTotal = $declarations === null
                ? $snapshot['expected_total']
                : round($declaredTotal, 2);

            $closing = CashRegisterClosing::create([
                'cash_register_id' => $locked->id,
                'closed_by' => $closedBy->id,
                'expected_amount' => $snapshot['expected_total'],
                'declared_amount' => $declaredTotal,
                'difference_amount' => round($declaredTotal - $snapshot['expected_total'], 2),
                'payment_breakdown' => $breakdown,
                'is_automated' => $automated,
                'notes' => $notes,
            ]);

            $locked->update([
                'closed_at' => now(),
                'actual_closing_balance' => $declaredTotal,
            ]);

            return $closing;
        });
    }
}
