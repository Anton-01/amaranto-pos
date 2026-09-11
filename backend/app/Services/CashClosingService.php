<?php

namespace App\Services;

use App\Models\CashRegister;
use App\Models\CashRegisterClosing;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PettyCashTransaction;
use App\Models\User;
use Illuminate\Support\Collection;
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
     * @return array{payment_methods: Collection, expected_by_method: Collection, products: Collection, opening_balance: float, petty_cash_total: float, sales_total: float, expected_total: float}
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
            'products' => $this->productsSold($cashRegister),
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
                // Se congela junto con el dinero: nombre y costo unitario del
                // producto pueden cambiar mañana, el arqueo de hoy no.
                'product_breakdown' => $snapshot['products']->all(),
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

    /**
     * Que se vendio en este turno: producto, piezas, ingreso y costo.
     *
     * EL COSTO SE CONGELA AQUI. `order_items` guarda lo que se cobro por cada
     * linea, pero no lo que costo surtirla; el costo unitario vive en
     * `products.cost_price` y es un dato vivo que el catalogo puede cambiar
     * cualquier dia. Al capturarlo dentro del arqueo, el margen que reporta un
     * cierre sigue siendo el margen que tenia ese turno, no el que arrojaria
     * recalcularlo hoy.
     *
     * `leftJoin` y no `join`: un producto eliminado despues de la venta deja
     * `order_items.product_id` en NULL, y perder esas lineas haria que la suma
     * de la lista no cuadre con el dinero del arqueo. Se agrupan bajo el
     * nombre generico con costo cero —no hay de donde leerlo— y por eso el
     * costo de la fila se marca como no disponible.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function productsSold(CashRegister $cashRegister): Collection
    {
        return DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.cash_register_id', $cashRegister->id)
            ->where('orders.status', 'completed')
            // Una linea anulada en mesa sigue en la tabla como evidencia, pero
            // no se vendio: contarla inflaria las piezas del turno.
            ->whereNull('order_items.canceled_at')
            ->select(
                'order_items.product_id',
                DB::raw("COALESCE(products.name, 'Producto eliminado') as product_name"),
                DB::raw('SUM(order_items.quantity) as quantity_sold'),
                DB::raw('SUM(order_items.final_price_at_sale) as revenue'),
                DB::raw('SUM(order_items.quantity * COALESCE(products.cost_price, 0)) as cost'),
                DB::raw('COUNT(CASE WHEN products.id IS NULL THEN 1 END) as orphan_lines'),
            )
            ->groupBy('order_items.product_id', 'products.name')
            ->orderByDesc('revenue')
            ->get()
            ->map(function (object $row): array {
                $revenue = round((float) $row->revenue, 2);
                $costKnown = (int) $row->orphan_lines === 0;
                $cost = $costKnown ? round((float) $row->cost, 2) : null;

                return [
                    'product_id' => $row->product_id,
                    'name' => $row->product_name,
                    'quantity_sold' => (int) $row->quantity_sold,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    // El margen solo se afirma cuando el costo es conocido; una
                    // resta contra un costo ausente diria "margen del 100%".
                    'margin' => $cost === null ? null : round($revenue - $cost, 2),
                ];
            })
            ->values();
    }
}
