<?php

namespace Tests\Feature\Mailing;

use App\Jobs\SendConfiguredProcessMail;
use App\Mail\CashRegisterClosingReportMail;
use App\Models\CashRegister;
use App\Models\EmailConfiguration;
use App\Models\User;
use App\Services\Mail\DynamicMailerFactory;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RequiresPostgres;
use Tests\TestCase;

/**
 * Database-driven mailing wired into the cash auto-closing.
 *
 * These tests pin the three properties the module exists for: the closing job
 * never blocks on SMTP, the credentials never reach the browser, and the
 * report is a self-contained document with no attachment and no difference
 * arithmetic.
 */
class DynamicMailingTest extends TestCase
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

    private function openRegisterFor(User $user): CashRegister
    {
        return CashRegister::create([
            'user_id' => $user->id,
            'opened_at' => now()->subHours(6),
            'opening_balance' => 500.00,
        ]);
    }

    private function activeJobsConfiguration(array $overrides = []): EmailConfiguration
    {
        return EmailConfiguration::create(array_merge([
            'process_type' => EmailConfiguration::PROCESS_JOBS,
            'provider' => 'sendgrid',
            'api_key' => 'SG.testing-key-0001',
            'from_email' => 'no-reply@cronos.pos',
            'from_name' => 'Cronos POS',
            'target_emails' => ['contabilidad@cronos.pos', 'direccion@cronos.pos'],
            'subject' => 'Cierre de Caja Automatico — Cronos POS',
            'is_active' => true,
        ], $overrides));
    }

    public function test_el_cierre_automatico_encola_el_reporte_sin_enviarlo_de_forma_sincrona(): void
    {
        $this->activeJobsConfiguration();
        $this->openRegisterFor($this->admin());

        Queue::fake();

        $exitCode = Artisan::call('cronos:auto-close-registers');

        $this->assertSame(0, $exitCode);

        Queue::assertPushed(
            SendConfiguredProcessMail::class,
            fn (SendConfiguredProcessMail $job) => $job->processType === EmailConfiguration::PROCESS_JOBS
                && $job->mailable instanceof CashRegisterClosingReportMail
        );
    }

    public function test_sin_configuracion_activa_el_cierre_no_encola_ningun_correo(): void
    {
        $this->activeJobsConfiguration(['is_active' => false]);
        $register = $this->openRegisterFor($this->admin());

        Queue::fake();

        Artisan::call('cronos:auto-close-registers');

        Queue::assertNotPushed(SendConfiguredProcessMail::class);

        // The closing itself must still happen: mailing is a notification
        // channel, never a precondition of the financial operation.
        $this->assertDatabaseHas('cash_register_closings', ['cash_register_id' => $register->id]);
    }

    public function test_el_job_envia_a_los_destinatarios_y_con_el_asunto_de_la_base_de_datos(): void
    {
        $configuration = $this->activeJobsConfiguration();

        Mail::fake();

        $job = new SendConfiguredProcessMail(
            EmailConfiguration::PROCESS_JOBS,
            new CashRegisterClosingReportMail(
                closingId: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
                operatorName: 'Carlos Martinez',
                closingDate: now()->format('d/m/Y H:i:s'),
                totalAmount: 15420.50,
                isAutomated: true,
            )
        );

        $job->handle(app(DynamicMailerFactory::class));

        /*
         * The assertion looks at the "sent" bucket, not the queued one:
         * MailFake::sendNow() files the mailable as sent even when it is a
         * ShouldQueue instance (it hardcodes shouldQueue: false), and that is
         * the behaviour the job depends on. Queueing it again from inside the
         * worker would push it back to Redis, where the run-time transport no
         * longer exists — the asynchronous leg already happened when this job
         * was dispatched.
         */
        Mail::assertSent(CashRegisterClosingReportMail::class, function (CashRegisterClosingReportMail $mail) use ($configuration) {
            /*
             * The sender is read off the property instead of through
             * hasFrom(): that helper consults envelope() first, and
             * Envelope::isFrom() dereferences its own $from without a null
             * check. This Mailable's envelope declares only a subject — the
             * identity is applied by the job — so the helper fatals before it
             * ever compares anything.
             */
            $from = $mail->from[0] ?? [];

            return $mail->hasTo('contabilidad@cronos.pos')
                && $mail->hasTo('direccion@cronos.pos')
                && ($from['address'] ?? null) === $configuration->from_email
                && ($from['name'] ?? null) === $configuration->from_name
                && $mail->subject === $configuration->subject;
        });
    }

    public function test_el_job_no_envia_nada_cuando_la_configuracion_se_desactiva_despues_de_encolarse(): void
    {
        $this->activeJobsConfiguration(['is_active' => false]);

        Mail::fake();

        (new SendConfiguredProcessMail(
            EmailConfiguration::PROCESS_JOBS,
            new CashRegisterClosingReportMail(
                closingId: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
                operatorName: 'Carlos Martinez',
                closingDate: now()->format('d/m/Y H:i:s'),
                totalAmount: 100.00,
            )
        ))->handle(app(DynamicMailerFactory::class));

        Mail::assertNothingOutgoing();
    }

    public function test_el_transporte_dinamico_usa_las_credenciales_de_la_fila(): void
    {
        $configuration = $this->activeJobsConfiguration();

        $mailerName = app(DynamicMailerFactory::class)->register($configuration);

        $this->assertSame('dynamic-jobs', $mailerName);

        $transport = config("mail.mailers.{$mailerName}");

        $this->assertSame('smtp', $transport['transport']);
        $this->assertSame('smtp.sendgrid.net', $transport['host']);
        // SendGrid's relay requires this literal username; the key is the password.
        $this->assertSame('apikey', $transport['username']);
        $this->assertSame('SG.testing-key-0001', $transport['password']);
    }

    public function test_el_transporte_de_sendgrid_relaya_por_el_puerto_alterno_2525(): void
    {
        /*
         * Regression guard for the production timeout. Cloud providers block
         * outbound port 587 as an anti-spam policy and drop the packets rather
         * than rejecting them, so the SMTP handshake hangs until the socket
         * expires and the job dies with "Operation timed out". SendGrid's
         * alternate submission port 2525 is not covered by that block, so it
         * stays the default for any deployment on a VPS or Droplet.
         */
        $transport = config("mail.mailers.{$this->registeredSendGridMailer()}");

        $this->assertSame(2525, $transport['port']);
        $this->assertNotSame(587, $transport['port']);
        // STARTTLS still applies on 2525: the alternate port changes the route,
        // never the encryption.
        $this->assertSame('tls', $transport['encryption']);

        // The skeleton is the single source of truth for the port, so the guard
        // has to hold there too — not only on the transport it produced.
        $this->assertSame(2525, (int) config('mailing.providers.sendgrid.port'));
    }

    private function registeredSendGridMailer(): string
    {
        return app(DynamicMailerFactory::class)->register($this->activeJobsConfiguration());
    }

    public function test_el_reporte_es_autocontenido_sin_pdf_ni_diferencias(): void
    {
        $mail = new CashRegisterClosingReportMail(
            closingId: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
            operatorName: 'Carlos Martinez',
            closingDate: now()->format('d/m/Y H:i:s'),
            totalAmount: 15420.50,
            paymentBreakdown: ['Efectivo' => 15420.50],
            isAutomated: true,
        );

        $html = $mail->render();

        // The Mailable no longer declares an attachments() hook at all: the PDF
        // arqueo is gone and the body itself is the deliverable document.
        $this->assertFalse(method_exists($mail, 'attachments'));
        $this->assertStringNotContainsString('Diferencia', $html);
        $this->assertStringNotContainsString('FALTANTE', $html);
        $this->assertStringNotContainsString('SOBRANTE', $html);
        $this->assertStringNotContainsString('adjunto', $html);
        $this->assertStringNotContainsString('Cronos Fast Food', $html);

        // Fiscal identity seeded in global_settings turns the email into the
        // formal document that replaced the PDF.
        $this->assertStringContainsString('Cronos POS', $html);
        $this->assertStringContainsString('XAXX010101000', $html);
    }

    public function test_la_api_administra_configuraciones_sin_exponer_la_api_key(): void
    {
        $payload = [
            'process_type' => EmailConfiguration::PROCESS_USERS,
            'provider' => 'sendgrid',
            'api_key' => 'SG.super-secret-key-9876',
            'from_email' => 'cuentas@cronos.pos',
            'from_name' => 'Cronos POS',
            'target_emails' => ['soporte@cronos.pos'],
            'subject' => 'Notificacion de Cuenta',
            'is_active' => true,
        ];

        $created = $this->actingAs($this->admin())
            ->postJson('/api/admin/email-configurations', $payload);

        $created->assertCreated();
        $created->assertJsonMissing(['api_key' => 'SG.super-secret-key-9876']);
        $created->assertJsonPath('data.api_key_preview', '****9876');
        $created->assertJsonPath('data.has_api_key', true);

        $id = $created->json('data.id');

        // An empty credential field means "keep the stored key", so an edit of
        // the recipient list cannot silently wipe working credentials.
        $this->actingAs($this->admin())
            ->putJson("/api/admin/email-configurations/{$id}", [
                'api_key' => '',
                'target_emails' => ['soporte@cronos.pos', 'ti@cronos.pos'],
            ])
            ->assertOk();

        $stored = EmailConfiguration::findOrFail($id);

        $this->assertSame('SG.super-secret-key-9876', $stored->api_key);
        $this->assertSame(['soporte@cronos.pos', 'ti@cronos.pos'], $stored->target_emails);
    }

    public function test_solo_existe_una_configuracion_por_tipo_de_proceso(): void
    {
        $this->activeJobsConfiguration();

        $this->actingAs($this->admin())
            ->postJson('/api/admin/email-configurations', [
                'process_type' => EmailConfiguration::PROCESS_JOBS,
                'provider' => 'sendgrid',
                'api_key' => 'SG.otra-llave',
                'from_email' => 'otro@cronos.pos',
                'from_name' => 'Otro',
                'target_emails' => ['alguien@cronos.pos'],
                'subject' => 'Duplicado',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('process_type');
    }
}
