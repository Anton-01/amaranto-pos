<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\CashRegister;
use App\Models\GlobalSetting;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\StockMovement;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\TicketConfig;
use App\Models\User;
use App\Services\OrderCalculator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Operacion de demostracion: un turno de caja abierto, ventas de mostrador ya
 * cobradas (con sus tickets), una venta cancelada y una cuenta de mesa viva.
 *
 * Objetivo: que al terminar `docker compose up` el sistema tenga algo que
 * ENSEÑAR y algo que COBRAR. Sin esto, el primer arranque deja el historial de
 * ventas vacio, el inventario sin movimientos y el POS bloqueado con
 * ERR_POS_CASH_REGISTER_REQUIRED hasta que alguien abra caja a mano.
 *
 * Toda la aritmetica pasa por App\Services\OrderCalculator —el mismo motor que
 * usan OrderController y TableSessionController—, de modo que los importes
 * sembrados son indistinguibles de los que produce la aplicacion: si el arqueo
 * cuadra sobre estos datos, cuadra en produccion.
 */
class DemoSalesSeeder extends Seeder
{
    private OrderCalculator $calculator;

    private User $admin;

    private TicketConfig $ticketConfig;

    private CashRegister $cashRegister;

    private float $taxRate;

    public function run(): void
    {
        $this->calculator = new OrderCalculator;
        $this->admin = User::where('email', 'admin@cronos.pos')->firstOrFail();
        $this->ticketConfig = TicketConfig::where('is_active', true)->firstOrFail();

        $taxSetting = GlobalSetting::where('key', 'tax_rate')->first();
        $this->taxRate = $taxSetting ? (float) ($taxSetting->value['rate'] ?? 0.16) : 0.16;

        $this->seedPurchaseLedger();
        $this->seedShrinkage();

        // Turno ABIERTO: el POS exige una caja viva para poder cobrar.
        $this->cashRegister = CashRegister::create([
            'user_id' => $this->admin->id,
            'opening_balance' => 1500.00,
            'opened_at' => now()->subHours(4),
        ]);

        $this->seedCounterSales();
        $this->seedCanceledSale();
        $this->seedOpenTableAccount();
    }

    /**
     * Entrada de mercancia que respalda el stock inicial del catalogo.
     *
     * Sin estos movimientos el inventario nace con existencias que ningun
     * documento explica, y el kardex arranca en blanco.
     */
    private function seedPurchaseLedger(): void
    {
        $received = now()->subDays(5)->setTime(8, 30);

        Product::where('track_stock', true)
            ->where('current_stock', '>', 0)
            ->get()
            ->each(fn (Product $product) => StockMovement::create([
                'product_id' => $product->id,
                'user_id' => $this->admin->id,
                'type' => 'purchase_input',
                'quantity' => $product->current_stock,
                'cost_price_at_movement' => $product->cost_price,
                'reason' => null,
                'created_at' => $received,
            ]));
    }

    /** Una merma real, para que el modulo de mermas no nazca vacio. */
    private function seedShrinkage(): void
    {
        $product = Product::where('sku', 'REF-005')->first();

        if (! $product || $product->current_stock < 3) {
            return;
        }

        $product->decrement('current_stock', 3);

        StockMovement::create([
            'product_id' => $product->id,
            'user_id' => $this->admin->id,
            'type' => 'merma_output',
            'quantity' => 3,
            'cost_price_at_movement' => $product->cost_price,
            'reason' => 'damaged_spilled',
            'created_at' => now()->subDays(2)->setTime(17, 10),
        ]);
    }

    /** Tres tickets cobrados a lo largo del turno. */
    private function seedCounterSales(): void
    {
        // Efectivo, con promocion por linea sobre los refrescos.
        $this->sell(
            [
                ['sku' => 'REF-001', 'quantity' => 2, 'promotion' => 'Martes de Refrescos -15%'],
                ['sku' => 'BEB-004', 'quantity' => 1],
                ['sku' => 'BEB-001', 'quantity' => 1],
            ],
            'cash',
            now()->subHours(3),
            ['custom_legend' => 'Pedido para llevar'],
        );

        // Tarjeta, con promocion de monto fijo sobre el paquete.
        $this->sell(
            [
                ['sku' => 'PAQ-002', 'quantity' => 1, 'promotion' => 'Paquete Oficina -$20'],
                ['sku' => 'BEB-002', 'quantity' => 2],
            ],
            'card',
            now()->subHours(2),
        );

        // Efectivo, con descuento GLOBAL del 10% prorrateado entre las lineas.
        $this->sell(
            [
                ['sku' => 'PAQ-003', 'quantity' => 1],
                ['sku' => 'REF-003', 'quantity' => 4],
                ['sku' => 'BEB-005', 'quantity' => 2],
            ],
            'cash',
            now()->subMinutes(45),
            [
                'discount_type' => 'percentage',
                'discount_value' => 10,
                'custom_legend' => 'Cliente frecuente',
            ],
        );
    }

