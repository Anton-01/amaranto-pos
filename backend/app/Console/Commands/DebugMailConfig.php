<?php

namespace App\Console\Commands;

use App\Models\EmailConfiguration;
use App\Services\Mail\MailStrategyFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Prints the mail configuration Laravel is holding in memory, right now.
 *
 * The file on disk is not evidence. Between `.env.production` and the value a
 * worker reads there are three places the truth can change: `config:cache`
 * freezes a snapshot and stops the .env from being read at all, a container
 * that was not restarted keeps the previous snapshot, and
 * App\Services\Mail\DynamicMailerFactory may override the endpoint from the
 * database. Every one of those failures looks identical from the outside — the
 * .env says smtp.resend.com and the connection goes to 127.0.0.1 — so this
 * command answers the only question that matters: what does the framework
 * actually see?
 *
 * It reports three layers separately, in the order they take effect:
 *
 *   1. The .env's own mailer (config/mail.php), plus whether the config is
 *      cached, which decides if that file was even read this boot.
 *   2. The raw MAIL_* variables of the environment, compared against the
 *      loaded configuration to expose a stale cache.
 *   3. What each stored configuration would resolve to, so an unexpected
 *      override is visible before waiting for the 21:00 closing to fail.
 *
 * The command is read-only: it never sends and never writes.
 */
class DebugMailConfig extends Command
{
    protected $signature = 'app:debug-mail-config
                            {--process= : Inspeccionar solo este process_type}
                            {--no-database : Omitir las configuraciones almacenadas}';

    protected $description = 'Imprime la configuracion de correo que Laravel tiene cargada en tiempo de ejecucion';

    /** Environment variables that feed config/mail.php. */
    private const NATIVE_ENV_KEYS = [
        'MAIL_MAILER',
        'MAIL_HOST',
        'MAIL_PORT',
        'MAIL_USERNAME',
        'MAIL_PASSWORD',
        'MAIL_ENCRYPTION',
        'MAIL_SCHEME',
        'MAIL_URL',
        'MAIL_FROM_ADDRESS',
    ];

    /** Environment variables that feed the skeletons of config/mailing.php. */
    private const DYNAMIC_ENV_KEYS = [
        'DYNAMIC_SMTP_HOST',
        'DYNAMIC_SMTP_PORT',
        'DYNAMIC_SMTP_ENCRYPTION',
        'DYNAMIC_SMTP_USERNAME',
        'DYNAMIC_SMTP_TIMEOUT',
        'SENDGRID_SMTP_HOST',
        'SENDGRID_SMTP_PORT',
        'RESEND_API_ENDPOINT',
        'MAIL_TEST_TIMEOUT',
        'MAIL_SEND_TIMEOUT',
        'MAIL_CONNECT_TIMEOUT',
    ];

    public function handle(MailStrategyFactory $strategies): int
    {
        $this->runtimeSection();
        $this->nativeMailerSection();
        $this->environmentSection();
        $this->timeoutSection();

        if (! $this->option('no-database')) {
            $this->storedConfigurationsSection($strategies);
        }

        $this->newLine();
        $this->line('  Prioridad efectiva:  base de datos  >  config/mailing.php  >  .env');
        $this->newLine();

        return self::SUCCESS;
    }

    /** Where this process took its configuration from. */
    private function runtimeSection(): void
    {
        $cached = $this->laravel->configurationIsCached();

        $this->heading('Entorno de ejecucion');

        $this->table(['Dato', 'Valor'], [
            ['APP_ENV', (string) config('app.env')],
            ['Configuracion cacheada', $cached ? 'SI (bootstrap/cache/config.php)' : 'no'],
            ['Archivo .env leido en este arranque', $cached ? 'NO' : 'si'],
        ]);

        if ($cached) {
            /*
             * With a cached config Laravel skips loading the .env entirely, so
             * editing the file changes nothing until the cache is rebuilt. This
             * is the single most common cause of "the .env is right and the
             * application ignores it", and it also means the env() readings
             * further down come back empty — they are not proof of an empty
             * variable.
             */
            $this->warn('  La configuracion esta cacheada: el .env NO se lee en este arranque.');
            $this->line('  Tras editar el .env hay que reconstruir el cache y reiniciar los workers:');
            $this->line('      php artisan config:cache && php artisan queue:restart');
        }
    }

