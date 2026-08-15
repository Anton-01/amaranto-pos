<?php

namespace Tests\Feature\Performance;

use App\Models\CacheConfiguration;
use App\Models\CashRegister;
use App\Models\Category;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Role;
use App\Models\TicketConfig;
use App\Models\User;
use App\Support\ModuleCache;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RequiresPostgres;
use Tests\TestCase;

/**
 * Database-driven cache policies and payload reduction.
 *
 * These tests pin the three properties the module exists for: the refresh
 * window really comes from the database (and switching it off really bypasses
 * the cache), a new sale purges the dashboard immediately instead of waiting
 * for a TTL, and the list endpoints stop shipping the columns nobody renders —
 * cost prices and cashier accounts in particular.
 */
class DynamicCacheTest extends TestCase
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

    private function admin(): User
    {
        return User::where('email', 'admin@cronos.pos')->firstOrFail();
    }

    private function userWithRole(string $role): User
    {
        $roleModel = Role::firstOrCreate(['name' => $role]);

        $user = User::create([
            'name' => 'Prueba '.$role,
            'email' => $role.'-'.uniqid().'@cronos.pos',
            'password' => bcrypt('secret'),
            'status' => 'active',
        ]);

        DB::table('model_has_roles')->insert([
            'model_id' => $user->id,
            'role_id' => $roleModel->id,
        ]);

        return $user->fresh();
    }

    private function product(float $price = 100.00): Product
    {
        $category = Category::firstOrCreate(['name' => 'Pruebas'], ['is_active' => true]);

        return Product::create([
            'sku' => 'TST-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'Producto de prueba',
            'category_id' => $category->id,
            'cost_price' => $price / 2,
            'sale_price' => $price,
            'current_stock' => 50,
            'minimum_stock' => 0,
            'is_active' => true,
            'track_stock' => true,
        ]);
    }

    /** Registers a completed counter sale so the dashboard has something to show. */
    private function sell(User $cashier, float $total = 250.00): Order
    {
        $register = CashRegister::create([
            'user_id' => $cashier->id,
            'opened_at' => now()->subHours(2),
            'opening_balance' => 500.00,
        ]);

        return Order::create([
            'cash_register_id' => $register->id,
            'ticket_config_id' => TicketConfig::where('is_active', true)->firstOrFail()->id,
            'payment_method_id' => PaymentMethod::where('slug', 'cash')->firstOrFail()->id,
            'subtotal' => $total,
            'iva_total' => 0,
            'total' => $total,
            'status' => Order::STATUS_COMPLETED,
        ]);
    }

    public function test_la_duracion_del_cache_se_lee_de_la_base_de_datos(): void
    {
        CacheConfiguration::create([
            'module_name' => CacheConfiguration::MODULE_DASHBOARD_STATS,
            'duration_minutes' => 60,
            'is_active' => true,
        ]);

        $this->assertSame(60, CacheConfiguration::durationFor(CacheConfiguration::MODULE_DASHBOARD_STATS));

        // A module without a row falls back to the conservative default
        // instead of running uncached.
        $this->assertSame(
            CacheConfiguration::DEFAULT_DURATION_MINUTES,
            CacheConfiguration::durationFor(CacheConfiguration::MODULE_PRODUCT_CATALOG)
        );
    }

    public function test_una_politica_inactiva_hace_que_el_modulo_lea_en_vivo(): void
    {
        CacheConfiguration::create([
            'module_name' => CacheConfiguration::MODULE_DASHBOARD_STATS,
            'duration_minutes' => 60,
            'is_active' => false,
        ]);

        $this->assertNull(CacheConfiguration::durationFor(CacheConfiguration::MODULE_DASHBOARD_STATS));

        // With the policy off the callback runs on every call: nothing is
        // memoized, so a second read sees the new value immediately.
        $calls = 0;
        $read = function () use (&$calls) {
            return ModuleCache::remember(
                CacheConfiguration::MODULE_DASHBOARD_STATS,
                'hoy',
                function () use (&$calls) {
                    $calls++;

                    return $calls;
                }
            );
        };

        $this->assertSame(1, $read());
        $this->assertSame(2, $read());
    }

    public function test_el_cache_activo_memoriza_la_consulta_y_flush_la_reconstruye(): void
    {
        CacheConfiguration::create([
            'module_name' => CacheConfiguration::MODULE_DASHBOARD_STATS,
            'duration_minutes' => 60,
            'is_active' => true,
        ]);

        $calls = 0;
        $read = function () use (&$calls) {
            return ModuleCache::remember(
                CacheConfiguration::MODULE_DASHBOARD_STATS,
                'hoy',
                function () use (&$calls) {
                    $calls++;

                    return $calls;
                }
            );
        };

        $this->assertSame(1, $read());
        $this->assertSame(1, $read(), 'La segunda lectura debe servirse del cache.');

        ModuleCache::flush(CacheConfiguration::MODULE_DASHBOARD_STATS);

        $this->assertSame(2, $read(), 'Tras el flush la consulta debe reconstruirse.');
    }

    public function test_una_venta_nueva_invalida_el_dashboard_de_hoy(): void
    {
        CacheConfiguration::create([
            'module_name' => CacheConfiguration::MODULE_DASHBOARD_STATS,
            'duration_minutes' => 2880,
            'is_active' => true,
        ]);

        $admin = $this->admin();

        // Primes the cache with the figures as they stand before the sale.
        $before = $this->actingAs($admin)->getJson('/api/dashboard/stats');
        $before->assertOk();
        $ordersBefore = $before->json('data.today_orders');
        $salesBefore = $before->json('data.monthly_sales');

        $this->sell($admin, 250.00);

        // The two-day window is irrelevant: Order::booted() purged the module
        // the moment the sale was written.
        $after = $this->actingAs($admin)->getJson('/api/dashboard/stats');
        $after->assertOk();
        $this->assertSame($ordersBefore + 1, $after->json('data.today_orders'));
        $this->assertSame(round($salesBefore + 250.0, 2), $after->json('data.monthly_sales'));
    }

    public function test_un_cambio_de_politica_purga_lo_guardado_bajo_la_ventana_anterior(): void
    {
        $policy = CacheConfiguration::create([
            'module_name' => CacheConfiguration::MODULE_DASHBOARD_STATS,
            'duration_minutes' => 2880,
            'is_active' => true,
        ]);

        $calls = 0;
        $read = function () use (&$calls) {
            return ModuleCache::remember(
                CacheConfiguration::MODULE_DASHBOARD_STATS,
                'hoy',
                function () use (&$calls) {
                    $calls++;

                    return $calls;
                }
            );
        };

        $this->assertSame(1, $read());

        $policy->update(['duration_minutes' => 15]);

        $this->assertSame(2, $read(), 'Acortar la ventana debe surtir efecto de inmediato.');
    }

    public function test_solo_un_administrador_gobierna_las_politicas_de_cache(): void
    {
        $manager = $this->userWithRole('manager');

        $this->actingAs($manager)->getJson('/api/admin/cache-configurations')->assertForbidden();
        $this->actingAs($this->admin())->getJson('/api/admin/cache-configurations')->assertOk();
    }

    public function test_el_catalogo_lista_los_modulos_sin_politica_configurada(): void
    {
        $response = $this->actingAs($this->admin())->getJson('/api/admin/cache-configurations');

        $response->assertOk();
        $this->assertCount(count(CacheConfiguration::MODULES), $response->json('data'));

        foreach ($response->json('data') as $row) {
            $this->assertFalse($row['is_configured']);
        }
    }

    public function test_solo_se_aceptan_los_tiempos_de_refresco_del_catalogo(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/admin/cache-configurations', [
            'module_name' => CacheConfiguration::MODULE_DASHBOARD_STATS,
            'duration_minutes' => 7,
        ])->assertStatus(422)->assertJsonValidationErrors('duration_minutes');

        $this->actingAs($admin)->postJson('/api/admin/cache-configurations', [
            'module_name' => CacheConfiguration::MODULE_DASHBOARD_STATS,
            'duration_minutes' => 1440,
        ])->assertCreated();

        // One policy per module: the second attempt is a field error, not a 500.
        $this->actingAs($admin)->postJson('/api/admin/cache-configurations', [
            'module_name' => CacheConfiguration::MODULE_DASHBOARD_STATS,
            'duration_minutes' => 30,
        ])->assertStatus(422)->assertJsonValidationErrors('module_name');
    }

    public function test_el_historial_de_ventas_no_expone_el_costo_ni_la_cuenta_del_cajero(): void
    {
        $cashier = $this->userWithRole('vendor');
        $this->sell($cashier, 300.00);

        $response = $this->actingAs($cashier)->getJson('/api/orders');
        $response->assertOk();

        $row = $response->json('data.0');

        // The cashier's identity is reduced to the name the grid prints.
        $this->assertSame($cashier->name, $row['cash_register']['user']['name']);
        $this->assertArrayNotHasKey('email', $row['cash_register']['user']);
        $this->assertArrayNotHasKey('two_factor_confirmed_at', $row['cash_register']['user']);

        // The ticket internals belong to the detail endpoint, not to the list.
        $this->assertArrayNotHasKey('items', $row);
        $this->assertArrayNotHasKey('subtotal', $row);

        $body = $response->getContent();
        $this->assertStringNotContainsString('cost_price', $body);
    }

    public function test_el_catalogo_del_pos_no_publica_el_margen_del_negocio(): void
    {
        $this->product(120.00);

        $response = $this->actingAs($this->userWithRole('vendor'))->getJson('/api/products/grouped');
        $response->assertOk();

        $item = $response->json('data.0.items.0');

        $this->assertArrayHasKey('sale_price', $item);
        $this->assertArrayNotHasKey('cost_price', $item);
        $this->assertStringNotContainsString('cost_price', $response->getContent());
    }

    public function test_el_plano_de_mesas_conserva_su_forma_con_las_columnas_recortadas(): void
    {
        $response = $this->actingAs($this->userWithRole('vendor'))->getJson('/api/tables');
        $response->assertOk();

        $table = $response->json('data.0');

        // presentTable() sigue armando el mismo contrato aunque la consulta
        // solo traiga las seis columnas que pinta.
        $this->assertSame(
            ['id', 'name', 'capacity', 'zone', 'status', 'is_active', 'active_session'],
            array_keys($table)
        );
        $this->assertNotNull($response->json('metadata.summary.total'));
    }

    public function test_el_listado_de_usuarios_no_devuelve_columnas_sensibles(): void
    {
        $response = $this->actingAs($this->admin())->getJson('/api/admin/users');
        $response->assertOk();

        $row = $response->json('data.0');

        $this->assertArrayHasKey('name', $row);
        $this->assertArrayHasKey('active_tokens_count', $row);
        $this->assertArrayNotHasKey('two_factor_confirmed_at', $row);
        $this->assertArrayNotHasKey('password_restored_at', $row);
        $this->assertArrayNotHasKey('deleted_at', $row);
    }
}
