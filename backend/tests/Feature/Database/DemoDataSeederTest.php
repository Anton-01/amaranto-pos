<?php

namespace Tests\Feature\Database;

use App\Builders\TicketBuilder;
use App\Models\CashRegister;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Table;
use App\Models\User;
use App\Services\CashClosingService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\RequiresPostgres;
use Tests\TestCase;

/**
 * Contrato de los datos de demostracion (DemoCatalogSeeder + DemoSalesSeeder).
 *
 * El seeder base deja el sistema OPERABLE (ver SeederLeavesSystemOperational);
 * estos dos lo dejan ademas PROBABLE: catalogo con que cobrar, tickets ya
 * emitidos que mirar e imprimir, inventario con movimientos y una caja abierta.
 *
 * Lo que se fija aqui no es la decoracion sino la coherencia: si los importes
 * sembrados no cuadran contra el motor de calculo real, el primer arqueo de
 * caja de quien pruebe el sistema saldra descuadrado y la culpa parecera del
 * codigo de produccion.
 */
class DemoDataSeederTest extends TestCase
{
    use DatabaseMigrations;
    use RequiresPostgres;

    protected $dropTypes = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessPostgresAvailable();

        Artisan::call('db:seed', ['--force' => true]);
    }

    public function test_siembra_las_tres_categorias_con_productos_propios(): void
    {
        $this->assertEqualsCanonicalizing(
            ['Bebidas', 'Paquetes', 'Refrescos'],
            Category::pluck('name')->all()
        );

        foreach (Category::all() as $category) {
            $this->assertGreaterThanOrEqual(
                3,
                Product::where('category_id', $category->id)->count(),
                "La categoria {$category->name} nacio sin productos suficientes para probar el POS."
            );
        }

        // Un catalogo sin precio de venta no se puede cobrar.
        $this->assertSame(
            0,
            Product::where('sale_price', '<=', 0)->orWhere('cost_price', '<', 0)->count()
        );
    }

    public function test_el_inventario_solo_se_rastrea_donde_tiene_sentido(): void
    {
        // Lo que se prepara al momento o se arma como paquete no lleva stock:
        // descontarlo dejaria el inventario en negativo desde la primera venta.
        $this->assertSame(
            0,
            Product::where('track_stock', false)->where('current_stock', '>', 0)->count()
        );

        $rastreados = Product::where('track_stock', true)->get();
        $this->assertGreaterThan(0, $rastreados->count());

        foreach ($rastreados as $product) {
            $this->assertGreaterThanOrEqual(
                0,
                $product->current_stock,
                "El producto {$product->sku} quedo con stock negativo tras las ventas sembradas."
            );
        }

        // Toda existencia inicial tiene un documento que la respalda.
        $this->assertGreaterThan(0, StockMovement::where('type', 'purchase_input')->count());
    }

    public function test_las_ventas_sembradas_cuadran_contra_su_propio_detalle(): void
    {
        $ordenes = Order::with('items')->get();

        $this->assertGreaterThanOrEqual(3, $ordenes->count());

        foreach ($ordenes as $order) {
            $lineas = round($order->items->sum(fn ($item) => (float) $item->final_price_at_sale), 2);

            $this->assertEqualsWithDelta(
                (float) $order->total,
                $lineas,
                0.02,
                "La orden {$order->id} no cuadra: sus lineas suman {$lineas} y el total dice {$order->total}."
            );

            $this->assertEqualsWithDelta(
                (float) $order->total,
                round((float) $order->subtotal + (float) $order->iva_total, 2),
                0.02,
                "En la orden {$order->id} el IVA no se despeja del total."
            );
        }
    }

    public function test_deja_una_caja_abierta_y_su_arqueo_es_consistente(): void
    {
        $admin = User::where('email', 'admin@cronos.pos')->firstOrFail();

        $caja = CashRegister::where('user_id', $admin->id)->whereNull('closed_at')->first();

        $this->assertNotNull(
            $caja,
            'Sin caja abierta el POS responde 422 ERR_POS_CASH_REGISTER_REQUIRED y no se puede cobrar nada.'
        );

        $snapshot = app(CashClosingService::class)->snapshot($caja);

        // El arqueo solo cuenta ventas cobradas: ni las canceladas ni las
        // cuentas de mesa abiertas son ingreso.
        $cobradas = Order::where('cash_register_id', $caja->id)
            ->where('status', Order::STATUS_COMPLETED)
            ->sum('total');

        $this->assertEqualsWithDelta((float) $cobradas, $snapshot['sales_total'], 0.02);
        $this->assertEqualsWithDelta(
            (float) $caja->opening_balance + (float) $cobradas,
            $snapshot['expected_total'],
            0.02
        );
    }

    public function test_deja_una_cuenta_de_mesa_viva_lista_para_cobrar(): void
    {
        $mesa = Table::where('status', Table::STATUS_OCCUPIED)->first();

        $this->assertNotNull($mesa, 'Ninguna mesa quedo ocupada: no hay nada que cobrar en el comedor.');

        $sesion = $mesa->activeSession;

        $this->assertNotNull($sesion);
        $this->assertSame(Order::STATUS_OPEN, $sesion->order->status);
        $this->assertGreaterThan(0, $sesion->order->items->count());
        $this->assertGreaterThan(0, (float) $sesion->order->total);

        // Una cuenta abierta todavia no es una venta.
        $this->assertNotContains(
            $sesion->order->id,
            Order::settled()->pluck('id')->all()
        );
    }

    public function test_una_venta_sembrada_se_puede_imprimir(): void
    {
        $order = Order::where('status', Order::STATUS_COMPLETED)
            ->whereNotNull('amount_received')
            ->with(['items.product', 'ticketConfig'])
            ->first();

        $this->assertNotNull($order, 'Ninguna venta en efectivo quedo sembrada: el ticket no se puede probar.');

        $ticket = app(TicketBuilder::class)->build($order, $order->ticketConfig);

        $this->assertNotEmpty($ticket->empresa);
        $this->assertSame($order->items->count(), count($ticket->items));
        $this->assertNotNull($ticket->recibido);
        $this->assertNotNull($ticket->cambio);
        $this->assertGreaterThanOrEqual(
            (float) $order->total,
            (float) $order->amount_received,
            'El efectivo recibido no puede ser menor que el total: el cambio saldria negativo.'
        );
    }
}
