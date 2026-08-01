<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verificacion server-side de Cloudflare Turnstile (Fase 9).
 *
 * Turnstile es invisible para el cajero: el widget resuelve el reto en
 * segundo plano y entrega un token de un solo uso. Ese token DEBE validarse
 * aqui contra la API de Cloudflare antes de tocar la base de datos, o el
 * escudo anti-bots no existe (un script podria mandar cualquier cadena).
 */
class TurnstileVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * El escudo solo se activa cuando hay secreto configurado. Asi el entorno
     * local y los tests siguen funcionando sin llaves de Cloudflare.
     */
    public function enabled(): bool
    {
        return (bool) config('services.turnstile.enabled', true)
            && filled(config('services.turnstile.secret_key'));
    }

    /**
     * @return array{success: bool, code: string|null}
     */
    public function verify(?string $token, ?string $ip): array
    {
        if (! $this->enabled()) {
            return ['success' => true, 'code' => null];
        }

        if (blank($token)) {
            return ['success' => false, 'code' => 'missing-input-response'];
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('services.turnstile.timeout', 5))
                ->post(self::VERIFY_URL, array_filter([
                    'secret' => config('services.turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $ip,
                ]));
        } catch (Throwable $e) {
            return $this->onUnreachable('excepcion de red: '.$e->getMessage());
        }

        if ($response->failed()) {
            return $this->onUnreachable('HTTP '.$response->status());
        }

        $payload = $response->json();

        if (data_get($payload, 'success') === true) {
            return ['success' => true, 'code' => null];
        }

        $code = (string) (data_get($payload, 'error-codes.0') ?? 'invalid-input-response');

        Log::warning('[Turnstile] Token rechazado por Cloudflare.', [
            'error_codes' => data_get($payload, 'error-codes', []),
            'ip' => $ip,
        ]);

        return ['success' => false, 'code' => $code];
    }

    /**
     * Cloudflare inalcanzable. Por defecto se FALLA CERRADO (nadie entra):
     * es un POS financiero y un atacante podria provocar el fallo de red a
     * proposito. `TURNSTILE_FAIL_OPEN=true` invierte la decision para
     * sucursales con conectividad inestable.
     */
    private function onUnreachable(string $reason): array
    {
        $failOpen = (bool) config('services.turnstile.fail_open', false);

        Log::error('[Turnstile] No se pudo contactar a Cloudflare.', [
            'reason' => $reason,
            'fail_open' => $failOpen,
        ]);

        return [
            'success' => $failOpen,
            'code' => $failOpen ? null : 'verification-unavailable',
        ];
    }
}
