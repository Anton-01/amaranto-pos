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
 *
 * THE INJECTION IS OPTIONAL, AND THAT IS THE POINT. Config::set() overwrites
 * whatever the .env produced, so running it unconditionally turns every value
 * the factory could not resolve into a silent override of a correct one: a
 * skeleton with no host used to fall back to a literal 127.0.0.1 and the
 * application dialled loopback while .env.production pointed at the real
 * relay — a configuration that looks right in the file and is wrong in memory,
 * which is the hardest kind to diagnose. So the rule here is: only override
 * what is actually configured, and when nothing is, do not call Config::set()
 * at all and let Laravel resolve the mailer the .env declares.
 *
 * Priority, applied field by field:
 *
 *     database row  >  config/mailing.php skeleton  >  config/mail.php (.env)
 *
 * Run `php artisan app:debug-mail-config` to see what this resolution produced
 * in a live process, which is the only authority on what Laravel is using —
 * a cached config or a stale container makes the .env file itself unreliable
 * as evidence.
 */
class DynamicMailerFactory
{
    /**
     * Transport keys taken from the inherited mailer of config/mail.php when
     * neither the database nor the skeleton dictates a value.
     *
     * `scheme` and `url` belong here because Symfony builds its DSN out of
     * them: inheriting a host while dropping them would leave a MAIL_URL from
     * the .env quietly deciding the route.
     */
    private const INHERITED_KEYS = [
        'transport',
        'scheme',
        'url',
        'host',
        'port',
        'encryption',
        'username',
        'password',
        'timeout',
        'local_domain',
    ];

    /**
     * Skeleton keys that describe a real endpoint.
     *
     * `transport` is excluded on purpose: every skeleton declares it, so
     * counting it as an override would make the "nothing to override" branch
     * unreachable and bring the hijack straight back.
     */
    private const ENDPOINT_KEYS = ['host', 'port', 'encryption', 'timeout'];

    /** Endpoint keys that must reach the transport as integers. */
    private const NUMERIC_KEYS = ['port', 'timeout'];

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
     * Returns the name of a run-time mailer when the row or its skeleton has
     * something to override, and the name of the .env's own default mailer
     * when they do not. Callers only need the name: both are resolvable
     * through Mail::mailer().
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

        $native = $this->nativeTransport($provider);
        $overrides = $this->overridesFor($provider, $configuration, $native);

        /*
         * Neither the row nor the skeleton describes an endpoint or carries a
         * credential — the "SMTP Generico" case on a deployment that keeps its
         * mail server in the .env. Injecting anything here would be inventing
         * a transport out of defaults, so the database steps aside and the
         * message leaves through the mailer MAIL_MAILER names, with the
         * MAIL_HOST / MAIL_PORT / MAIL_USERNAME the server already has.
         */
        if ($overrides === []) {
            return $this->nativeMailerName($provider);
        }

        $mailerName = $this->mailerName($configuration);

        Config::set("mail.mailers.{$mailerName}", $this->transportFor($provider, $native, $overrides));

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
     * Resolves the mailer name without touching config('mail'), for callers
     * that need to report the route rather than send through it.
     *
     * `php artisan app:debug-mail-config` uses this to show which mailer each
     * process would use without mutating the configuration it is inspecting.
     */
    public function resolvedMailerName(EmailConfiguration $configuration): string
    {
        $provider = config("mailing.providers.{$configuration->provider}");

        if (! is_array($provider)) {
            throw new InvalidArgumentException(
                "ERR_MAIL_PROVIDER_UNSUPPORTED: proveedor '{$configuration->provider}' sin plantilla de transporte."
            );
        }

        $native = $this->nativeTransport($provider);

        return $this->overridesFor($provider, $configuration, $native) === []
            ? $this->nativeMailerName($provider)
            : $this->mailerName($configuration);
    }

