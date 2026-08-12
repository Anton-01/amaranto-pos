<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use Illuminate\Database\Seeder;

/**
 * Catalogo de demostracion: 3 categorias con productos coherentes y dos
 * promociones vigentes.
 *
 * Existe para que un `docker compose up` deje el POS OPERABLE: sin productos
 * no se puede cobrar, y sin cobrar no se puede probar ni el ticket, ni el
 * arqueo de caja, ni el inventario. Los precios de catalogo YA incluyen IVA,
 * que es la convencion de la casa (ver App\Services\OrderCalculator).
 *
 * `track_stock` distingue lo que se inventaria (botella y lata) de lo que se
 * prepara al momento o se arma como paquete: descontar stock de un capuchino
 * dejaria el inventario en negativo desde la primera venta.
 */
class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [];

        foreach (['Bebidas', 'Paquetes', 'Refrescos'] as $name) {
            $categories[$name] = Category::create([
                'name' => $name,
                'is_active' => true,
            ]);
        }

        foreach ($this->products() as $product) {
            $category = $categories[$product['category']];
            unset($product['category']);

            Product::create($product + [
                'category_id' => $category->id,
                'is_active' => true,
            ]);
        }

        $this->seedPromotions();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function products(): array
    {
        return [
            // --- Bebidas -------------------------------------------------
            [
                'category' => 'Bebidas',
                'sku' => 'BEB-001',
                'name' => 'Café Americano 12 oz',
                'cost_price' => 8.50,
                'sale_price' => 32.00,
                'current_stock' => 0,
                'minimum_stock' => 0,
                'maximum_stock' => 0,
                'track_stock' => false,
            ],
            [
                'category' => 'Bebidas',
                'sku' => 'BEB-002',
                'name' => 'Capuchino 12 oz',
                'cost_price' => 12.00,
                'sale_price' => 45.00,
                'current_stock' => 0,
                'minimum_stock' => 0,
                'maximum_stock' => 0,
                'track_stock' => false,
            ],
            [
                'category' => 'Bebidas',
                'sku' => 'BEB-003',
                'name' => 'Limonada Natural 500 ml',
                'cost_price' => 9.00,
                'sale_price' => 38.00,
                'current_stock' => 0,
                'minimum_stock' => 0,
                'maximum_stock' => 0,
                'track_stock' => false,
            ],
            [
                'category' => 'Bebidas',
                'sku' => 'BEB-004',
                'name' => 'Agua Natural 600 ml',
                'cost_price' => 6.50,
                'sale_price' => 18.00,
                'current_stock' => 120,
                'minimum_stock' => 24,
                'maximum_stock' => 240,
                'track_stock' => true,
            ],
            [
                'category' => 'Bebidas',
                'sku' => 'BEB-005',
                'name' => 'Jugo de Naranja 1 L',
                'cost_price' => 18.00,
                'sale_price' => 42.00,
                'current_stock' => 40,
                'minimum_stock' => 12,
                'maximum_stock' => 96,
                'track_stock' => true,
            ],
            [
                'category' => 'Bebidas',
                'sku' => 'BEB-006',
                'name' => 'Cerveza Artesanal 355 ml',
                'cost_price' => 22.00,
                'sale_price' => 65.00,
                // Debajo del minimo a proposito: dispara la alerta de stock
                // bajo y deja el tablero con algo que mostrar desde el minuto
                // cero.
                'current_stock' => 18,
                'minimum_stock' => 24,
                'maximum_stock' => 144,
                'track_stock' => true,
            ],

            // --- Refrescos -----------------------------------------------
            [
                'category' => 'Refrescos',
                'sku' => 'REF-001',
                'name' => 'Refresco de Cola 600 ml',
                'cost_price' => 11.00,
                'sale_price' => 25.00,
                'current_stock' => 96,
                'minimum_stock' => 24,
                'maximum_stock' => 240,
                'track_stock' => true,
            ],
            [
                'category' => 'Refrescos',
                'sku' => 'REF-002',
                'name' => 'Refresco de Cola Sin Azúcar 600 ml',
                'parent_sku' => 'REF-001',
                'cost_price' => 11.00,
                'sale_price' => 25.00,
                'current_stock' => 60,
                'minimum_stock' => 24,
                'maximum_stock' => 180,
                'track_stock' => true,
            ],
            [
                'category' => 'Refrescos',
                'sku' => 'REF-003',
                'name' => 'Refresco de Lima-Limón 600 ml',
                'cost_price' => 10.50,
                'sale_price' => 24.00,
                'current_stock' => 54,
                'minimum_stock' => 18,
                'maximum_stock' => 180,
                'track_stock' => true,
            ],
            [
                'category' => 'Refrescos',
                'sku' => 'REF-004',
                'name' => 'Refresco de Naranja 600 ml',
                'cost_price' => 10.50,
                'sale_price' => 24.00,
                'current_stock' => 48,
                'minimum_stock' => 18,
                'maximum_stock' => 180,
                'track_stock' => true,
            ],
            [
                'category' => 'Refrescos',
                'sku' => 'REF-005',
                'name' => 'Refresco de Manzana 600 ml',
                'cost_price' => 10.00,
                'sale_price' => 24.00,
                'current_stock' => 36,
                'minimum_stock' => 18,
                'maximum_stock' => 144,
                'track_stock' => true,
            ],
            [
                'category' => 'Refrescos',
                'sku' => 'REF-006',
                'name' => 'Agua Mineral 355 ml',
                'cost_price' => 9.00,
                'sale_price' => 22.00,
                'current_stock' => 42,
                'minimum_stock' => 12,
                'maximum_stock' => 120,
                'track_stock' => true,
            ],

            // --- Paquetes ------------------------------------------------
            // Se arman con productos de las otras dos categorias, de modo que
            // su precio siempre queda por debajo de la suma de sus partes.
            [
                'category' => 'Paquetes',
                'sku' => 'PAQ-001',
                'name' => 'Paquete Desayuno (Café + Jugo)',
                'cost_price' => 26.50,
                'sale_price' => 68.00,
                'current_stock' => 0,
                'minimum_stock' => 0,
                'maximum_stock' => 0,
                'track_stock' => false,
            ],
            [
                'category' => 'Paquetes',
                'sku' => 'PAQ-002',
                'name' => 'Paquete Oficina (4 Refrescos)',
                'cost_price' => 43.00,
                'sale_price' => 92.00,
                'current_stock' => 0,
                'minimum_stock' => 0,
                'maximum_stock' => 0,
                'track_stock' => false,
            ],
            [
                'category' => 'Paquetes',
                'sku' => 'PAQ-003',
                'name' => 'Paquete Fiesta (12 Refrescos + 4 Aguas)',
                'cost_price' => 158.00,
                'sale_price' => 329.00,
                'current_stock' => 0,
                'minimum_stock' => 0,
                'maximum_stock' => 0,
                'track_stock' => false,
            ],
            [
                'category' => 'Paquetes',
                'sku' => 'PAQ-004',
                'name' => 'Paquete Cafetería (2 Capuchinos + Limonada)',
                'cost_price' => 33.00,
                'sale_price' => 118.00,
                'current_stock' => 0,
                'minimum_stock' => 0,
                'maximum_stock' => 0,
                'track_stock' => false,
            ],
        ];
    }

    /**
     * Dos promociones VIGENTES (una por porcentaje y una por monto fijo) para
     * poder probar las dos ramas de App\Services\OrderCalculator sin capturar
     * nada a mano.
     */
    private function seedPromotions(): void
    {
        $refrescos = Promotion::create([
            'name' => 'Martes de Refrescos -15%',
            'type' => 'percentage',
            'value' => 15.00,
            'start_date' => now()->subDays(7),
            'end_date' => now()->addDays(30),
            'is_active' => true,
        ]);

        $refrescos->products()->attach(
            Product::whereIn('sku', ['REF-001', 'REF-002', 'REF-003', 'REF-004', 'REF-005'])->pluck('id')
        );

        $paquete = Promotion::create([
            'name' => 'Paquete Oficina -$20',
            'type' => 'fixed_amount',
            'value' => 20.00,
            'start_date' => now()->subDays(3),
            'end_date' => now()->addDays(15),
            'is_active' => true,
        ]);

        $paquete->products()->attach(
            Product::where('sku', 'PAQ-002')->pluck('id')
        );
    }
}
