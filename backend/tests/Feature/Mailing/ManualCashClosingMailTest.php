<?php

namespace Tests\Feature\Mailing;

use App\Exceptions\Mail\MailConfigurationMissingException;
use App\Jobs\SendConfiguredProcessMail;
use App\Mail\CashRegisterClosingMail;
use App\Models\EmailConfiguration;
use App\Models\User;
use App\Services\Mail\MailStrategyFactory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RequiresPostgres;
use Tests\TestCase;

/**
 * Manual "Enviar por Correo" of the cash closings history, unified with the
 * centralized mailing module.
 *
 * The three properties pinned here are the reason the unification exists: the
 * screen cannot send without an active configuration for the `sales` process,
 * it opens pre-loaded with the recipients that configuration stores, and an
 * operator may replace those recipients for a single report without the saved
 * list changing underneath them.
 */
class ManualCashClosingMailTest extends TestCase
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

    private function admin(): User
    {
        return User::where('email', 'admin@cronos.pos')->firstOrFail();
    }

    private function salesConfiguration(array $overrides = []): EmailConfiguration
    {
        return EmailConfiguration::create(array_merge([
            'process_type' => EmailConfiguration::PROCESS_SALES,
            'provider' => EmailConfiguration::PROVIDER_RESEND,
            'api_key' => 're_testing_key_0001',
            'from_email' => 'no-reply@pos-app.tech',
            'from_name' => 'Cronos POS',
            'target_emails' => ['contabilidad@cronos.pos', 'direccion@cronos.pos'],
            'subject' => 'Reporte de Cierres — Cronos POS',
            'is_active' => true,
        ], $overrides));
    }

    public function test_la_precarga_falla_con_422_cuando_no_hay_configuracion_de_ventas(): void
    {
        $response = $this->actingAs($this->admin())
            ->getJson('/api/cash-registers/closings/email-configuration');

        $response->assertStatus(422)
            ->assertJsonPath('code', MailConfigurationMissingException::ERROR_CODE)
            ->assertJsonPath('metadata.process_type', EmailConfiguration::PROCESS_SALES);

        // The message is the one the operator can act on: it names the panel
        // where the configuration is created.
        $this->assertStringContainsString('Ajustes', $response->json('message'));
    }

    public function test_una_configuracion_desactivada_se_trata_como_inexistente(): void
    {
        $this->salesConfiguration(['is_active' => false]);

        $this->actingAs($this->admin())
            ->getJson('/api/cash-registers/closings/email-configuration')
            ->assertStatus(422)
            ->assertJsonPath('code', MailConfigurationMissingException::ERROR_CODE);
    }

    public function test_la_precarga_devuelve_los_destinatarios_configurados_sin_la_credencial(): void
    {
        $configuration = $this->salesConfiguration();

        $response = $this->actingAs($this->admin())
            ->getJson('/api/cash-registers/closings/email-configuration');

        $response->assertOk()
            ->assertJsonPath('data.recipients', $configuration->target_emails)
            ->assertJsonPath('data.from_email', 'no-reply@pos-app.tech')
            ->assertJsonPath('data.process_label', 'Ventas y Cierres');

        // The manual send never becomes a second way to leak the provider key.
        $this->assertStringNotContainsString('re_testing_key_0001', $response->getContent());
    }

    public function test_el_envio_manual_se_rechaza_sin_configuracion_activa_y_no_encola_nada(): void
    {
        Queue::fake();

        $this->actingAs($this->admin())
            ->postJson('/api/cash-registers/closings/send-email', [
                'emails' => ['externo@cliente.com'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', MailConfigurationMissingException::ERROR_CODE);

        Queue::assertNotPushed(SendConfiguredProcessMail::class);
    }

    public function test_el_envio_manual_viaja_por_el_modulo_centralizado(): void
    {
        $this->salesConfiguration();

        Queue::fake();

        $this->actingAs($this->admin())
            ->postJson('/api/cash-registers/closings/send-email', [])
            ->assertOk()
            ->assertJsonPath('metadata.ad_hoc', false)
            ->assertJsonPath('metadata.recipients', 2);

        /*
         * No override on a default send: the worker resolves the stored list
         * itself, so a recipient added while the message waited in the queue is
         * still served.
         */
        Queue::assertPushed(
            SendConfiguredProcessMail::class,
            fn (SendConfiguredProcessMail $job) => $job->processType === EmailConfiguration::PROCESS_SALES
                && $job->mailable instanceof CashRegisterClosingMail
                && $job->recipientsOverride === null
        );
    }

    public function test_los_destinatarios_escritos_a_mano_sobrescriben_los_configurados_sin_alterarlos(): void
    {
        $configuration = $this->salesConfiguration();

        Queue::fake();

        $this->actingAs($this->admin())
            ->postJson('/api/cash-registers/closings/send-email', [
                'emails' => ['auditor.externo@despacho.mx'],
            ])
            ->assertOk()
            ->assertJsonPath('metadata.ad_hoc', true)
            ->assertJsonPath('metadata.recipients', 1);

        Queue::assertPushed(
            SendConfiguredProcessMail::class,
            fn (SendConfiguredProcessMail $job) => $job->recipientsOverride === ['auditor.externo@despacho.mx']
        );

        // An ad-hoc send is valid for one message: the global distribution list
        // must survive it untouched.
        $this->assertSame(
            ['contabilidad@cronos.pos', 'direccion@cronos.pos'],
            $configuration->fresh()->target_emails
        );
    }

    public function test_el_job_entrega_al_destinatario_puntual_con_el_remitente_oficial(): void
    {
        $configuration = $this->salesConfiguration();

        Http::fake(['https://api.resend.com/emails' => Http::response(['id' => 'e1b2c3d4'], 200)]);
        Mail::fake();

        (new SendConfiguredProcessMail(
            EmailConfiguration::PROCESS_SALES,
            new CashRegisterClosingMail(new EloquentCollection, ['date_from' => null, 'date_to' => null]),
            ['auditor.externo@despacho.mx'],
        ))->handle(app(MailStrategyFactory::class));

        Http::assertSent(function (Request $request) use ($configuration) {
            $body = $request->data();

            /*
             * Only the destination changed. Credential, sender and subject are
             * still the ones stored for the process, so an ad-hoc report is
             * indistinguishable from a scheduled one for whoever receives it.
             */
            return $body['to'] === ['auditor.externo@despacho.mx']
                && $body['from'] === $configuration->from_name.' <no-reply@pos-app.tech>'
                && $body['subject'] === $configuration->subject;
        });
    }

    public function test_una_lista_puntual_sin_correos_validos_no_cae_en_la_lista_configurada(): void
    {
        $this->salesConfiguration();

        Http::fake();
        Mail::fake();

        (new SendConfiguredProcessMail(
            EmailConfiguration::PROCESS_SALES,
            new CashRegisterClosingMail(new EloquentCollection),
            ['no-es-un-correo'],
        ))->handle(app(MailStrategyFactory::class));

        /*
         * Silently widening a one-off send to the whole finance distribution
         * list would be the worst possible recovery from a typo, so the job
         * sends nothing at all.
         */
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_una_configuracion_activa_sin_destinatarios_se_rechaza_antes_de_encolar(): void
    {
        $this->salesConfiguration(['target_emails' => []]);

        Queue::fake();

        $this->actingAs($this->admin())
            ->postJson('/api/cash-registers/closings/send-email', [])
            ->assertStatus(422)
            ->assertJsonPath('code', MailConfigurationMissingException::ERROR_CODE);

        Queue::assertNotPushed(SendConfiguredProcessMail::class);
    }

    public function test_un_correo_mal_formado_se_rechaza_por_validacion(): void
    {
        $this->salesConfiguration();

        Queue::fake();

        $this->actingAs($this->admin())
            ->postJson('/api/cash-registers/closings/send-email', [
                'emails' => ['no-es-un-correo'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('emails.0');

        Queue::assertNotPushed(SendConfiguredProcessMail::class);
    }
}
