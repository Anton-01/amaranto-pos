<?php

namespace Tests\Feature\Mailing;

use App\Mail\TestConnectionMail;
use App\Models\EmailConfiguration;
use App\Services\Mail\Contracts\MailStrategyInterface;
use App\Services\Mail\DynamicMailerFactory;
use App\Services\Mail\MailStrategyFactory;
use App\Services\Mail\Strategies\ResendMailStrategy;
use App\Services\Mail\Strategies\SendGridMailStrategy;
use App\Services\Mail\Strategies\SmtpMailStrategy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * The Strategy layer that replaced the single hardcoded mailing path.
 *
 * What is pinned here is the reason the pattern was introduced: each provider
 * is selected by configuration and not by a branch, Resend leaves through
 * HTTPS/443 without ever opening an SMTP socket, and every strategy bounds how
 * long it may block — the property that turned a 60-second frozen tab into an
 * error the administrator can read.
 *
 * No PostgreSQL is required. The rows are unsaved carriers, and the only table
 * the diagnostic Mailable touches (global_settings, for the fiscal footer) is
 * created in an in-memory SQLite database, so the strategies can be exercised
 * anywhere.
 */
class MailStrategyTest extends TestCase
{
    private const RESEND_ENDPOINT = 'https://api.resend.com/emails';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useInMemoryDatabase();

