<?php

namespace Tests\Feature\Mailing;

use App\Models\EmailConfiguration;
use App\Services\Mail\DynamicMailerFactory;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Precedence of the mail configuration: database > config/mailing.php > .env.
 *
 * These tests exist because of an incident where `.env.production` pointed at a
 * real relay and every message still went to 127.0.0.1. The cause was not the
 * .env: DynamicMailerFactory ran Config::set() unconditionally, and the generic
 * provider skeleton carried a literal loopback fallback that outranked
 * MAIL_HOST on every send. What is pinned here is the rule that came out of it
 * — a value the database does not have must never overwrite one the server
 * does.
 *
 * No database is touched: the model is a carrier for the payload and never
 * reaches a table, which is the same trick the connection diagnostic uses.
 */
class MailConfigurationPrecedenceTest extends TestCase
{
    /** Endpoint the .env is pretending to declare in every case below. */
    private const ENV_HOST = 'smtp.resend.com';

    protected function setUp(): void
    {
        parent::setUp();

        // config/mail.php as a production .env would leave it.
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => [
                'transport' => 'smtp',
                'scheme' => null,
                'url' => null,
                'host' => self::ENV_HOST,
                'port' => 2525,
                'username' => 'resend',
                'password' => 'env-password',
                'encryption' => 'tls',
                'timeout' => null,
                'local_domain' => 'cronos.pos',
            ],
        ]);
    }

    private function factory(): DynamicMailerFactory
    {
        return app(DynamicMailerFactory::class);
    }

    private function configuration(array $overrides = []): EmailConfiguration
    {
        return new EmailConfiguration(array_merge([
            'process_type' => EmailConfiguration::PROCESS_JOBS,
            'provider' => 'smtp',
            'from_email' => 'no-reply@cronos.pos',
            'from_name' => 'Cronos POS',
            'target_emails' => ['soporte@cronos.pos'],
            'subject' => 'Aviso',
            'is_active' => true,
        ], $overrides));
    }

    /** Empties the generic skeleton, the state of a server without DYNAMIC_SMTP_*. */
    private function withoutGenericSkeletonEndpoint(): void
    {
        config([
            'mailing.providers.smtp.host' => null,
            'mailing.providers.smtp.port' => null,
            'mailing.providers.smtp.encryption' => null,
            'mailing.providers.smtp.username' => null,
            'mailing.providers.smtp.timeout' => null,
        ]);
    }

    public function test_sin_datos_en_la_base_el_transporte_no_se_sobrescribe(): void
    {
        $this->withoutGenericSkeletonEndpoint();

        $mailerName = $this->factory()->register($this->configuration());

        // The .env's own mailer, not a run-time one: this is the whole fix.
        $this->assertSame('smtp', $mailerName);
        $this->assertNull(config('mail.mailers.dynamic-jobs'));

        // And the native block came out of the call untouched.
        $this->assertSame(self::ENV_HOST, config('mail.mailers.smtp.host'));
        $this->assertSame(2525, config('mail.mailers.smtp.port'));
    }

    public function test_una_credencial_guardada_no_arrastra_al_host_a_loopback(): void
    {
        $this->withoutGenericSkeletonEndpoint();

        $mailerName = $this->factory()->register($this->configuration([
            'api_key' => 'clave-de-la-base',
        ]));

        $this->assertSame('dynamic-jobs', $mailerName);

        $transport = config("mail.mailers.{$mailerName}");

        // The row owns the credential, the .env still owns the endpoint.
        $this->assertSame('clave-de-la-base', $transport['password']);
        $this->assertSame(self::ENV_HOST, $transport['host']);
        $this->assertSame(2525, $transport['port']);
        $this->assertNotSame('127.0.0.1', $transport['host']);

        // MAIL_USERNAME is a real login and outranks the sender identity.
        $this->assertSame('resend', $transport['username']);
    }

    public function test_la_plantilla_generica_gana_sobre_el_env_cuando_esta_declarada(): void
    {
        $this->withoutGenericSkeletonEndpoint();

        config([
            'mailing.providers.smtp.host' => 'relay.interno.local',
            'mailing.providers.smtp.port' => '2525',
        ]);

        $transport = config('mail.mailers.'.$this->factory()->register($this->configuration()));

        $this->assertSame('relay.interno.local', $transport['host']);
        // Skeletons read env() strings; the transport needs a real integer.
        $this->assertSame(2525, $transport['port']);
        // Not declared by the skeleton, so it keeps coming from the .env.
        $this->assertSame('tls', $transport['encryption']);
    }

    public function test_sendgrid_conserva_su_endpoint_y_no_hereda_el_host_del_env(): void
    {
        $transport = config('mail.mailers.'.$this->factory()->register($this->configuration([
            'provider' => 'sendgrid',
            'api_key' => 'SG.clave-0001',
        ])));

        // Picking SendGrid is an explicit request for its relay, so the .env's
        // host must not bleed into it.
        $this->assertSame('smtp.sendgrid.net', $transport['host']);
        $this->assertSame(2525, $transport['port']);
        $this->assertSame('apikey', $transport['username']);
        $this->assertSame('SG.clave-0001', $transport['password']);
        $this->assertNotSame(self::ENV_HOST, $transport['host']);
    }

    public function test_un_mail_url_del_env_no_desvia_al_proveedor_elegido(): void
    {
        /*
         * Symfony builds its DSN from url/scheme before host and port, so
         * inheriting them from config/mail.php would quietly send SendGrid
         * traffic to the native relay while every printed value still read
         * smtp.sendgrid.net.
         */
        config(['mail.mailers.smtp.url' => 'smtp://user:pass@'.self::ENV_HOST.':2525']);

        $transport = config('mail.mailers.'.$this->factory()->register($this->configuration([
            'provider' => 'sendgrid',
            'api_key' => 'SG.clave-0001',
        ])));

        $this->assertArrayNotHasKey('url', $transport);
        $this->assertSame('smtp.sendgrid.net', $transport['host']);
    }

    public function test_el_esqueleto_generico_no_declara_un_endpoint_literal(): void
    {
        /*
         * Regression guard on the file itself. The bug was a default argument
         * — env('DYNAMIC_SMTP_HOST', '127.0.0.1') — and it comes back the
         * moment somebody "fixes" the null by filling it in, which is exactly
         * how it looks like an improvement in a diff.
         */
        $skeleton = require config_path('mailing.php');

        $this->assertNull($skeleton['providers']['smtp']['host']);
        $this->assertNull($skeleton['providers']['smtp']['port']);
        $this->assertSame('smtp', $skeleton['providers']['smtp']['inherits']);
    }

    public function test_el_comando_de_diagnostico_imprime_el_host_y_el_puerto_vigentes(): void
    {
        $exitCode = Artisan::call('app:debug-mail-config', ['--no-database' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(self::ENV_HOST, $output);
        $this->assertStringContainsString("config('mail.mailers.smtp.host')", $output);
        $this->assertStringContainsString("config('mail.mailers.smtp.port')", $output);

        // The output travels in support threads: no credential may ride along.
        $this->assertStringNotContainsString('env-password', $output);
    }
}
