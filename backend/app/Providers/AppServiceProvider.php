<?php

namespace App\Providers;

use App\Listeners\JobTelemetrySubscriber;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        /*
         * NotifyPettyCashWithdrawal ya queda registrado por el descubrimiento
         * automatico de listeners (su metodo `handle` declara el evento en la
         * firma). El `Event::listen` explicito que habia aqui lo registraba una
         * SEGUNDA vez, y cada retiro de caja chica notificaba dos veces a los
         * administradores. Se elimina el registro manual; el descubrimiento
         * basta.
         */

        // Telemetria de jobs (Fase 10): se engancha a los eventos nativos de
        // la cola, asi que ningun job necesita instrumentarse a mano.
        Event::subscribe(JobTelemetrySubscriber::class);

        $this->configureRateLimiting();
    }

    /**
     * Escudo global de la API (Fase 9).
     *
     * Aplicado al grupo `api` desde bootstrap/app.php. Protege al servidor de
     * saturaciones y de bucles infinitos del frontend (un useEffect mal
     * dependido puede lanzar miles de peticiones por minuto).
     *
     * La identidad es el usuario autenticado cuando existe — asi dos cajeros
     * detras del mismo NAT de la sucursal no comparten cupo — y la IP en las
     * rutas publicas.
     *
     * Se consulta el guard `sanctum` de forma explicita: este middleware corre
     * ANTES del `auth:sanctum` de la ruta, y el guard por defecto (`web`,
     * basado en sesion) devolveria null para peticiones con Bearer token,
     * degradando silenciosamente todo el cupo a un unico bucket por IP.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $maxAttempts = (int) config('security.throttle.api.max_attempts', 100);

            return Limit::perMinute($maxAttempts)
                ->by($request->user('sanctum')?->id ?: $request->ip())
                ->response(function (Request $request, array $headers) {
                    $retryAfter = (int) ($headers['Retry-After'] ?? 60);

                    return response()->json([
                        'status' => 'error',
                        'code' => 'ERR_API_RATE_LIMIT_EXCEEDED',
                        'message' => 'Demasiadas peticiones. Espera unos segundos antes de reintentar.',
                        'errors' => [],
                        'metadata' => [
                            'retry_after_seconds' => $retryAfter,
                        ],
                    ], 429, $headers);
                });
        });
    }
}
