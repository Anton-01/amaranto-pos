<?php

namespace Tests\Feature\Dining;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use App\Services\CancellationAuthorizationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RequiresPostgres;
use Tests\TestCase;

/**
 * Line controls and secure cancellation of a live table account.
 *
 * The properties pinned here are the ones that make the feature auditable:
 * withdrawing already-registered consumption takes rank and always leaves a
 * trace, stock comes back when it does, and a table can only be voided by
 * somebody who actually holds the authority.
 */
class TableAccountControlsTest extends TestCase
{
    use DatabaseMigrations;
    use RequiresPostgres;

    protected $dropTypes = true;

    private const AUTHORIZATION_PASSWORD = 'clave-de-turno-2026';

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
        $roleModel = Role::firstOrCreate(['name' => $role], ['description' => $role]);

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

    private function product(float $price = 100.00, int $stock = 50): Product
    {
        $category = Category::firstOrCreate(['name' => 'Pruebas'], ['is_active' => true]);

        return Product::create([
            'sku' => 'TST-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'Producto de prueba',
            'category_id' => $category->id,
            'cost_price' => $price / 2,
            'sale_price' => $price,
            'current_stock' => $stock,
            'minimum_stock' => 0,
            'is_active' => true,
            'track_stock' => true,
        ]);
    }

    /**
     * Opens a table and sends one round to it, returning everything the
     * assertions need.
     *
     * @return array{table: Table, product: Product, item: OrderItem, session: TableSession}
     */
    private function openTableWithItems(User $waiter, int $quantity = 3, float $price = 100.00, int $stock = 50): array
    {
        $table = Table::where('status', Table::STATUS_AVAILABLE)->firstOrFail();
        $product = $this->product($price, $stock);

        $this->actingAs($waiter)
            ->postJson("/api/tables/{$table->id}/open", ['guests' => 2, 'notes' => null])
            ->assertCreated();

        $this->actingAs($waiter)
            ->postJson("/api/tables/{$table->id}/items", [
                'items' => [['product_id' => $product->id, 'quantity' => $quantity, 'promotion_id' => null]],
            ])
            ->assertOk();

        $session = TableSession::where('table_id', $table->id)
            ->where('status', TableSession::STATUS_OPEN)
            ->firstOrFail();

        return [
            'table' => $table,
            'product' => $product->fresh(),
            'item' => OrderItem::where('order_id', $session->order_id)->firstOrFail(),
            'session' => $session,
        ];
    }

    private function configureAuthorizationPassword(): void
    {
        app(CancellationAuthorizationService::class)->store(self::AUTHORIZATION_PASSWORD);
    }

    public function test_reducir_la_cantidad_devuelve_stock_y_deja_huella_en_auditoria(): void
    {
        $supervisor = $this->userWithRole('manager');
        ['table' => $table, 'product' => $product, 'item' => $item] = $this->openTableWithItems($supervisor, 3, 100.00, 50);

        $stockAfterOrder = $product->fresh()->current_stock;

        $response = $this->actingAs($supervisor)
            ->putJson("/api/tables/{$table->id}/items/{$item->id}", ['quantity' => 1]);

        $response->assertOk();
        $response->assertJsonPath('data.items.0.quantity', 1);
        $response->assertJsonPath('data.total', 100.0);

        // Two units walked back out of the account and back into inventory.
        $this->assertSame($stockAfterOrder + 2, $product->fresh()->current_stock);

        $log = AuditLog::where('action', 'item_removed_from_table')->latest('created_at')->firstOrFail();

        $this->assertSame('quantity_decreased', $log->metadata['operation']);
        $this->assertSame($supervisor->id, $log->user_id);
        $this->assertSame(3, $log->metadata['quantity_before']);
        $this->assertSame(1, $log->metadata['quantity_after']);
        $this->assertSame(2, $log->metadata['quantity_removed']);
        $this->assertSame(200.0, $log->metadata['amount_removed']);
        $this->assertSame($product->id, $log->metadata['product_id']);
    }