    /**
     * The values this configuration genuinely dictates over the .env.
     *
     * An empty array is a meaningful answer: it says the deployment, not the
     * database, owns the transport.
     *
     * @return array<string, mixed>
     */
    private function overridesFor(array $provider, EmailConfiguration $configuration, array $native): array
    {
        $overrides = [];

        foreach (self::ENDPOINT_KEYS as $key) {
            $value = $provider[$key] ?? null;

            // blank() and not isset(): the skeleton reads env() variables that
            // are declared-but-empty on plenty of servers, and an empty string
            // is the absence of configuration, not a request for an empty host.
            if (blank($value)) {
                continue;
            }

            $overrides[$key] = in_array($key, self::NUMERIC_KEYS, true) ? (int) $value : $value;
        }

        // The stored credential is the one piece of the transport the database
        // always owns, so it outranks MAIL_PASSWORD whenever it is present.
        if (filled($configuration->api_key)) {
            $overrides['password'] = $configuration->api_key;
        }

        /*
         * The username is deliberately resolved last and only when something
         * else is already being overridden. On its own it authenticates
         * nothing, and adding it would make this array non-empty — forcing a
         * Config::set() whose only effect would be to replace the .env's
         * MAIL_USERNAME with the sender identity.
         */
        if ($overrides === []) {
            return [];
        }

        $username = $this->usernameFor($provider, $configuration, $native);

        if (filled($username)) {
            $overrides['username'] = $username;
        }

        return $overrides;
    }

    /**
     * SMTP login for the resolved transport.
     *
     * SendGrid's relay authenticates with the fixed username "apikey" plus the
     * key as password; generic servers use a plain login. The `credentials`
     * flag on the skeleton selects between both shapes.
     *
     * The sender address is the last resort, not the first: it is an identity,
     * not a login, and it only ever worked because most small relays accept
     * the mailbox as username. A MAIL_USERNAME in the .env is a real login and
     * takes precedence, so a configured relay account cannot be replaced by
     * whatever address the administrator typed in the "from" field.
     */
    private function usernameFor(array $provider, EmailConfiguration $configuration, array $native): ?string
    {
        $declared = $provider['username'] ?? null;

        if (filled($declared)) {
            return $declared;
        }

        if (($provider['credentials'] ?? 'password') === 'api_key') {
            return 'apikey';
        }

        if (filled($native['username'] ?? null)) {
            return null;
        }

        return $configuration->from_email ?: null;
    }

    /**
     * The mailer of config/mail.php this provider completes itself with,
     * reduced to the transport keys that can be inherited.
     *
     * @return array<string, mixed>
     */
    private function nativeTransport(array $provider): array
    {
        $native = config('mail.mailers.'.$this->inheritedMailerName($provider));

        if (! is_array($native)) {
            return [];
        }

        return array_intersect_key($native, array_flip(self::INHERITED_KEYS));
    }

    /**
     * Assembles the final transport: the .env's mailer as the base, the
     * skeleton and the database row on top.
     *
     * @return array<string, mixed>
     */
    private function transportFor(array $provider, array $native, array $overrides): array
    {
        /*
         * A skeleton that names its own host describes a different endpoint
         * than the .env's mailer, so the DSN shortcuts of config/mail.php are
         * dropped: Symfony reads MAIL_URL / MAIL_SCHEME before host and port,
         * and inheriting them would let the native route override the
         * provider the administrator picked.
         */
        if (filled($provider['host'] ?? null)) {
            unset($native['url'], $native['scheme']);
        }

        $transport = array_merge($native, $overrides);

        $transport['transport'] = $provider['transport'] ?? ($native['transport'] ?? 'smtp');

        // EHLO identity: config/mail.php already resolves MAIL_EHLO_DOMAIN, so
        // it is only computed here when the inherited mailer left it empty.
        if (blank($transport['local_domain'] ?? null)) {
            $transport['local_domain'] = parse_url((string) config('app.url'), PHP_URL_HOST) ?: null;
        }

        return $transport;
    }

    /** Name of the config/mail.php mailer this provider builds upon. */
    private function inheritedMailerName(array $provider): string
    {
        return $provider['inherits'] ?? 'smtp';
    }

    /**
     * Mailer to fall back to when the database has nothing to override.
     *
     * MAIL_MAILER is the deployment's own answer to "how does this server send
     * mail", so it is honoured as written — including a `log` mailer on a
     * staging box, which is a deliberate configuration and not a failure. The
     * inherited mailer only covers a default that names something
     * config/mail.php does not declare.
     */
    private function nativeMailerName(array $provider): string
    {
        $default = config('mail.default');

        if (filled($default) && is_array(config("mail.mailers.{$default}"))) {
            return $default;
        }

        return $this->inheritedMailerName($provider);
    }
}
