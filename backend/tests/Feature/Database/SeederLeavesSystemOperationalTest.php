<?php

namespace Tests\Feature\Database;

use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Table;
use App\Models\TicketConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\RequiresPostgres;
use Tests\TestCase;

/**
 * Una instalacion recien sembrada debe poder VENDER.
 *
 * El seeder no creaba ninguna `ticket_config` activa, y tanto
 * `OrderController@store` (mostrador) como `TableSessionController@open`
 * (comedor) abortan con ERR_TICKET_NO_ACTIVE_CONFIG y devuelven 422 si no la
 * encuentran. Resultado: tras cada `docker compose up` —que corre
 * `migrate:fresh --seed`— el POS arrancaba inoperante: el cajero no podia
 * cobrar y el mesero no podia abrir una mesa.
 *
 * Estas pruebas fijan el contrato minimo del seeder: lo que hace falta para
 * que el primer dia de operacion sea posible sin configurar nada a mano.
 */
class SeederLeavesSystemOperationalTest extends TestCase
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

    public function test_existe_exactamente_una_configuracion_de_ticket_activa(): void
    {
        $activas = TicketConfig::where('is_active', true)->get();

        $this->assertCount(
            1,
            $activas,
            'Sin una ticket_config activa el sistema no puede vender: /api/orders y '
            .'/api/tables/{id}/open responden 422 ERR_TICKET_NO_ACTIVE_CONFIG.'
        );

        $config = $activas->first();

        $this->assertSame(1, $config->version);
        $this->assertNotEmpty($config->business_name);
        $this->assertNotEmpty($config->rfc);
        $this->assertNotEmpty($config->address);
        $this->assertNotEmpty($config->phone);
        $this->assertNotNull($config->updated_by, 'La FK updated_by es obligatoria.');
    }

    public function test_se_puede_abrir_una_mesa_sin_configurar_nada_a_mano(): void
    {
        $admin = User::where('email', 'admin@cronos.pos')->firstOrFail();
        $table = Table::where('status', Table::STATUS_AVAILABLE)->firstOrFail();

        $response = $this->actingAs($admin)
            ->postJson("/api/tables/{$table->id}/open", ['guests' => 2, 'notes' => null]);

        $response->assertCreated();

        $this->assertSame(Table::STATUS_OCCUPIED, $table->fresh()->status);
        $this->assertDatabaseHas('table_sessions', ['table_id' => $table->id, 'status' => 'open']);
    }

    public function test_el_catalogo_base_queda_operativo(): void
    {
        // Sin metodos de pago no hay cobro; sin roles no hay RBAC; sin mesas
        // el comedor no existe. Son el minimo para el primer turno.
        $this->assertGreaterThan(0, PaymentMethod::where('status', 'active')->count());
        $this->assertEqualsCanonicalizing(
            ['admin', 'manager', 'vendor'],
            Role::pluck('name')->all()
        );
        $this->assertGreaterThan(0, Table::where('is_active', true)->count());
    }

    /**
     * El seeder debe poder correr sobre una base ya migrada tantas veces como
     * haga falta: es lo que ocurre en cada arranque del contenedor.
     */
    public function test_el_seeder_es_parte_de_un_arranque_repetible(): void
    {
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true, '--drop-types' => true]);
        $this->assertSame(1, TicketConfig::where('is_active', true)->count());

        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true, '--drop-types' => true]);
        $this->assertSame(1, TicketConfig::where('is_active', true)->count());
    }
}