    /** Venta cancelada con devolucion de stock, replicando OrderController::cancel. */
    private function seedCanceledSale(): void
    {
        $order = $this->sell(
            [['sku' => 'BEB-006', 'quantity' => 3]],
            'transfer',
            now()->subMinutes(90),
        );

        $canceledAt = now()->subMinutes(80);

        $order->load('items');

        foreach ($order->items as $item) {
            $product = $item->product_id ? Product::find($item->product_id) : null;

            if (! $product || ! $product->track_stock) {
                continue;
            }

            $product->increment('current_stock', $item->quantity);

            StockMovement::create([
                'product_id' => $product->id,
                'user_id' => $this->admin->id,
                'type' => 'adjustment',
                'quantity' => $item->quantity,
                'cost_price_at_movement' => $item->base_price_at_sale ?? 0,
                'reason' => null,
                'created_at' => $canceledAt,
            ]);
        }

        // `expected_closing_balance` NO se revierte, igual que en la
        // aplicacion: el arqueo real (CashClosingService) recalcula sobre las
        // ordenes 'completed', asi que una cancelada nunca infla el esperado.
        $order->forceFill([
            'status' => Order::STATUS_CANCELED,
            'canceled_by' => $this->admin->id,
            'canceled_at' => $canceledAt,
            'cancellation_reason' => 'Cliente cambio de opinion antes de recoger el pedido.',
        ])->save();

        AuditLog::create([
            'action' => 'order_canceled',
            'auditable_type' => 'Order',
            'auditable_id' => $order->id,
            'user_id' => $this->admin->id,
            'metadata' => [
                'order_id' => $order->id,
                'order_total' => $order->total,
                'reason' => $order->cancellation_reason,
                'authorizer_name' => $this->admin->name,
                'authorizer_email' => $this->admin->email,
                'items_count' => $order->items->count(),
                'stock_reverted' => true,
            ],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'DemoSalesSeeder',
            'created_at' => $canceledAt,
        ]);
    }

    /**
     * Mesa 3 ocupada con una comanda a medio consumir.
     *
     * Queda LISTA PARA COBRAR: sirve para probar el cierre de mesa, el reparto
     * del descuento global y la impresion del ticket de comedor sin capturar
     * nada. La orden nace 'open', que scopeSettled() excluye del historial de
     * ventas: una cuenta sin cobrar todavia no es un ingreso.
     */
    private function seedOpenTableAccount(): void
    {
        $table = Table::where('name', 'Mesa 3')->first();

        if (! $table || $table->status !== Table::STATUS_AVAILABLE) {
            return;
        }

        $openedAt = now()->subMinutes(35);

        $order = Order::create([
            'ticket_config_id' => $this->ticketConfig->id,
            'table_id' => $table->id,
            'table_name_at_sale' => $table->name,
            'waiter_id' => $this->admin->id,
            'waiter_name_at_sale' => $this->admin->name,
            'discount_type' => 'none',
            'discount_value' => 0,
            'discount_total' => 0,
            'subtotal' => 0,
            'iva_total' => 0,
            'total' => 0,
            'status' => Order::STATUS_OPEN,
        ]);

        $lines = $this->buildLines([
            ['sku' => 'BEB-002', 'quantity' => 2],
            ['sku' => 'REF-002', 'quantity' => 2],
            ['sku' => 'PAQ-001', 'quantity' => 1],
        ]);

        // La cuenta abierta jamas lleva descuento global: se aplica una sola
        // vez, en el cobro (TableSessionController::close).
        $composed = $this->calculator->compose($lines, $this->taxRate);

        foreach ($composed['items'] as $index => $itemData) {
            // Dos rondas: la trazabilidad por hora de comanda es justo lo que
            // se quiere poder mirar en la pantalla de la mesa.
            $order->items()->create($itemData + [
                'created_at' => $openedAt->copy()->addMinutes($index < 2 ? 3 : 18),
            ]);
        }

        $order->forceFill([
            'subtotal' => $composed['subtotal'],
            'iva_total' => $composed['iva_total'],
            'total' => $composed['total'],
            'created_at' => $openedAt,
            'updated_at' => $openedAt,
        ])->save();

        $session = TableSession::create([
            'table_id' => $table->id,
            'order_id' => $order->id,
            'user_id' => $this->admin->id,
            'guests' => 4,
            'notes' => 'Cuenta de demostracion sembrada al levantar el entorno.',
            'status' => TableSession::STATUS_OPEN,
            'opened_at' => $openedAt,
        ]);

        $table->update(['status' => Table::STATUS_OCCUPIED]);

        AuditLog::create([
            'action' => 'table_session_opened',
            'auditable_type' => 'TableSession',
            'auditable_id' => $session->id,
            'user_id' => $this->admin->id,
            'metadata' => [
                'table_id' => $table->id,
                'table_name' => $table->name,
                'order_id' => $order->id,
                'guests' => $session->guests,
                'waiter_name' => $this->admin->name,
            ],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'DemoSalesSeeder',
            'created_at' => $openedAt,
        ]);
    }

