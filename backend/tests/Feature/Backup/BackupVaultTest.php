<?php

namespace Tests\Feature\Backup;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Backup\BackupService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RequiresPostgres;
use Tests\TestCase;

/**
 * Boveda de respaldos y rollback manual (Fase 10).
 *
 * El disco se sustituye por `Storage::fake`, que es precisamente lo que hace
 * valiosa la indireccion por la fachada `Storage`: la logica de checksum,
 * manifiesto y catalogo se prueba sin credenciales de Google Cloud.
 */
class BackupVaultTest extends TestCase
{
    /*
     * DatabaseMigrations y NO RefreshDatabase, a proposito.
     *
     * RefreshDatabase envuelve cada prueba en una transaccion abierta. El
     * rollback restaura con `psql` desde OTRA conexion, y sus `DROP TABLE`
     * necesitan un lock ACCESS EXCLUSIVE que esa transaccion bloquea: la
     * suite se queda colgada hasta agotar el timeout. Sin transaccion
     * envolvente no hay locks que disputar.
     */
    use DatabaseMigrations;
    use RequiresPostgres;

    /** `migrate:fresh` no borra los ENUM nativos de PostgreSQL sin esto. */
    protected $dropTypes = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessPostgresAvailable();

        /*
         * `Storage::fake` sustituye la INSTANCIA del disco pero no toca
         * `config('filesystems.disks.*')`. BackupService::vaultConfigured()
         * lee esa configuracion para decidir si la boveda esta operativa, asi
         * que sin credenciales simuladas degradaria al disco local y las
         * aserciones sobre `gcs_backups` mirarian el lugar equivocado.
         */
        config([
            'filesystems.disks.gcs_backups.bucket' => 'cronos-pos-backups-isolation',
            'filesystems.disks.gcs_backups.key_file_json' => '{"type":"service_account"}',
        ]);

        Storage::fake('gcs_backups');