    /** Layer 1: the mailer config/mail.php built out of the .env. */
    private function nativeMailerSection(): void
    {
        $default = (string) config('mail.default');

        $this->heading('Mailer nativo (config/mail.php)');

        // Printed as its own line because it is the literal pair the incident
        // is argued over: what Laravel answers for host and port.
        $this->line("  config('mail.mailers.smtp.host')  =  ".$this->render(config('mail.mailers.smtp.host')));
        $this->line("  config('mail.mailers.smtp.port')  =  ".$this->render(config('mail.mailers.smtp.port')));
        $this->newLine();

        $rows = [
            ["config('mail.default')", $this->render($default)],
            ['from.address', $this->render(config('mail.from.address'))],
        ];

        foreach (['transport', 'scheme', 'url', 'host', 'port', 'username', 'password', 'encryption', 'timeout', 'local_domain'] as $key) {
            $rows[] = ["mailers.smtp.{$key}", $this->render(config("mail.mailers.smtp.{$key}"), $key)];
        }

        $this->table(['Clave', 'Valor cargado'], $rows);

        if ($default !== 'smtp' && is_array(config("mail.mailers.{$default}"))) {
            // A default pointing elsewhere means the smtp block above is not
            // the route messages take, however correct it looks.
            $this->warn("  El mailer por omision es '{$default}', no 'smtp': el bloque anterior no es la ruta de salida.");
        }
    }

    /** Layer 2: raw variables, to catch a snapshot that drifted from the file. */
    private function environmentSection(): void
    {
        $this->heading('Variables de entorno del proceso');

        $rows = [];

        foreach (self::NATIVE_ENV_KEYS as $key) {
            $rows[] = [$key, $this->render(env($key), $key)];
        }

        foreach (self::DYNAMIC_ENV_KEYS as $key) {
            $rows[] = [$key, $this->render(env($key), $key)];
        }

        $this->table(['Variable', 'Valor'], $rows);

        $envHost = env('MAIL_HOST');
        $configHost = config('mail.mailers.smtp.host');

        if (filled($envHost) && $envHost !== $configHost) {
            /*
             * The variable and the loaded configuration disagree, which can
             * only happen when something between them rewrote the value: a
             * stale config cache, or a config/mail.php that stopped reading
             * the variable.
             */
            $this->warn('  MAIL_HOST y la configuracion cargada NO coinciden:');
            $this->line("      MAIL_HOST (entorno) = {$envHost}");
            $this->line('      config(...smtp.host) = '.$this->render($configHost));
        }
    }

    /**
     * The network budget every strategy is bound by.
     *
     * Printed next to the transport because it answers the other half of a
     * hang: not "where does the mail go" but "how long can it block before the
     * request comes back with an error". A test timeout larger than the HTTP
     * server's own limit means the browser gives up first and the
     * administrator sees a dead tab instead of a message.
     */
    private function timeoutSection(): void
    {
        $this->heading('Limites de red por estrategia (config/mailing.php)');

        $this->table(['Fase', 'Segundos', 'Aplica a'], [
            ['test', (string) config('mailing.timeouts.test'), 'Prueba de conexion sincrona (boton "Probar Conexion")'],
            ['send', (string) config('mailing.timeouts.send'), 'Envio real desde el worker de la cola'],
            ['connect', (string) config('mailing.timeouts.connect'), 'Handshake TCP/TLS de las estrategias HTTP (Resend)'],
        ]);

        $this->line('  default_socket_timeout (php.ini) = '.$this->render(ini_get('default_socket_timeout')));
        $this->line('  Las estrategias SMTP lo acotan durante el envio y lo restauran al terminar,');
        $this->line('  por eso un puerto bloqueado ya no congela la peticion 60 segundos.');
    }