    /**
     * Cobra una venta de mostrador replicando OrderController::store.
     *
     * @param  array<int, array{sku: string, quantity: int, promotion?: string}>  $items
     * @param  array{discount_type?: string, discount_value?: float, custom_legend?: string}  $options
     */
    private function sell(array $items, string $paymentSlug, Carbon $soldAt, array $options = []): Order
    {
        $lines = $this->buildLines($items);

        $discountType = $options['discount_type'] ?? 'none';
        $discountValue = (float) ($options['discount_value'] ?? 0);

        $composed = $this->calculator->compose($lines, $this->taxRate, $discountType, $discountValue);

        // En efectivo el cliente paga con billetes: se redondea hacia arriba al
        // siguiente multiplo de 50 para que el ticket tenga recibido y cambio.
        $received = $paymentSlug === 'cash'
            ? max(50.0, ceil($composed['total'] / 50) * 50)
            : null;

        $order = Order::create([
            'cash_register_id' => $this->cashRegister->id,
            'ticket_config_id' => $this->ticketConfig->id,
            'payment_method_id' => PaymentMethod::where('slug', $paymentSlug)->firstOrFail()->id,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_total' => $composed['discount_total'],
            'subtotal' => $composed['subtotal'],
            'iva_total' => $composed['iva_total'],
            'total' => $composed['total'],
            'amount_received' => $received,
            'amount_change' => $received !== null ? round($received - $composed['total'], 2) : null,
            'custom_legend' => $options['custom_legend'] ?? null,
            'status' => Order::STATUS_COMPLETED,
        ]);

        foreach ($composed['items'] as $itemData) {
            $order->items()->create($itemData + ['created_at' => $soldAt]);
        }

        // Repartidas en el turno: con todo en `now()` los reportes por hora y
        // los filtros por rango de fecha nacen sin nada que distinguir.
        $order->forceFill(['created_at' => $soldAt, 'updated_at' => $soldAt])->save();

        $this->cashRegister->increment('expected_closing_balance', $composed['total']);

        return $order;
    }

    /**
     * Traduce SKUs a lineas del calculador y descuenta el stock rastreado.
     *
     * @param  array<int, array{sku: string, quantity: int, promotion?: string}>  $items
     * @return array<int, array<string, mixed>>
     */
    private function buildLines(array $items): array
    {
        $lines = [];

        foreach ($items as $item) {
            $product = Product::where('sku', $item['sku'])->firstOrFail();

            $promotion = isset($item['promotion'])
                ? Promotion::where('name', $item['promotion'])->firstOrFail()
                : null;

            $lines[] = $this->calculator->line($product, $promotion, $item['quantity']);

            if ($product->track_stock) {
                $product->decrement('current_stock', $item['quantity']);
            }
        }

        return $lines;
    }
}
