<?php

namespace Tests\Feature\Database;

use App\Support\Database\PostgresEnum;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RequiresPostgres;
use Tests\TestCase;

/**
 * Blindaje contra el fallo que tumbaba el contenedor en el segundo arranque.
 *
 *     SQLSTATE[42710]: Duplicate object: type "discount_type" already exists
 *
 * `migrate:fresh` borra TABLAS, no tipos: los ENUM nativos de PostgreSQL
 * sobreviven y el `CREATE TYPE` de la segunda corrida choca contra el
 * superviviente. Estas pruebas ejecutan la suite de migraciones dos veces
 * seguidas —deliberadamente SIN `--drop-types`— y exigen que pase.
 *
 * NOTA: estas pruebas reconstruyen el esquema completo, asi que no usan
 * RefreshDatabase (seria redundante y mas lento). Dejan la base migrada y
 * limpia al terminar, que es el estado que cualquier otra prueba espera.
 */
class MigrationsAreRerunnableTest extends TestCase
{
    use RequiresPostgres;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessPostgresAvailable();
    }

    protected function tearDown(): void
    {
        // Se deja el esquema en pie para no arrastrar a las demas pruebas.
        Artisan::call('migrate:fresh', ['--force' => true, '--drop-types' => true]);

        parent::tearDown();
    }

    public function test_migrate_fresh_se_puede_repetir_sin_drop_types(): void
    {
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));

        // La segunda pasada es la que reventaba: los ENUM del primer ciclo
        // siguen vivos porque `migrate:fresh` no los borra.
        $exit = Artisan::call('migrate:fresh', ['--force' => true]);

        $this->assertSame(
            0,
            $exit,
            "La segunda corrida de migrate:fresh fallo:\n".Artisan::output()
        );

        // Y una tercera, por si algun tipo quedara a medio recrear.
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
    }

    public function test_migrate_fresh_con_drop_types_tambien_es_repetible(): void
    {
        // Es el comando exacto del entrypoint de Docker.
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--drop-types' => true]));
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true, '--drop-types' => true]));
    }

    /**
     * Ningun `up()` debe crear un tipo con SQL crudo: PostgresEnum es el unico
     * camino idempotente. Esta prueba es la que evita que la regresion vuelva
     * a entrar por una migracion nueva escrita al viejo estilo.
     */
    public function test_ninguna_migracion_crea_tipos_con_sql_crudo_en_up(): void
    {
        $ofensores = [];

        foreach (glob(database_path('migrations/*.php')) as $file) {
            $source = file_get_contents($file);

            // Solo interesa el cuerpo de up(): los down() recrean tipos de
            // forma legitima (renombrar, convertir columna, soltar el viejo).
            $up = $this->extractMethod($source, 'up');

            if ($up !== null && preg_match('/CREATE\s+TYPE/i', $up) === 1) {
                $ofensores[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $ofensores,
            'Estas migraciones crean un ENUM con SQL crudo en up(); usa '.
            'App\\Support\\Database\\PostgresEnum::create() o volveran a romper '.
            "el arranque en la segunda corrida:\n  - ".implode("\n  - ", $ofensores)
        );
    }

    public function test_todos_los_enums_del_esquema_quedan_creados(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        $esperados = [
            'user_status', 'promotion_type', 'stock_movement_type',
            'stock_movement_reason', 'petty_cash_reason', 'order_status',
            'discount_type', 'table_status', 'table_session_status',
            'job_execution_status',
        ];

        foreach ($esperados as $enum) {
            $this->assertTrue(PostgresEnum::exists($enum), "Falta el tipo '{$enum}'.");
        }

        // `open` lo agrega una migracion posterior con ALTER TYPE ADD VALUE.
        $valores = DB::select(
            "SELECT e.enumlabel AS v FROM pg_enum e JOIN pg_type t ON t.oid = e.enumtypid WHERE t.typname = 'order_status'"
        );

        $this->assertEqualsCanonicalizing(
            ['completed', 'canceled', 'open'],
            array_column($valores, 'v')
        );
    }

    /**
     * PostgresEnum::create() sobre un tipo EN USO no debe destruirlo: hacerlo
     * se llevaria por delante las columnas que dependen de el.
     */
    public function test_no_recrea_un_tipo_que_esta_en_uso(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);

        $this->assertTrue(PostgresEnum::isInUse('order_status'));

        // Invocarlo con valores distintos no debe alterar el tipo vivo.
        PostgresEnum::create('order_status', ['algo', 'totalmente', 'distinto']);

        $valores = DB::select(
            "SELECT e.enumlabel AS v FROM pg_enum e JOIN pg_type t ON t.oid = e.enumtypid WHERE t.typname = 'order_status'"
        );

        $this->assertContains('completed', array_column($valores, 'v'));
        $this->assertNotContains('algo', array_column($valores, 'v'));
    }

    /** Un tipo huerfano (sin columnas que lo usen) SI se recrea. */
    public function test_recrea_un_tipo_huerfano_para_respetar_la_migracion(): void
    {
        PostgresEnum::drop('prueba_enum_huerfano');
        PostgresEnum::create('prueba_enum_huerfano', ['a', 'b']);
        PostgresEnum::create('prueba_enum_huerfano', ['a', 'b', 'c']);

        $valores = DB::select(
            "SELECT e.enumlabel AS v FROM pg_enum e JOIN pg_type t ON t.oid = e.enumtypid WHERE t.typname = 'prueba_enum_huerfano'"
        );

        $this->assertEqualsCanonicalizing(['a', 'b', 'c'], array_column($valores, 'v'));

        PostgresEnum::drop('prueba_enum_huerfano');
    }

    /** Aisla el cuerpo de un metodo por conteo de llaves. */
    private function extractMethod(string $source, string $name): ?string
    {
        $needle = "function {$name}(";
        $start = strpos($source, $needle);

        if ($start === false) {
            return null;
        }

        $open = strpos($source, '{', $start);

        if ($open === false) {
            return null;
        }

        $depth = 0;

        for ($i = $open, $len = strlen($source); $i < $len; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }

        return null;
    }
}
