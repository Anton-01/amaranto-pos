<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guardia estatica de la homologacion horaria de tres capas (CONTEXT.md 53).
 *
 * `MexicoTimezoneTest` prueba la ARITMETICA de los limites de dia; esta clase
 * prueba que las tres capas sigan APUNTANDO a la misma zona: si alguien quita
 * el `TZ` de un servicio del compose, revierte `config/app.php` a UTC o vuelve
 * a escribir la zona a mano en una consulta, la suite lo dice por su nombre en
 * lugar de esperar a que un corte de caja salga con la fecha corrida.
 *
 * No toca la base de datos: solo lee archivos del repositorio.
 */
class TimezoneAlignmentTest extends TestCase
{
    private const TZ = 'America/Mexico_City';

    /** Servicios que DEBEN declarar TZ, por archivo de compose. */
    private const REQUIRED_TZ_SERVICES = [
        // `postgres` solo existe en desarrollo: produccion usa la Managed
        // PostgreSQL de DigitalOcean, que se alinea por sesion (seccion 47).
        'docker-compose.yml' => ['backend', 'scheduler', 'queue-worker', 'postgres'],
        // En produccion los cuatro servicios PHP heredan el TZ del ancla YAML
        // `x-backend-service`.
        'docker-compose.prod.yml' => ['backend', 'reverb', 'scheduler', 'queue-worker'],
    ];

    private function repoPath(string $relative): string
    {
        return __DIR__ . '/../../../' . $relative;
    }

    // ---------------------------------------------------------------
    // Capa 2 — Laravel
    // ---------------------------------------------------------------

    public function test_app_timezone_defaults_to_mexico_city(): void
    {
        $config = require $this->repoPath('backend/config/app.php');

        $this->assertSame(
            self::TZ,
            $config['timezone'],
            'config/app.php debe resolver a ' . self::TZ . ' cuando APP_TIMEZONE no esta definida.'
        );
    }

    public function test_app_timezone_is_env_driven(): void
    {
        $source = file_get_contents($this->repoPath('backend/config/app.php'));

        $this->assertMatchesRegularExpression(
            "/'timezone'\s*=>\s*env\('APP_TIMEZONE',\s*'" . preg_quote(self::TZ, '/') . "'\)/",
            $source,
            'La zona debe leerse de APP_TIMEZONE con la zona del negocio como default.'
        );
    }

    public function test_database_session_inherits_the_app_timezone(): void
    {
        // La correccion de la seccion 47: sin esta linea, PostgreSQL interpreta
        // en UTC las cadenas sin offset que escribe Eloquent.
        $source = file_get_contents($this->repoPath('backend/config/database.php'));

        $this->assertStringContainsString(
            "'timezone' => env('DB_TIMEZONE') ?: config('app.timezone')",
            $source,
            'La conexion pgsql debe emitir SET TIME ZONE con la zona de la aplicacion.'
        );
    }

    public function test_env_examples_document_the_app_timezone(): void
    {
        foreach (['backend/.env.example', '.env.production.example'] as $file) {
            $this->assertStringContainsString(
                'APP_TIMEZONE=' . self::TZ,
                file_get_contents($this->repoPath($file)),
                "{$file} debe documentar APP_TIMEZONE."
            );
        }
    }

    // ---------------------------------------------------------------
    // Capa 1 — Docker
    // ---------------------------------------------------------------

    /**
     * El compose se lee como texto a proposito: no hay extension YAML
     * garantizada en el contenedor de PHP, y un parser propio para dos archivos
     * seria mas fragil que la comprobacion que reemplaza.
     */
    public function test_compose_services_declare_the_business_timezone(): void
    {
        foreach (self::REQUIRED_TZ_SERVICES as $file => $services) {
            $contents = file_get_contents($this->repoPath($file));

            $this->assertNotFalse($contents, "No se pudo leer {$file}.");

            foreach ($services as $service) {
                $this->assertTrue(
                    $this->serviceHasTimezone($contents, $service),
                    "El servicio '{$service}' de {$file} no declara TZ=" . self::TZ
                    . ' (ni lo hereda del ancla compartida): su reloj de SO quedaria en UTC.'
                );
            }
        }
    }

    public function test_production_php_services_share_a_single_tz_declaration(): void
    {
        $contents = file_get_contents($this->repoPath('docker-compose.prod.yml'));

        // El TZ vive en el ancla `x-backend-service`, antes de `services:`, de
        // modo que los cuatro contenedores PHP no puedan divergir.
        [$anchor] = explode("\nservices:", $contents, 2);

        $this->assertStringContainsString(
            'TZ: ' . self::TZ,
            $anchor,
            'El TZ de produccion debe declararse en el ancla compartida, no servicio por servicio.'
        );
    }

