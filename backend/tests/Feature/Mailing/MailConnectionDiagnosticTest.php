<?php

namespace Tests\Feature\Mailing;

use App\Mail\TestConnectionMail;
use App\Models\EmailConfiguration;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Concerns\RequiresPostgres;
use Tests\TestCase;

/**
 * Synchronous health check of the mailing configuration.
 *
 * These tests pin the three properties the tool exists for: it sends inside the
 * request (never through the queue, or the answer would be "enqueued" instead
 * of "delivered"), it validates credentials that are not in the database yet,
 * and it hands the provider's raw error back to the administrator.
 */
class MailConnectionDiagnosticTest extends TestCase
{
    use DatabaseMigrations;
    use RequiresPostgres;

    protected $dropTypes = true;

    private const ENDPOINT = '/api/admin/email-configurations/test-connection';

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

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'process_type' => EmailConfiguration::PROCESS_JOBS,
            'provider' => 'sendgrid',
            'api_key' => 'SG.testing-key-0001',
            'from_email' => 'no-reply@cronos.pos',
            'from_name' => 'Cronos POS',
            'target_emails' => ['soporte@cronos.pos'],
        ], $overrides);
    }

    public function test_la_prueba_envia_el_correo_dentro_de_la_peticion_y_no_por_la_cola(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->admin())
            ->postJson(self::ENDPOINT, $this->payload());

        $response->assertOk();
        $response->assertJsonPath('status', 'success');

        Mail::assertSent(TestConnectionMail::class, function (TestConnectionMail $mail) {
            return $mail->hasTo('soporte@cronos.pos')
                && $mail->processType === EmailConfiguration::PROCESS_JOBS;
        });

        /*
         * The point of the whole endpoint. A queued diagnostic would report
         * success the moment Redis accepted the payload, which says nothing
         * about SMTP: the message has to leave through the transport while the
         * administrator is still waiting on the response.
         */
        Mail::assertNothingQueued();
    }

    public function test_la_prueba_valida_credenciales_sin_persistirlas_y_sale_por_el_puerto_2525(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->admin())
            ->postJson(self::ENDPOINT, $this->payload());

        $response->assertOk();
        $response->assertJsonPath('data.transport.host', 'smtp.sendgrid.net');
        $response->assertJsonPath('data.transport.port', 2525);

        // The credential must never travel back to the browser, not even inside
        // the diagnostic block that describes the route it was dialled through.
        $response->assertJsonMissingPath('data.transport.password');
        $response->assertJsonMissing(['api_key' => 'SG.testing-key-0001']);

        // Validating "on the fly" means exactly this: a working transport was
        // assembled and used without a single row being written.
        $this->assertDatabaseCount('email_configurations', 0);
        $this->assertSame(2525, config('mail.mailers.dynamic-jobs.port'));
    }

    public function test_la_prueba_devuelve_el_mensaje_exacto_del_transporte_cuando_falla(): void
    {
        $timeout = 'Connection could not be established with host "smtp.sendgrid.net:2525": '
            .'stream_socket_client(): Operation timed out';

        // forgetMailers() is tolerated because the transport is injected and
        // its timeout capped right before the send, and both flush the cache.
        Mail::shouldReceive('forgetMailers')->zeroOrMoreTimes();
        Mail::shouldReceive('mailer')->once()->andThrow(new TransportException($timeout));

        $response = $this->actingAs($this->admin())
            ->postJson(self::ENDPOINT, $this->payload());

        /*
         * 422 and not 500: the request was well formed and the application did
         * exactly what it was asked. What failed is the configuration under
         * test, which is a fact about the payload — and it keeps a mistyped API
         * key out of the platform's 5xx alerting.
         */
        $response->assertStatus(422);
        $response->assertJsonPath('status', 'error');
        $response->assertJsonPath('error_code', 'ERR_MAIL_TEST_FAILED');
        $response->assertJsonPath('error_class', 'TransportException');

        /*
         * Verbatim, not paraphrased: this string is the entire deliverable of
         * the feature. "Operation timed out" against an open port points at the
         * host firewall, while a 401 on the same route points at the key — a
         * friendly "no se pudo conectar" would erase that distinction.
         */
        $response->assertJsonPath('message', $timeout);
        $response->assertJsonPath('error', $timeout);
        $response->assertJsonPath('data.error.message', $timeout);

        // The trace is what turns "it failed" into something an administrator
        // can paste into a support thread.
        $this->assertNotEmpty($response->json('data.error.trace'));
        $this->assertSame('smtp.sendgrid.net', $response->json('data.transport.host'));
    }

    public function test_la_prueba_de_resend_viaja_por_https_443_y_no_por_smtp(): void
    {
        Http::fake(['https://api.resend.com/emails' => Http::response(['id' => 'e1b2c3d4'], 200)]);

        $response = $this->actingAs($this->admin())->postJson(self::ENDPOINT, $this->payload([
            'provider' => EmailConfiguration::PROVIDER_RESEND,
            'api_key' => 're_testing_key_0001',
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.transport.channel', 'https');
        $response->assertJsonPath('data.transport.port', 443);
        $response->assertJsonPath('data.strategy', 'ResendMailStrategy');

        /*
         * The point of the provider: a host that filters outbound submission
         * ports cannot break this route, because no SMTP transport is built at
         * all.
         */
        $this->assertNull(config('mail.mailers.dynamic-jobs'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.resend.com/emails'
            && $request->hasHeader('Authorization', 'Bearer re_testing_key_0001'));
    }

    public function test_la_prueba_de_resend_devuelve_el_error_de_la_api_con_422(): void
    {
        Http::fake([
            'https://api.resend.com/emails' => Http::response(
                ['statusCode' => 403, 'name' => 'validation_error', 'message' => 'The cronos.pos domain is not verified'],
                403
            ),
        ]);

        $response = $this->actingAs($this->admin())->postJson(self::ENDPOINT, $this->payload([
            'provider' => EmailConfiguration::PROVIDER_RESEND,
            'api_key' => 're_testing_key_0001',
        ]));

        $response->assertStatus(422);
        $response->assertJsonPath('error_code', 'ERR_MAIL_TEST_FAILED');
        $response->assertJsonPath('data.error.status_code', 403);
        $this->assertStringContainsString(
            'The cronos.pos domain is not verified',
            $response->json('message')
        );

        // The hint is the fastest path from a 403 to the panel that fixes it.
        $this->assertStringContainsString('dominio', implode(' ', $response->json('data.hints')));
    }

    public function test_el_catalogo_publica_resend_junto_a_smtp_y_sendgrid(): void
    {
        $response = $this->actingAs($this->admin())
            ->getJson('/api/admin/email-configurations/catalogs');

        $response->assertOk();

        $providers = collect($response->json('data.providers'));

        $this->assertEqualsCanonicalizing(
            ['smtp', 'sendgrid', 'resend'],
            $providers->pluck('value')->all()
        );

        // The dropdown states the outbound channel because that — not the
        // feature set — is what the choice is actually about on a VPS.
        $this->assertSame('https', $providers->firstWhere('value', 'resend')['channel']);
        $this->assertSame('smtp', $providers->firstWhere('value', 'sendgrid')['channel']);
    }

    public function test_la_prueba_reutiliza_la_credencial_guardada_cuando_el_formulario_la_deja_vacia(): void
    {
        $configuration = EmailConfiguration::create([
            'process_type' => EmailConfiguration::PROCESS_JOBS,
            'provider' => 'sendgrid',
            'api_key' => 'SG.stored-key-4321',
            'from_email' => 'no-reply@cronos.pos',
            'from_name' => 'Cronos POS',
            'target_emails' => ['contabilidad@cronos.pos'],
            'subject' => 'Cierre de Caja',
            'is_active' => true,
        ]);

        Mail::fake();

        // The admin form blanks the key field while editing, because the API
        // never returns it. Without the fallback the button would be unusable
        // on precisely the configurations already running in production.
        $response = $this->actingAs($this->admin())->postJson(self::ENDPOINT, $this->payload([
            'api_key' => '',
            'configuration_id' => $configuration->id,
        ]));

        $response->assertOk();
        $this->assertSame('SG.stored-key-4321', config('mail.mailers.dynamic-jobs.password'));
    }

    public function test_la_prueba_exige_credencial_cuando_no_hay_nada_guardado(): void
    {
        Mail::fake();

        $response = $this->actingAs($this->admin())
            ->postJson(self::ENDPOINT, $this->payload(['api_key' => '']));

        $response->assertStatus(400);
        $response->assertJsonPath('error_code', 'ERR_MAIL_TEST_NO_CREDENTIAL');

        Mail::assertNothingSent();
    }

    public function test_la_prueba_exige_al_menos_un_destinatario_valido(): void
    {
        Mail::fake();

        $this->actingAs($this->admin())
            ->postJson(self::ENDPOINT, $this->payload(['target_emails' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('target_emails');

        $this->actingAs($this->admin())
            ->postJson(self::ENDPOINT, $this->payload(['target_emails' => ['no-es-un-correo']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('target_emails.0');

        Mail::assertNothingSent();
    }

    public function test_la_prueba_de_conexion_es_exclusiva_de_administradores(): void
    {
        $vendor = User::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Cajero',
            'email' => 'cajero@cronos.pos',
            'password' => 'password',
            'status' => 'active',
        ]);

        $vendor->roles()->attach(Role::where('name', 'vendor')->value('id'));

        Mail::fake();

        // The endpoint dials the provider with a credential and sends real
        // mail: it belongs to the same admin-only group as the rows it tests.
        $this->actingAs($vendor)
            ->postJson(self::ENDPOINT, $this->payload())
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    public function test_el_correo_de_prueba_imprime_fecha_hora_proceso_y_ruta_de_salida(): void
    {
        $html = (new TestConnectionMail(EmailConfiguration::PROCESS_JOBS, [
            'host' => 'smtp.sendgrid.net',
            'port' => 2525,
            'encryption' => 'tls',
        ]))->render();

        $this->assertStringContainsString('Prueba de Conexion Exitosa', $html);
        $this->assertStringContainsString('Cronos POS', $html);
        $this->assertStringContainsString(now()->format('d/m/Y'), $html);
        $this->assertStringContainsString(EmailConfiguration::PROCESS_TYPES[EmailConfiguration::PROCESS_JOBS], $html);
        $this->assertStringContainsString('smtp.sendgrid.net:2525', $html);
        $this->assertStringContainsString('TLS', $html);

        // A diagnostic Mailable that queued itself would defeat the endpoint it
        // serves, so the contract is asserted on the class itself.
        $this->assertNotInstanceOf(ShouldQueue::class, new TestConnectionMail('jobs'));
    }
}
