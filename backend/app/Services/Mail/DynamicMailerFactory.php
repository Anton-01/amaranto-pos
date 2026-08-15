<?php

namespace App\Services\Mail;

use App\Models\EmailConfiguration;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * Builds a Symfony Mailer transport out of a database row, at run time.
 *
 * The application ships without hardcoded provider credentials: this factory
 * takes an EmailConfiguration, merges it over the provider skeleton declared in
 * config/mailing.php, and injects the result into config('mail.mailers.*') so
 * the standard Mail facade can address it by name. Nothing is persisted to
 * disk and nothing survives the process, which is exactly what makes a key
 * rotated from the admin UI effective on the very next message.
 */
class DynamicMailerFactory
{
    /**
     * The contract is injected rather than the concrete manager so the service
     * keeps working when the mailer is swapped for a fake during testing.
     */
    public function __construct(private readonly MailFactory $manager)
    {
    }

    /**
     * Registers the transport for a configuration and returns its mailer name.
     *
     * @throws InvalidArgumentException when the row points at a provider that
     *                                  config/mailing.php does not describe.
     */
    public function register(EmailConfiguration $configuration): string
    {
        $provider = config("mailing.providers.{$configuration->provider}");

        if (! is_array($provider)) {
            throw new InvalidArgumentException(
                "ERR_MAIL_PROVIDER_UNSUPPORTED: proveedor '{$configuration->provider}' sin plantilla de transporte."
            );
        }

        $mailerName = $this->mailerName($configuration);

        Config::set("mail.mailers.{$mailerName}", $this->transportFor($provider, $configuration));

        /*
         * MailManager caches every mailer it resolves. Queue workers are
         * long-lived processes that handle messages for several processes in a
         * row, so without this flush the second job would keep sending through
         * the transport built for the first one — with the wrong API key.
         */
        if ($this->manager instanceof MailManager) {
            $this->manager->forgetMailers();
        }

        return $mailerName;
    }

    /** Namespaced mailer key, e.g. "dynamic-jobs". */
    public function mailerName(EmailConfiguration $configuration): string
    {
        $prefix = config('mailing.runtime_mailer_prefix', 'dynamic');

        return "{$prefix}-{$configuration->process_type}";
    }

    /**
     * Fills the provider skeleton with the stored credential.
     *
     * SendGrid's relay authenticates with the fixed username "apikey" plus the
     * key as password; generic servers use the sender address as username. The
     * `credentials` flag on the skeleton selects between both shapes.
     *
     * The port always comes from the skeleton. Do not reintroduce 587 as the
     * fallback: cloud providers block outbound 587 as an anti-spam measure and
     * the connection times out instead of failing fast, so a skeleton missing
     * its port would silently sink every message. 2525 is SendGrid's alternate
     * submission port and is not covered by that block.
     */
    private function transportFor(array $provider, EmailConfiguration $configuration): array
    {
        $declaredUsername = $provider['username'] ?? null;

        $username = ($provider['credentials'] ?? 'password') === 'api_key'
            ? ($declaredUsername ?: 'apikey')
            : ($declaredUsername ?: $configuration->from_email);

        return [
            'transport' => $provider['transport'] ?? 'smtp',
            'host' => $provider['host'] ?? '127.0.0.1',
            'port' => $provider['port'] ?? 2525,
            'encryption' => $provider['encryption'] ?? 'tls',
            'username' => $username,
            'password' => $configuration->api_key,
            'timeout' => $provider['timeout'] ?? null,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: null,
        ];
    }
}