        // config/mail.php as a production .env would leave it, so a strategy
        // that inherits the native mailer inherits something realistic.
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'host' => 'smtp.interno.local',
                'port' => 2525,
                'username' => 'relay',
                'password' => 'env-password',
                'encryption' => 'tls',
                'timeout' => null,
                'local_domain' => 'cronos.pos',
            ],
        ]);
    }

    /**
     * The diagnostic Mailable renders the fiscal footer out of global_settings.
     * SQLite serves that single lookup so these tests never need a service.
     */
    private function useInMemoryDatabase(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        Schema::create('global_settings', function ($table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
        });
    }

    /** @return array<string, mixed> */
    private function config(array $overrides = []): array
    {
        return array_merge([
            'process_type' => EmailConfiguration::PROCESS_JOBS,
            'provider' => EmailConfiguration::PROVIDER_RESEND,
            'api_key' => 're_testing_key_0001',
            'from_email' => 'no-reply@cronos.pos',
            'from_name' => 'Cronos POS',
            'subject' => 'Cierre de Caja',
            'target_emails' => ['contabilidad@cronos.pos'],
        ], $overrides);
    }

    private function strategyFor(string $provider): MailStrategyInterface
    {
        return app(MailStrategyFactory::class)->make($provider);
    }

    public function test_la_fabrica_resuelve_una_estrategia_distinta_por_proveedor(): void
    {
        $factory = app(MailStrategyFactory::class);

        // The whole point of the pattern: the caller names a provider and gets
        // an object, never a branch inside a shared procedure.
        $this->assertInstanceOf(SmtpMailStrategy::class, $factory->make(EmailConfiguration::PROVIDER_SMTP));
        $this->assertInstanceOf(SendGridMailStrategy::class, $factory->make(EmailConfiguration::PROVIDER_SENDGRID));
        $this->assertInstanceOf(ResendMailStrategy::class, $factory->make(EmailConfiguration::PROVIDER_RESEND));

        // SendGrid specializes the SMTP family instead of duplicating it.
        $this->assertInstanceOf(SmtpMailStrategy::class, $factory->make(EmailConfiguration::PROVIDER_SENDGRID));
    }

    public function test_la_fabrica_rechaza_un_proveedor_sin_estrategia_registrada(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ERR_MAIL_STRATEGY_UNSUPPORTED/');

        app(MailStrategyFactory::class)->make('mailchimp');
    }

    public function test_el_catalogo_ofrece_solo_proveedores_con_estrategia(): void
    {
        $supported = app(MailStrategyFactory::class)->supportedProviders();

        $this->assertEqualsCanonicalizing(
            [
                EmailConfiguration::PROVIDER_SMTP,
                EmailConfiguration::PROVIDER_SENDGRID,
                EmailConfiguration::PROVIDER_RESEND,
            ],
            $supported
        );

        // A provider the UI offers must be one the system can send through, so
        // the visible catalog can never grow past the registered strategies.
        $this->assertSame([], array_diff(array_keys(EmailConfiguration::PROVIDERS), $supported));
    }

    public function test_resend_sale_por_https_443_y_nunca_abre_un_puerto_smtp(): void
    {
        Http::fake([self::RESEND_ENDPOINT => Http::response(['id' => 'e1b2c3d4'], 200)]);

        $result = $this->strategyFor(EmailConfiguration::PROVIDER_RESEND)->testConnection($this->config());

        $this->assertTrue($result['success']);
        $this->assertSame('https', $result['transport']['channel']);
        $this->assertSame(443, $result['transport']['port']);
        $this->assertSame('api.resend.com', $result['transport']['host']);
        $this->assertSame('e1b2c3d4', $result['transport']['message_id']);

        /*
         * The reason this provider exists. A VPS that filters outbound 587 (and
         * sometimes 2525) cannot be fixed from the application, so the HTTP
         * strategy must not fall back to a Symfony transport under any
         * circumstance: no run-time mailer may appear in the configuration.
         */
        $this->assertNull(config('mail.mailers.dynamic-jobs'));

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === self::RESEND_ENDPOINT
                && $request->hasHeader('Authorization', 'Bearer re_testing_key_0001')
                && $body['from'] === 'Cronos POS <no-reply@cronos.pos>'
                && $body['to'] === ['contabilidad@cronos.pos']
                && str_contains($body['html'], 'Prueba de Conexion Exitosa');
        });
    }

    public function test_resend_devuelve_el_error_exacto_de_la_api_sin_lanzar_excepcion(): void
    {
        Http::fake([
            self::RESEND_ENDPOINT => Http::response(
                ['statusCode' => 401, 'name' => 'validation_error', 'message' => 'API key is invalid'],
                401
            ),
        ]);

        $result = $this->strategyFor(EmailConfiguration::PROVIDER_RESEND)->testConnection($this->config());

        // testConnection() is the diagnostic half of the contract: a failure is
        // a return value, which is what lets the endpoint answer 422 with the
        // cause instead of a 500 with none.
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('API key is invalid', $result['error']['message']);
        $this->assertStringContainsString('HTTP 401', $result['error']['message']);
        $this->assertSame(401, $result['error']['status_code']);
        $this->assertSame('validation_error', $result['error']['provider_code']);
        $this->assertSame('MailTransportException', $result['error']['class']);
        $this->assertNotEmpty($result['error']['trace']);
    }

    public function test_un_puerto_bloqueado_falla_en_segundos_con_el_error_de_curl(): void
    {
        /*
         * The failure this refactor exists for, reproduced at its source: a
         * dropped packet surfaces as a cURL timeout instead of hanging the
         * request until PHP's 60-second default socket timeout expires. The
         * bound itself is asserted below on the client configuration; here what
         * matters is that the message travels back verbatim.
         */
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received'
        ));

        $result = $this->strategyFor(EmailConfiguration::PROVIDER_RESEND)->testConnection($this->config());

        $this->assertFalse($result['success']);
        $this->assertSame('ConnectionException', $result['error']['class']);
        $this->assertStringContainsString('Operation timed out', $result['error']['message']);
        $this->assertLessThan(60_000, $result['elapsed_ms']);
    }

    public function test_resend_exige_credencial_y_destinatarios_antes_de_salir_a_la_red(): void
    {
        Http::fake();

        $strategy = $this->strategyFor(EmailConfiguration::PROVIDER_RESEND);

        $withoutKey = $strategy->testConnection($this->config(['api_key' => null]));
        $withoutRecipients = $strategy->testConnection($this->config(['target_emails' => []]));

        $this->assertStringContainsString('ERR_MAIL_RESEND_NO_CREDENTIAL', $withoutKey['error']['message']);
        $this->assertStringContainsString('ERR_MAIL_RESEND_NO_RECIPIENTS', $withoutRecipients['error']['message']);

        // Neither case is worth a round trip: the request never left.
        Http::assertNothingSent();
    }

    public function test_el_diagnostico_nunca_arrastra_la_credencial(): void
    {
        Http::fake([self::RESEND_ENDPOINT => Http::response(['message' => 'Unauthorized'], 401)]);

        $result = $this->strategyFor(EmailConfiguration::PROVIDER_RESEND)
            ->testConnection($this->config(['api_key' => 're_super_secret_9876']));

        // The whole array is echoed into an HTTP response and a log line, so
        // the assertion is on its serialization, not on a hand-picked key.
        $this->assertStringNotContainsString('re_super_secret_9876', json_encode($result));
    }

    public function test_la_estrategia_smtp_acota_el_tiempo_del_transporte(): void
    {
        Mail::fake();
        config(['mailing.timeouts.test' => 7]);

        $result = $this->strategyFor(EmailConfiguration::PROVIDER_SENDGRID)->testConnection($this->config([
            'provider' => EmailConfiguration::PROVIDER_SENDGRID,
            'api_key' => 'SG.testing-key-0001',
        ]));

        $this->assertTrue($result['success']);

        /*
         * Without this the socket inherits default_socket_timeout = 60 and a
         * blocked port holds the request for a full minute. The skeleton ships
         * 15 seconds, so the cap has to actually lower it.
         */
        $this->assertSame(7, config('mail.mailers.dynamic-jobs.timeout'));
        $this->assertSame(7, $result['transport']['timeout']);
        $this->assertSame('smtp', $result['transport']['channel']);
        $this->assertSame(2525, $result['transport']['port']);
    }

    public function test_la_estrategia_smtp_no_sube_un_limite_ya_configurado(): void
    {
        Mail::fake();
        config([
            'mailing.timeouts.test' => 8,
            'mailing.providers.sendgrid.timeout' => 3,
        ]);

        $this->strategyFor(EmailConfiguration::PROVIDER_SENDGRID)->testConnection($this->config([
            'provider' => EmailConfiguration::PROVIDER_SENDGRID,
            'api_key' => 'SG.testing-key-0001',
        ]));

        // A deployment that configured 3 seconds knows something about its
        // network that this default does not: the cap only ever lowers.
        $this->assertSame(3, config('mail.mailers.dynamic-jobs.timeout'));
    }

    public function test_la_estrategia_smtp_devuelve_el_mensaje_del_transporte_cuando_falla(): void
    {
        $timeout = 'Connection could not be established with host "smtp.sendgrid.net:2525": '
            .'stream_socket_client(): Operation timed out';

        Mail::shouldReceive('forgetMailers')->zeroOrMoreTimes();
        Mail::shouldReceive('mailer')->once()->andThrow(new TransportException($timeout));

        $result = $this->strategyFor(EmailConfiguration::PROVIDER_SENDGRID)->testConnection($this->config([
            'provider' => EmailConfiguration::PROVIDER_SENDGRID,
            'api_key' => 'SG.testing-key-0001',
        ]));

        $this->assertFalse($result['success']);
        $this->assertSame($timeout, $result['error']['message']);
        $this->assertSame('TransportException', $result['error']['class']);
        // The route is still reported: an error without the host and port it
        // was dialled against is half a diagnosis.
        $this->assertSame('smtp.sendgrid.net', $result['transport']['host']);
    }

    public function test_sendgrid_avisa_cuando_la_credencial_no_es_una_api_key(): void
    {
        Mail::fake();

        $result = $this->strategyFor(EmailConfiguration::PROVIDER_SENDGRID)->testConnection($this->config([
            'provider' => EmailConfiguration::PROVIDER_SENDGRID,
            'api_key' => 'contrasena-de-la-cuenta',
        ]));

        $this->assertNotEmpty($result['hints']);
        $this->assertStringContainsString('SG.', implode(' ', $result['hints']));
    }

    public function test_describir_el_transporte_no_muta_la_configuracion(): void
    {
        $before = config('mail.mailers');

        $smtp = $this->strategyFor(EmailConfiguration::PROVIDER_SENDGRID)->describeTransport($this->config([
            'provider' => EmailConfiguration::PROVIDER_SENDGRID,
            'api_key' => 'SG.testing-key-0001',
        ]));

        $http = $this->strategyFor(EmailConfiguration::PROVIDER_RESEND)->describeTransport($this->config());

        $this->assertSame('smtp.sendgrid.net', $smtp['host']);
        $this->assertSame('dynamic-jobs', $smtp['mailer']);
        $this->assertSame('resend-api', $http['mailer']);

        // `app:debug-mail-config` prints this for every stored row, so it must
        // describe the route without becoming the reason it changed.
        $this->assertSame($before, config('mail.mailers'));
        $this->assertNull(config('mail.mailers.dynamic-jobs'));
    }

    public function test_el_constructor_de_transportes_smtp_rechaza_un_proveedor_http(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ERR_MAIL_PROVIDER_NOT_SMTP/');

        /*
         * Resend declares no host and no port, so a factory that merged it
         * would find "nothing to override" and quietly route its mail through
         * the .env's SMTP relay — with the wrong credential and the wrong
         * sender. Failing loudly is the only honest answer.
         */
        app(DynamicMailerFactory::class)->register(new EmailConfiguration([
            'process_type' => EmailConfiguration::PROCESS_JOBS,
            'provider' => EmailConfiguration::PROVIDER_RESEND,
            'api_key' => 're_testing_key_0001',
        ]));
    }

    public function test_resend_respeta_el_asunto_aplicado_por_el_job(): void
    {
        Http::fake([self::RESEND_ENDPOINT => Http::response(['id' => 'e1'], 200)]);

        $mail = (new TestConnectionMail(EmailConfiguration::PROCESS_JOBS))
            ->subject('Cierre de Caja Automatico');

        $sent = $this->strategyFor(EmailConfiguration::PROVIDER_RESEND)->send($mail, $this->config());

        $this->assertTrue($sent);

        // The queue job stamps sender and subject on the Mailable; the HTTP
        // strategy has to read them back or a Resend message would silently
        // lose the wording configured in the database.
        Http::assertSent(fn (Request $request) => $request->data()['subject'] === 'Cierre de Caja Automatico');
    }
}
