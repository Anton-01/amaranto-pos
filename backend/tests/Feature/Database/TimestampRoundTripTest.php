<?php

namespace Tests\Feature\Database;

use App\Models\User;
use App\Models\UserSessionLog;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\RequiresPostgres;
use Tests\TestCase;

/**
 * El instante que escribe la aplicacion es el instante que lee de vuelta.
 *
 * EL DESFASE DE 6 HORAS
 *
 * Eloquent serializa los Carbon con formato `Y-m-d H:i:s`, SIN offset. El
 * Carbon vive en la zona de la aplicacion (America/Mexico_City), pero
 * PostgreSQL interpretaba esa cadena desnuda en la zona de su sesion —Etc/UTC
 * por defecto— y guardaba la hora local etiquetada como UTC:
 *
 *     Laravel now()   2026-08-06T12:34:04-06:00
 *     Guardado        2026-08-06 12:34:04+00     <- 6 h adelantado
 *
 * Afectaba a TODA columna `timestamptz`: ordenes, cierres de caja, auditoria,
 * sesiones de mesa, telemetria. El sintoma visible era una mesa recien abierta
 * anunciando "Abierta hace 6h 0m".
 *
 * La correccion alinea la sesion de PostgreSQL con la zona de la aplicacion
 * (`'timezone'` en la conexion pgsql de config/database.php).
 */
class TimestampRoundTripTest extends TestCase
{
    use DatabaseMigrations;
    use RequiresPostgres;

    protected $dropTypes = true;

    private function user(): User
    {
        return User::create([
            'name' => 'Prueba TZ',
            'email' => 'tz-'.uniqid().'@cronos.pos',
            'password' => bcrypt('secret'),
            'status' => 'active',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessPostgresAvailable();
    }

    public function test_la_sesion_de_postgres_comparte_zona_con_la_aplicacion(): void
    {
        $this->assertSame(
            config('app.timezone'),
            DB::selectOne('SHOW TIME ZONE')->TimeZone,
            'Sin esta alineacion, toda escritura timestamptz se desplaza por la diferencia de zonas.'
        );
    }

    public function test_un_timestamp_escrito_ahora_se_relee_como_ahora(): void
    {
        $log = UserSessionLog::create([
            'user_id' => $this->user()->id,
            'login_at' => now(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'prueba',
        ]);

        $releido = UserSessionLog::findOrFail($log->id)->login_at;

        // 21_600_000 ms era el desfase exacto del bug. Cinco segundos de holgura
        // cubren la latencia del test sin dejar pasar nada parecido a una hora.
        $this->assertLessThan(
            5,
            abs($releido->diffInSeconds(now())),
            'El instante releido no coincide con el escrito: la zona volvio a desalinearse.'
        );
    }

    public function test_postgres_almacena_el_offset_correcto_no_la_hora_local_como_utc(): void
    {
        $antes = now();

        $log = UserSessionLog::create([
            'user_id' => $this->user()->id,
            'login_at' => $antes,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'prueba',
        ]);

        $crudo = DB::selectOne(
            'SELECT login_at AT TIME ZONE \'UTC\' AS utc FROM user_sessions_log WHERE id = ?',
            [$log->id]
        )->utc;

        $this->assertLessThan(
            5,
            abs(Carbon::parse($crudo, 'UTC')->diffInSeconds($antes)),
            'PostgreSQL guardo la hora de pared local como si fuera UTC.'
        );
    }

    /**
     * Garantia para el histórico de produccion, que NO se migra: `timestamptz`
     * almacena un instante absoluto, de modo que cambiar la zona de la sesion
     * altera como se RENDERIZA, nunca el instante guardado.
     */
    public function test_las_filas_historicas_conservan_su_instante(): void
    {
        $id = (string) Str::uuid();
        $instante = '2026-08-05 20:00:00+00';

        DB::insert(
            'INSERT INTO user_sessions_log (id, user_id, login_at, ip_address, user_agent)
             VALUES (?, ?, ?::timestamptz, ?, ?)',
            [$id, $this->user()->id, $instante, '10.0.0.1', 'legacy']
        );

        $this->assertSame(
            0,
            (int) abs(UserSessionLog::findOrFail($id)->login_at->diffInSeconds(Carbon::parse($instante))),
            'Una fila preexistente cambio de instante al releerse.'
        );
    }

    /** Los rangos de fecha siguen capturando lo que se escribe hoy. */
    public function test_un_registro_de_hoy_cae_en_el_filtro_de_hoy(): void
    {
        $user = $this->user();

        UserSessionLog::create([
            'user_id' => $user->id,
            'login_at' => now(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'de-hoy',
        ]);

        $encontrados = UserSessionLog::where('user_agent', 'de-hoy')
            ->whereBetween('login_at', [
                now()->startOfDay()->toDateTimeString(),
                now()->endOfDay()->toDateTimeString(),
            ])
            ->count();

        $this->assertSame(1, $encontrados, 'Un registro de hoy quedo fuera del rango de hoy.');
    }
}