    public function test_eliminar_una_partida_la_retira_de_la_cuenta_y_reintegra_su_stock(): void
    {
        $supervisor = $this->userWithRole('manager');
        ['table' => $table, 'product' => $product, 'item' => $item] = $this->openTableWithItems($supervisor, 2, 150.00, 20);

        $stockAfterOrder = $product->fresh()->current_stock;

        $response = $this->actingAs($supervisor)
            ->deleteJson("/api/tables/{$table->id}/items/{$item->id}");

        $response->assertOk();
        $response->assertJsonPath('data.items', []);
        $response->assertJsonPath('data.total', 0.0);

        $this->assertDatabaseMissing('order_items', ['id' => $item->id]);
        $this->assertSame($stockAfterOrder + 2, $product->fresh()->current_stock);

        $log = AuditLog::where('action', 'item_removed_from_table')->latest('created_at')->firstOrFail();

        $this->assertSame('item_deleted', $log->metadata['operation']);
        $this->assertSame(2, $log->metadata['quantity_removed']);
        $this->assertSame(300.0, $log->metadata['amount_removed']);

        // The reversal is visible in the inventory ledger, not just as a jump
        // in current_stock.
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'adjustment',
            'quantity' => 2,
        ]);
    }

    public function test_no_se_puede_aumentar_una_partida_mas_alla_del_stock_disponible(): void
    {
        $supervisor = $this->userWithRole('manager');
        ['table' => $table, 'item' => $item] = $this->openTableWithItems($supervisor, 2, 80.00, 3);

        $this->actingAs($supervisor)
            ->putJson("/api/tables/{$table->id}/items/{$item->id}", ['quantity' => 10])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_POS_INSUFFICIENT_STOCK');

        // The rejected attempt left the account exactly as it was.
        $this->assertSame(2, (int) $item->fresh()->quantity);
    }

    public function test_una_partida_ajena_a_la_mesa_no_puede_tocarse_desde_otra_mesa(): void
    {
        $supervisor = $this->userWithRole('manager');
        ['item' => $item] = $this->openTableWithItems($supervisor);

        $otherTable = Table::where('status', Table::STATUS_AVAILABLE)->firstOrFail();
        $this->actingAs($supervisor)
            ->postJson("/api/tables/{$otherTable->id}/open", ['guests' => 1, 'notes' => null])
            ->assertCreated();

        $this->actingAs($supervisor)
            ->putJson("/api/tables/{$otherTable->id}/items/{$item->id}", ['quantity' => 1])
            ->assertStatus(404)
            ->assertJsonPath('code', 'ERR_TABLE_ITEM_NOT_FOUND');
    }

    public function test_un_mesero_no_puede_retirar_partidas_ya_comandadas(): void
    {
        $waiter = $this->userWithRole('vendor');
        ['table' => $table, 'product' => $product, 'item' => $item] = $this->openTableWithItems($waiter, 3, 100.00, 50);

        $stockAfterOrder = $product->fresh()->current_stock;

        // The waiter opened the table and sent the round, but withdrawing what
        // is already on the account takes rank.
        $this->actingAs($waiter)
            ->putJson("/api/tables/{$table->id}/items/{$item->id}", ['quantity' => 1])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_AUTH_FORBIDDEN_ROLE');

        $this->actingAs($waiter)
            ->deleteJson("/api/tables/{$table->id}/items/{$item->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_AUTH_FORBIDDEN_ROLE');

        // Neither the account nor the inventory moved.
        $this->assertSame(3, (int) $item->fresh()->quantity);
        $this->assertSame($stockAfterOrder, $product->fresh()->current_stock);
        $this->assertSame(0, AuditLog::where('action', 'item_removed_from_table')->count());
    }

    public function test_un_administrador_cancela_la_mesa_sin_contrasena_de_autorizacion(): void
    {
        $admin = $this->admin();
        ['table' => $table, 'product' => $product, 'session' => $session] = $this->openTableWithItems($admin, 2, 250.00, 30);

        $stockAfterOrder = $product->fresh()->current_stock;

        $response = $this->actingAs($admin)
            ->postJson("/api/tables/{$table->id}/cancel", [
                'reason' => 'El cliente se retiro antes de consumir.',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.canceled_amount', 500.0);

        $this->assertSame(TableSession::STATUS_CANCELED, $session->fresh()->status);
        $this->assertSame(Table::STATUS_AVAILABLE, $table->fresh()->status);

        $order = Order::findOrFail($session->order_id);
        $this->assertSame(Order::STATUS_CANCELED, $order->status);
        $this->assertSame('El cliente se retiro antes de consumir.', $order->cancellation_reason);
        $this->assertSame($admin->id, $order->canceled_by);

        // Lines are stamped, never deleted: the voided consumption is evidence.
        $this->assertSame(0, OrderItem::where('order_id', $order->id)->whereNull('canceled_at')->count());
        $this->assertGreaterThan(0, OrderItem::where('order_id', $order->id)->count());

        $this->assertSame($stockAfterOrder + 2, $product->fresh()->current_stock);

        $log = AuditLog::where('action', 'table_canceled')->latest('created_at')->firstOrFail();

        $this->assertSame(CancellationAuthorizationService::AUTHORIZED_BY_ROLE, $log->metadata['authorized_by']);
        $this->assertSame('El cliente se retiro antes de consumir.', $log->metadata['reason']);
        $this->assertSame(500.0, $log->metadata['canceled_amount']);
        $this->assertSame($admin->id, $log->user_id);
    }

    public function test_un_no_administrador_necesita_la_contrasena_de_autorizacion_correcta(): void
    {
        $this->configureAuthorizationPassword();

        $waiter = $this->userWithRole('vendor');
        ['table' => $table, 'session' => $session] = $this->openTableWithItems($waiter, 1, 90.00, 10);

        $this->actingAs($waiter)
            ->postJson("/api/tables/{$table->id}/cancel", [
                'reason' => 'Comanda levantada en la mesa equivocada.',
                'authorization_password' => 'contrasena-incorrecta',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ERR_TABLE_CANCEL_UNAUTHORIZED');

        // The rejected attempt changed nothing.
        $this->assertSame(TableSession::STATUS_OPEN, $session->fresh()->status);
        $this->assertSame(Table::STATUS_OCCUPIED, $table->fresh()->status);

        $this->actingAs($waiter)
            ->postJson("/api/tables/{$table->id}/cancel", [
                'reason' => 'Comanda levantada en la mesa equivocada.',
                'authorization_password' => self::AUTHORIZATION_PASSWORD,
            ])
            ->assertOk();

        $this->assertSame(TableSession::STATUS_CANCELED, $session->fresh()->status);

        $log = AuditLog::where('action', 'table_canceled')->latest('created_at')->firstOrFail();
        $this->assertSame(CancellationAuthorizationService::AUTHORIZED_BY_PASSWORD, $log->metadata['authorized_by']);
    }

    public function test_el_motivo_es_obligatorio_para_cancelar(): void
    {
        $admin = $this->admin();
        ['table' => $table] = $this->openTableWithItems($admin, 1);

        $this->actingAs($admin)
            ->postJson("/api/tables/{$table->id}/cancel", ['reason' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_sin_contrasena_configurada_el_no_administrador_recibe_una_instruccion_accionable(): void
    {
        $waiter = $this->userWithRole('vendor');
        ['table' => $table] = $this->openTableWithItems($waiter, 1);

        $this->actingAs($waiter)
            ->postJson("/api/tables/{$table->id}/cancel", [
                'reason' => 'Mesa abierta por error.',
                'authorization_password' => 'lo-que-sea',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_CANCEL_PASSWORD_NOT_CONFIGURED');
    }

    public function test_la_contrasena_de_autorizacion_se_guarda_hasheada_y_no_se_expone(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->putJson('/api/admin/settings/cancellation-password', [
                'password' => self::AUTHORIZATION_PASSWORD,
                'password_confirmation' => self::AUTHORIZATION_PASSWORD,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_configured', true);

        $stored = DB::table('global_settings')
            ->where('key', CancellationAuthorizationService::SETTING_KEY)
            ->value('value');

        $this->assertStringNotContainsString(self::AUTHORIZATION_PASSWORD, (string) $stored);

        // The generic settings endpoint must neither read it back nor let a
        // clear-text value be written over it.
        $this->actingAs($admin)
            ->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonMissingPath('data.'.CancellationAuthorizationService::SETTING_KEY);

        $this->actingAs($admin)
            ->putJson('/api/admin/settings', [
                'settings' => [
                    ['key' => CancellationAuthorizationService::SETTING_KEY, 'value' => ['hash' => 'texto-plano']],
                ],
            ])
            ->assertStatus(422);
    }
}