        config([
            'backup.disk' => 'gcs_backups',
            'backup.encryption.enabled' => false,
            'backup.restore.pre_restore_snapshot' => false,
            'backup.restore.require_confirmation_phrase' => 'RESTAURAR PRODUCCION',
            // Un bloqueo por locks debe fallar rapido en pruebas, no colgar
            // la suite durante los 15 minutos del ajuste de produccion.
            'backup.restore.statement_timeout_seconds' => 30,
            'backup.dump_timeout_seconds' => 60,
        ]);
    }

    private function service(): BackupService
    {
        return app(BackupService::class);
    }

    // -----------------------------------------------------------------
    // Generacion
    // -----------------------------------------------------------------

    public function test_genera_un_snapshot_con_artefacto_manifiesto_y_checksum(): void
    {
        $manifest = $this->service()->create($this->admin(), 'prueba');

        Storage::disk('gcs_backups')->assertExists($manifest->artifact);
        Storage::disk('gcs_backups')->assertExists($manifest->name.'.manifest.json');

        $this->assertSame(64, strlen($manifest->checksum), 'El checksum debe ser un SHA-256 hexadecimal.');
        $this->assertGreaterThan(0, $manifest->size_bytes);
        $this->assertContains('database.sql', $manifest->contents);
        $this->assertContains('metadata.json', $manifest->contents);
    }

    public function test_el_checksum_del_manifiesto_corresponde_al_artefacto_subido(): void
    {
        $manifest = $this->service()->create(null, 'prueba');

        $bytes = Storage::disk('gcs_backups')->get($manifest->artifact);

        $this->assertSame(hash('sha256', $bytes), $manifest->checksum);
    }

    public function test_el_espacio_de_trabajo_temporal_no_deja_el_dump_en_claro(): void
    {
        $this->service()->create(null, 'prueba');

        $residuo = glob(storage_path('app/backup-workspace/*')) ?: [];

        // El dump viaja en claro dentro del workspace y el artefacto local es
        // una copia integra de la base: ninguno debe sobrevivir a la subida.
        $this->assertSame(
            [],
            $residuo,
            'Quedaron restos del respaldo en el disco local: '.implode(', ', array_map('basename', $residuo))
        );
    }

    public function test_registra_la_creacion_en_la_auditoria_de_seguridad(): void
    {
        $admin = $this->admin();
        $manifest = $this->service()->create($admin, 'prueba');

        $audit = AuditLog::where('action', 'backup.created')->firstOrFail();

        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame($manifest->name, $audit->metadata['snapshot']);
        $this->assertSame($manifest->checksum, $audit->metadata['checksum']);
    }

    // -----------------------------------------------------------------
    // Catalogo e integridad
    // -----------------------------------------------------------------

    public function test_lista_los_puntos_de_restauracion_del_mas_reciente_al_mas_antiguo(): void
    {
        // El reloj se adelanta entre ambos: `created_at` tiene resolucion de
        // segundo y dos respaldos consecutivos pueden empatar, dejando el
        // orden a merced de como devuelva los archivos el sistema de ficheros.
        $primero = $this->service()->create(null, 'uno');

        $this->travel(5)->minutes();

        $segundo = $this->service()->create(null, 'dos');

        $this->travelBack();

        $snapshots = $this->service()->listSnapshots();

        $this->assertCount(2, $snapshots);
        $this->assertSame($segundo->name, $snapshots[0]->name);
        $this->assertSame($primero->name, $snapshots[1]->name);
    }

    public function test_un_manifiesto_corrupto_no_oculta_los_sanos(): void
    {
        $sano = $this->service()->create(null, 'sano');
        Storage::disk('gcs_backups')->put('roto.manifest.json', '{esto no es json');

        $snapshots = $this->service()->listSnapshots();

        $this->assertCount(1, $snapshots);
        $this->assertSame($sano->name, $snapshots[0]->name);
    }

    public function test_verify_aprueba_un_artefacto_intacto(): void
    {
        $manifest = $this->service()->create(null, 'prueba');

        $result = $this->service()->verify($manifest->name);

        $this->assertTrue($result['valid']);
        $this->assertSame($manifest->checksum, $result['actual']);
    }

    public function test_verify_detecta_un_artefacto_alterado(): void
    {
        $manifest = $this->service()->create(null, 'prueba');

        Storage::disk('gcs_backups')->put(
            $manifest->artifact,
            Storage::disk('gcs_backups')->get($manifest->artifact).'BASURA'
        );

        $result = $this->service()->verify($manifest->name);

        $this->assertFalse($result['valid']);
        $this->assertNotSame($result['expected'], $result['actual']);
    }

    // -----------------------------------------------------------------
    // Rollback
    // -----------------------------------------------------------------

    public function test_la_frase_de_confirmacion_incorrecta_aborta_el_rollback(): void
    {
        $manifest = $this->service()->create(null, 'prueba');

        $this->expectExceptionMessage('Frase de confirmacion incorrecta');

        $this->service()->restore($manifest->name, $this->admin(), 'frase equivocada');
    }

    public function test_un_checksum_alterado_bloquea_el_rollback_antes_de_tocar_la_base(): void
    {
        // El admin se crea ANTES de fijar la linea base: si se creara al
        // invocar restore(), el conteo subiria por la propia prueba y el
        // aserto no distinguiria eso de una restauracion indebida.
        $admin = $this->admin();
        $manifest = $this->service()->create(null, 'prueba');
        $usuariosAntes = User::count();

        Storage::disk('gcs_backups')->put(
            $manifest->artifact,
            Storage::disk('gcs_backups')->get($manifest->artifact).'BASURA'
        );

        try {
            $this->service()->restore($manifest->name, $admin, 'RESTAURAR PRODUCCION');
            $this->fail('El rollback debio abortar por integridad comprometida.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('INTEGRIDAD COMPROMETIDA', $e->getMessage());
        }

        $this->assertSame($usuariosAntes, User::count(), 'La base de datos fue modificada pese al fallo de integridad.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.restore.integrity_failed']);
    }

    public function test_el_rollback_revierte_los_datos_al_punto_seleccionado(): void
    {
        $admin = $this->admin();
        $manifest = $this->service()->create($admin, 'prueba');
        $usuariosEnSnapshot = User::count();

        // Cambio posterior al snapshot: debe desaparecer tras el rollback.
        User::create([
            'name' => 'Usuario Fantasma',
            'email' => 'fantasma@cronos.pos',
            'password' => bcrypt('x'),
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('users', ['email' => 'fantasma@cronos.pos']);

        $result = $this->service()->restore($manifest->name, $admin, 'RESTAURAR PRODUCCION');

        $this->assertTrue($result['checksum_verified']);
        $this->assertSame($usuariosEnSnapshot, User::count());
        $this->assertDatabaseMissing('users', ['email' => 'fantasma@cronos.pos']);
    }

    /**
     * El dump SOBRESCRIBE `audit_logs`: sin reinyeccion, un rollback borraria
     * su propia evidencia forense. Inaceptable en un sistema financiero.
     */
    public function test_la_traza_de_auditoria_sobrevive_al_rollback_que_la_sobrescribe(): void
    {
        $admin = $this->admin();
        $manifest = $this->service()->create($admin, 'prueba');

        // El snapshot se tomo antes de escribir `backup.created`, asi que el
        // dump trae la tabla de auditoria sin ninguna de estas entradas.
        $this->service()->restore($manifest->name, $admin, 'RESTAURAR PRODUCCION');

        $acciones = AuditLog::where('auditable_type', 'backup')->pluck('action');

        $this->assertContains('backup.restore.started', $acciones->all());
        $this->assertContains('backup.restore.completed', $acciones->all());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backup.restore.started',
        ]);
    }

    // -----------------------------------------------------------------
    // Endpoints
    // -----------------------------------------------------------------

    public function test_los_endpoints_de_boveda_son_exclusivos_de_admin(): void
    {
        $manager = $this->userWithRole('manager');

        $this->actingAs($manager)->getJson('/api/admin/backups')->assertForbidden();
        $this->actingAs($manager)->postJson('/api/admin/backups')->assertForbidden();
        $this->actingAs($manager)
            ->postJson('/api/admin/backups/algo/restore', ['confirmation_phrase' => 'x'])
            ->assertForbidden();
    }

    public function test_el_catalogo_expone_el_diagnostico_de_la_boveda(): void
    {
        $manifest = $this->service()->create(null, 'prueba');

        $this->actingAs($this->admin())
            ->getJson('/api/admin/backups')
            ->assertOk()
            ->assertJsonPath('data.snapshots.0.name', $manifest->name)
            ->assertJsonPath('data.restore_phrase', 'RESTAURAR PRODUCCION')
            ->assertJsonStructure(['data' => ['vault' => ['disk', 'isolated', 'encrypted', 'reason']]]);
    }

    public function test_el_endpoint_de_verificacion_reporta_corrupcion_con_422(): void
    {
        $manifest = $this->service()->create(null, 'prueba');

        $this->actingAs($this->admin())
            ->getJson("/api/admin/backups/{$manifest->name}/verify")
            ->assertOk()
            ->assertJsonPath('code', 'BACKUP_INTEGRITY_VALID');

        Storage::disk('gcs_backups')->put(
            $manifest->artifact,
            Storage::disk('gcs_backups')->get($manifest->artifact).'BASURA'
        );

        $this->actingAs($this->admin())
            ->getJson("/api/admin/backups/{$manifest->name}/verify")
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_BACKUP_INTEGRITY_COMPROMISED');
    }

    public function test_el_endpoint_de_rollback_exige_la_frase_de_confirmacion(): void
    {
        $manifest = $this->service()->create(null, 'prueba');

        $this->actingAs($this->admin())
            ->postJson("/api/admin/backups/{$manifest->name}/restore", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirmation_phrase');

        $this->actingAs($this->admin())
            ->postJson("/api/admin/backups/{$manifest->name}/restore", ['confirmation_phrase' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_BACKUP_RESTORE_FAILED');
    }

    // -----------------------------------------------------------------
    // Diagnostico de aislamiento
    // -----------------------------------------------------------------

    public function test_reporta_estado_degradado_cuando_gcp_no_esta_configurado(): void
    {
        Storage::forgetDisk('gcs_backups');

        config([
            'filesystems.disks.gcs_backups' => [
                'driver' => 'gcs',
                'bucket' => null,
                'key_file_path' => null,
                'key_file_json' => null,
            ],
            'backup.fallback_disk' => 'backups_local',
        ]);

        $status = $this->service()->status();

        $this->assertFalse($status['isolated']);
        $this->assertFalse($status['configured']);
        $this->assertSame('backups_local', $status['disk']);
        $this->assertStringContainsString('no esta configurada', $status['reason']);
    }

    public function test_la_poda_elimina_los_snapshots_vencidos(): void
    {
        $viejo = $this->service()->create(null, 'viejo');
        $nuevo = $this->service()->create(null, 'nuevo');

        // Se envejece el manifiesto del primero por debajo de la retencion.
        $disk = Storage::disk('gcs_backups');
        $data = json_decode($disk->get($viejo->name.'.manifest.json'), true);
        $data['created_at'] = now()->subDays(90)->toIso8601String();
        $disk->put($viejo->name.'.manifest.json', json_encode($data));

        $eliminados = $this->service()->prune(30);

        $this->assertSame([$viejo->name], $eliminados);
        $disk->assertMissing($viejo->artifact);
        $disk->assertExists($nuevo->artifact);
    }

    // -----------------------------------------------------------------

    private function admin(): User
    {
        return $this->userWithRole('admin');
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
}