    /** Layer 3: what the stored rows would do to the transport. */
    private function storedConfigurationsSection(MailStrategyFactory $strategies): void
    {
        $this->heading('Configuraciones almacenadas (email_configurations)');

        try {
            $query = EmailConfiguration::query()->orderBy('process_type');

            if (filled($process = $this->option('process'))) {
                $query->where('process_type', $process);
            }

            $configurations = $query->get();
        } catch (Throwable $e) {
            // A debugging tool must not die on the layer it is not describing:
            // the two sections above are still the answer when the database is
            // unreachable.
            $this->warn('  No se pudo leer la tabla: '.$e->getMessage());

            return;
        }

        if ($configurations->isEmpty()) {
            $this->line('  Sin filas: todo el correo sale por el mailer nativo del .env.');

            return;
        }

        $rows = [];

        foreach ($configurations as $configuration) {
            try {
                /*
                 * describeTransport() is read-only by contract, so the whole
                 * table can be printed without injecting a single mailer into
                 * config('mail.mailers') — a debugging command that mutates the
                 * configuration it describes is a command that lies.
                 */
                $strategy = $strategies->for($configuration);
                $route = $strategy->describeTransport($configuration->toStrategyConfig());
            } catch (Throwable $e) {
                $rows[] = [
                    $configuration->process_type,
                    $configuration->provider,
                    $configuration->is_active ? 'activa' : 'inactiva',
                    'ERROR',
                    'ERROR',
                    $e->getMessage(),
                ];

                continue;
            }

            $mailerName = (string) ($route['mailer'] ?? '?');
            $isDynamic = str_starts_with($mailerName, (string) config('mailing.runtime_mailer_prefix', 'dynamic').'-');

            $rows[] = [
                $configuration->process_type,
                $configuration->provider,
                $configuration->is_active ? 'activa' : 'inactiva',
                class_basename($strategy),
                sprintf(
                    '%s → %s:%s',
                    strtoupper((string) ($route['channel'] ?? '?')),
                    $route['host'] ?? '(sin host)',
                    $route['port'] ?? '?'
                ),
                match (true) {
                    ($route['channel'] ?? null) === 'https' => 'API HTTP: no abre puertos SMTP',
                    $isDynamic => 'transporte inyectado desde la BD',
                    default => 'sin datos que sobrescribir: usa el .env',
                },
            ];
        }

        $this->table(
            ['Proceso', 'Proveedor', 'Estado', 'Estrategia', 'Ruta de salida', 'Origen del transporte'],
            $rows
        );

        $this->line('  Para validar credenciales de verdad usa el boton "Probar Conexion": envia un');
        $this->line('  correo real con limite de tiempo y devuelve el error exacto del proveedor.');
    }

    /** Section title, so a long output stays readable in a terminal. */
    private function heading(string $title): void
    {
        $this->newLine();
        $this->line('<fg=cyan>'.str_repeat('─', 72).'</>');
        $this->line("<fg=cyan> {$title}</>");
        $this->line('<fg=cyan>'.str_repeat('─', 72).'</>');
    }

    /**
     * Renders a value for the terminal, masking anything credential-shaped.
     *
     * The output of this command lands in support tickets and chat threads, so
     * passwords are reduced to their last four characters — enough to tell two
     * keys apart, useless to whoever reads the paste.
     */
    private function render(mixed $value, string $key = ''): string
    {
        if (blank($value)) {
            return '<fg=yellow>(vacio)</>';
        }

        if (str_contains(strtolower($key), 'password') || str_contains(strtolower($key), 'key')) {
            return '****'.substr((string) $value, -4);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return implode(', ', Arr::flatten($value));
        }

        return (string) $value;
    }
}