    public function test_development_postgres_server_runs_in_local_time(): void
    {
        // `TZ` no basta en el contenedor de PostgreSQL: el parametro `timezone`
        // del servidor lo escribe initdb la primera vez, asi que un volumen
        // preexistente seguiria en UTC sin este `-c`.
        $contents = file_get_contents($this->repoPath('docker-compose.yml'));

        $this->assertStringContainsString(
            '-c timezone=' . self::TZ,
            $contents,
            'El servidor PostgreSQL de desarrollo debe arrancar con timezone=' . self::TZ . '.'
        );
    }

    // ---------------------------------------------------------------
    // Capa 3 — Consultas
    // ---------------------------------------------------------------

    /**
     * Con `app.timezone` como unica fuente de verdad, volver a escribir la zona
     * a mano en una llamada a Carbon o en SQL crudo es exactamente el defecto
     * que reintroduce el desfase: el `.env` diria una cosa y la consulta haria
     * otra. `App\Support\Timezone` existe para los casos que si necesitan el
     * valor explicito.
     */
    public function test_no_hardcoded_timezone_in_date_calculations(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn($this->repoPath('backend/app')) as $file) {
            $source = file_get_contents($file);

            foreach ([
                // Carbon::now('America/...'), now('America/...'), Carbon::parse($x, 'America/...')
                // `[^;\n]*` y no `[^;]*`: sin el ancla de linea, un docblock que
                // mencione `Carbon::parse()` alcanzaba el literal de una
                // constante declarada mas abajo y lo reportaba como infraccion.
                '/\b(?:Carbon::)?(?:now|today|parse|createFromFormat)\s*\([^;\n]*[\'"]' . preg_quote(self::TZ, '/') . '[\'"]/',
                // ->timezone('America/...') / ->setTimezone('America/...')
                '/->set[Tt]imezone\s*\(\s*[\'"]' . preg_quote(self::TZ, '/') . '[\'"]/',
                '/->timezone\s*\(\s*[\'"]' . preg_quote(self::TZ, '/') . '[\'"]/',
                // AT TIME ZONE 'America/...' incrustado en SQL crudo
                '/AT TIME ZONE\s+[\'"]' . preg_quote(self::TZ, '/') . '[\'"]/',
            ] as $pattern) {
                if (preg_match($pattern, $source)) {
                    $offenders[] = str_replace($this->repoPath(''), '', $file);
                    break;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Zona horaria escrita a mano en calculos de fecha. Usa Carbon sin argumento de zona "
            . "(hereda app.timezone) o App\\Support\\Timezone en:\n  - "
            . implode("\n  - ", $offenders)
        );
    }

    public function test_timezone_helper_rejects_a_bogus_identifier(): void
    {
        // sqlLiteral() interpola en SQL crudo: la validacion es lo que impide
        // que un APP_TIMEZONE manipulado se convierta en inyeccion.
        $identifiers = \DateTimeZone::listIdentifiers();

        $this->assertContains(self::TZ, $identifiers);
        $this->assertNotContains("America/Mexico_City'; DROP TABLE orders; --", $identifiers);
    }

    /** @return list<string> */
    private function phpFilesIn(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Un servicio "declara TZ" si lo trae en su propio bloque `environment:` o
     * si lo hereda por `<<: *backend-service`.
     */
    private function serviceHasTimezone(string $compose, string $service): bool
    {
        $anchorDeclaresTz = str_contains(
            explode("\nservices:", $compose, 2)[0],
            'TZ: ' . self::TZ
        );

        // Bloque del servicio: desde su nombre indentado 2 espacios hasta el
        // siguiente servicio al mismo nivel.
        if (! preg_match(
            '/^  ' . preg_quote($service, '/') . ':\s*$(.*?)(?=^  \S|\z)/ms',
            $compose,
            $matches
        )) {
            return false;
        }

        $block = $matches[1];

        if (preg_match('/^\s*<<:\s*\*backend-service\s*$/m', $block) && $anchorDeclaresTz) {
            return true;
        }

        return (bool) preg_match(
            '/^\s*-?\s*TZ[:=]\s*' . preg_quote(self::TZ, '/') . '\s*$/m',
            $block
        );
    }
}
