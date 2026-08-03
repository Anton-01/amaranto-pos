<?php

namespace Tests\Feature\Telemetry;

use App\Jobs\CreateDatabaseBackup;
use App\Listeners\JobTelemetrySubscriber;
use App\Models\JobExecutionLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\RequiresPostgres;
use Tests\TestCase;

/**
 * Telemetria de jobs en segundo plano (Fase 10).
 */
class JobTelemetryTest extends TestCase
{
    use RefreshDatabase;
    use RequiresPostgres;

    /**
     * `migrate:fresh` borra tablas pero NO los tipos ENUM nativos de
     * PostgreSQL, y el esquema del POS define varios. Sin esto, la segunda
     * prueba de la suite muere con "type ... already exists".
     */
    protected $dropTypes = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessPostgresAvailable();
    }

    /**
     * Regresion critica: Laravel auto-descubre en `app/Listeners` todo metodo
     * que empiece por `handle`. Si el suscriptor los nombrara asi, quedarian
     * registrados dos veces y CADA job escribiria su telemetria por duplicado.
     */
    public function test_los_listeners_de_cola_se_registran_una_sola_vez(): void
    {
        $dispatcher = app('events');
        $reflection = new \ReflectionClass($dispatcher);
        $property = $reflection->getProperty('listeners');
        $property->setAccessible(true);
        $listeners = $property->getValue($dispatcher);

        $mine = collect($listeners[JobQueued::class] ?? [])
            ->filter(fn ($l) => is_array($l) && ($l[0] ?? null) === JobTelemetrySubscriber::class);

        $this->assertCount(1, $mine, 'El suscriptor de telemetria quedo registrado mas de una vez.');
    }

    public function test_encolar_un_job_crea_una_fila_pendiente_con_el_disparador(): void
    {
        $user = $this->admin();
        $this->actingAs($user);

        $uuid = (string) Str::uuid();
        $this->fireQueued($uuid, 'App\Jobs\Demo');

        $log = JobExecutionLog::where('job_uuid', $uuid)->firstOrFail();

        $this->assertSame(JobExecutionLog::STATUS_PENDING, $log->status);
        $this->assertSame(JobExecutionLog::ATTEMPT_QUEUED, $log->attempt);
        $this->assertSame('App\Jobs\Demo', $log->job_name);
        $this->assertSame($user->id, $log->triggered_by);
        $this->assertSame('user', $log->trigger_source);
    }

    public function test_el_ciclo_completo_pasa_de_pendiente_a_exito_con_duracion(): void
    {
        $uuid = (string) Str::uuid();

        $this->fireQueued($uuid, 'App\Jobs\Demo');
        $this->fireProcessing($uuid, 'App\Jobs\Demo', 1);
        $this->fireProcessed($uuid, 'App\Jobs\Demo', 1);

        $log = JobExecutionLog::where('job_uuid', $uuid)->firstOrFail();

        $this->assertSame(JobExecutionLog::STATUS_SUCCESS, $log->status);
        $this->assertSame(1, $log->attempt);
        $this->assertNotNull($log->duration_ms);

        // Regresion: la duracion se medía restando `started_at` releido de una
        // columna timestamptz, lo que introducia un sesgo de 6 horas exactas
        // (21_600_000 ms) por la zona horaria de la aplicacion.
        $this->assertLessThan(60_000, $log->duration_ms, 'La duracion trae el sesgo de zona horaria.');

        // La fila de encolado se REUTILIZA: un job ejecutado una vez deja una
        // sola fila, no una pendiente huerfana mas otra de ejecucion.
        $this->assertSame(1, JobExecutionLog::where('job_uuid', $uuid)->count());
    }

    public function test_un_fallo_guarda_clase_mensaje_y_traza(): void
    {
        $uuid = (string) Str::uuid();

        $this->fireQueued($uuid, 'App\Jobs\Demo');
        $this->fireProcessing($uuid, 'App\Jobs\Demo', 1);
        $this->fireFailed($uuid, 'App\Jobs\Demo', 1, new RuntimeException('estallo el job'));

        $log = JobExecutionLog::where('job_uuid', $uuid)->firstOrFail();

        $this->assertSame(JobExecutionLog::STATUS_FAILED, $log->status);
        $this->assertSame(RuntimeException::class, $log->exception_class);
        $this->assertSame('estallo el job', $log->exception_message);
        $this->assertNotEmpty($log->exception_trace);
    }

    public function test_cada_reintento_estrena_su_propia_fila(): void
    {
        $uuid = (string) Str::uuid();

        $this->fireQueued($uuid, 'App\Jobs\Demo');

        $this->fireProcessing($uuid, 'App\Jobs\Demo', 1);
        $this->fireFailed($uuid, 'App\Jobs\Demo', 1, new RuntimeException('primer fallo'));

        $this->fireProcessing($uuid, 'App\Jobs\Demo', 2);
        $this->fireProcessed($uuid, 'App\Jobs\Demo', 2);

        $rows = JobExecutionLog::where('job_uuid', $uuid)->orderBy('attempt')->get();

        $this->assertCount(2, $rows, 'El historico debe conservar por que fallo cada intento.');
        $this->assertSame(JobExecutionLog::STATUS_FAILED, $rows[0]->status);
        $this->assertSame(JobExecutionLog::STATUS_SUCCESS, $rows[1]->status);
    }

    public function test_la_traza_se_recorta_al_limite_configurado(): void
    {
        config(['telemetry.jobs.max_trace_length' => 200]);

        $uuid = (string) Str::uuid();
        $this->fireProcessing($uuid, 'App\Jobs\Demo', 1);
        $this->fireFailed($uuid, 'App\Jobs\Demo', 1, new RuntimeException('x'));

        $log = JobExecutionLog::where('job_uuid', $uuid)->firstOrFail();

        $this->assertLessThanOrEqual(240, strlen((string) $log->exception_trace));
    }

    public function test_los_jobs_de_la_lista_de_exclusion_no_se_registran(): void
    {
        config(['telemetry.jobs.ignore' => ['App\Jobs\Ruidoso']]);

        $uuid = (string) Str::uuid();
        $this->fireQueued($uuid, 'App\Jobs\Ruidoso');

        $this->assertSame(0, JobExecutionLog::where('job_uuid', $uuid)->count());
    }

    // -----------------------------------------------------------------
    // Endpoints
    // -----------------------------------------------------------------

    public function test_el_catalogo_agrega_por_job_y_es_admin_only(): void
    {
        JobExecutionLog::create([
            'job_uuid' => (string) Str::uuid(),
            'job_name' => 'App\Jobs\Alpha', 'attempt' => 1,
            'status' => JobExecutionLog::STATUS_SUCCESS, 'duration_ms' => 120,
        ]);
        JobExecutionLog::create([
            'job_uuid' => (string) Str::uuid(),
            'job_name' => 'App\Jobs\Alpha', 'attempt' => 1,
            'status' => JobExecutionLog::STATUS_FAILED,
        ]);

        $response = $this->actingAs($this->admin())->getJson('/api/admin/jobs');

        $response->assertOk();
        $response->assertJsonPath('data.jobs.0.job_name', 'App\Jobs\Alpha');
        $response->assertJsonPath('data.jobs.0.total_runs', 2);
        $response->assertJsonPath('data.jobs.0.success_rate', 50);
        $response->assertJsonPath('data.summary.failed_24h', 1);

        // Un vendor no puede ver trazas internas del sistema.
        $this->actingAs($this->user('vendor'))->getJson('/api/admin/jobs')->assertForbidden();
    }

    public function test_el_historico_filtra_por_estatus_y_nombre(): void
    {
        JobExecutionLog::create([
            'job_uuid' => (string) Str::uuid(),
            'job_name' => 'App\Jobs\Alpha', 'attempt' => 1, 'status' => JobExecutionLog::STATUS_SUCCESS,
        ]);
        JobExecutionLog::create([
            'job_uuid' => (string) Str::uuid(),
            'job_name' => 'App\Jobs\Beta', 'attempt' => 1, 'status' => JobExecutionLog::STATUS_FAILED,
        ]);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->getJson('/api/admin/jobs/history?status=failed')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.job_name', 'App\Jobs\Beta');

        $this->actingAs($admin)
            ->getJson('/api/admin/jobs/history?job_name=App\Jobs\Alpha')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
    }

    public function test_no_se_reintenta_un_job_que_no_fallo(): void
    {
        $log = JobExecutionLog::create([
            'job_uuid' => (string) Str::uuid(),
            'job_name' => 'App\Jobs\Alpha', 'attempt' => 1, 'status' => JobExecutionLog::STATUS_SUCCESS,
        ]);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/jobs/{$log->id}/retry")
            ->assertStatus(422)
            ->assertJsonPath('code', 'ERR_JOB_NOT_FAILED');
    }

    public function test_no_se_reintenta_si_el_payload_ya_no_esta_en_failed_jobs(): void
    {
        $log = JobExecutionLog::create([
            'job_uuid' => (string) Str::uuid(),
            'job_name' => 'App\Jobs\Alpha', 'attempt' => 1, 'status' => JobExecutionLog::STATUS_FAILED,
        ]);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/jobs/{$log->id}/retry")
            ->assertStatus(410)
            ->assertJsonPath('code', 'ERR_JOB_PAYLOAD_UNAVAILABLE');
    }

    public function test_reintenta_un_job_fallido_con_payload_disponible(): void
    {
        // El payload debe ser REAL: `queue:retry` deserializa el comando y
        // rechaza cualquier cosa que no haya salido del serializador de la
        // cola. Se genera despachando de verdad y moviendo la fila a fallidos,
        // que es exactamente el recorrido de un job que revienta en produccion.
        config(['queue.default' => 'database']);

        CreateDatabaseBackup::dispatch(null, 'prueba-reintento');

        $queued = DB::table('jobs')->firstOrFail();
        $payload = $queued->payload;
        $uuid = json_decode($payload, true)['uuid'];

        DB::table('jobs')->delete();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => $payload,
            'exception' => 'RuntimeException: boom',
            'failed_at' => now(),
        ]);

        // La telemetria ya creo su fila `pending` al despachar; el fallo la
        // marca como fallida, igual que haria el worker.
        JobExecutionLog::where('job_uuid', $uuid)->update([
            'status' => JobExecutionLog::STATUS_FAILED,
            'attempt' => 1,
        ]);

        $log = JobExecutionLog::where('job_uuid', $uuid)->firstOrFail();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/jobs/{$log->id}/retry")
            ->assertOk()
            ->assertJsonPath('code', 'JOB_RETRY_QUEUED');

        // queue:retry consume la fila de fallidos y reencola el trabajo.
        $this->assertSame(0, DB::table('failed_jobs')->where('uuid', $uuid)->count());
        $this->assertSame(1, DB::table('jobs')->count());
    }

    // -----------------------------------------------------------------
    // Emisores de eventos de cola
    // -----------------------------------------------------------------

    private function fireQueued(string $uuid, string $jobName): void
    {
        event(new JobQueued(
            connectionName: 'database',
            queue: 'default',
            id: 1,
            job: $jobName,
            payload: json_encode(['uuid' => $uuid, 'displayName' => $jobName]),
            delay: null,
        ));
    }

    private function fireProcessing(string $uuid, string $jobName, int $attempt): void
    {
        event(new JobProcessing('database', $this->fakeJob($uuid, $jobName, $attempt)));
    }

    private function fireProcessed(string $uuid, string $jobName, int $attempt): void
    {
        event(new JobProcessed('database', $this->fakeJob($uuid, $jobName, $attempt)));
    }

    private function fireFailed(string $uuid, string $jobName, int $attempt, \Throwable $e): void
    {
        event(new JobFailed('database', $this->fakeJob($uuid, $jobName, $attempt), $e));
    }

    /**
     * Doble de `Illuminate\Contracts\Queue\Job` con lo que consume el
     * suscriptor. Evita levantar un worker real en cada prueba.
     */
    private function fakeJob(string $uuid, string $jobName, int $attempt): object
    {
        return new class($uuid, $jobName, $attempt)
        {
            public function __construct(
                private string $uuid,
                private string $name,
                private int $attempt,
            ) {}

            public function uuid(): string
            {
                return $this->uuid;
            }

            public function resolveName(): string
            {
                return $this->name;
            }

            public function attempts(): int
            {
                return $this->attempt;
            }

            public function getQueue(): string
            {
                return 'default';
            }

            public function isReleased(): bool
            {
                return false;
            }

            /** Lo consultan los listeners internos de la cola de Laravel. */
            public function payload(): array
            {
                return ['uuid' => $this->uuid, 'displayName' => $this->name];
            }

            public function getJobId(): string
            {
                return $this->uuid;
            }

            public function hasFailed(): bool
            {
                return false;
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getConnectionName(): string
            {
                return 'database';
            }
        };
    }

    private function admin(): User
    {
        return $this->user('admin');
    }

    private function user(string $role): User
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
