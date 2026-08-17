<?php

namespace App\Services\Mail;

use App\Models\EmailConfiguration;
use App\Services\Mail\Contracts\MailStrategyInterface;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves which mail strategy answers for the active configuration.
 *
 * The mapping lives in config/mailing.php, under the `strategy` key of each
 * provider, so adding a provider is a configuration entry plus one class — not
 * an edit to the job, the controller or this factory. The instance is built by
 * the container, which is what lets a strategy declare its own dependencies
 * (DynamicMailerFactory for the SMTP family, nothing for the HTTP one) and lets
 * a test swap one for a double with a single bind().
 *
 * Resolution is by provider key, taken from the database row
 * (email_configurations.provider) or from the payload of the health check —
 * the two are the same string, which is why an administrator can validate a
 * provider before saving it.
 */
class MailStrategyFactory
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Strategy for a provider key.
     *
     * @throws InvalidArgumentException when the provider has no strategy
     *                                  declared, which means somebody added a
     *                                  key to the catalog without wiring a
     *                                  class to it.
     */
    public function make(string $provider): MailStrategyInterface
    {
        $strategy = config("mailing.providers.{$provider}.strategy");

        if (! is_string($strategy) || ! class_exists($strategy)) {
            throw new InvalidArgumentException(
                "ERR_MAIL_STRATEGY_UNSUPPORTED: el proveedor '{$provider}' no tiene una estrategia de envio registrada."
            );
        }

        $instance = $this->container->make($strategy);

        if (! $instance instanceof MailStrategyInterface) {
            throw new InvalidArgumentException(
                "ERR_MAIL_STRATEGY_INVALID: '{$strategy}' no implementa MailStrategyInterface."
            );
        }

        return $instance;
    }

    /** Strategy for a stored configuration. */
    public function for(EmailConfiguration $configuration): MailStrategyInterface
    {
        return $this->make((string) $configuration->provider);
    }

    /**
     * Provider keys that currently have a strategy behind them.
     *
     * The admin catalog is validated against this list, so a provider can never
     * be offered in the dropdown before its class exists.
     *
     * @return array<int, string>
     */
    public function supportedProviders(): array
    {
        return collect((array) config('mailing.providers'))
            ->filter(fn ($provider) => is_array($provider)
                && is_string($provider['strategy'] ?? null)
                && class_exists($provider['strategy']))
            ->keys()
            ->all();
    }
}
