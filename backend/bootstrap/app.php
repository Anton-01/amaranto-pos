<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * El contenedor de la API solo es alcanzable a traves del Nginx del
         * host (127.0.0.1:8000). Sin confiar en ese proxy, `$request->ip()`
         * devolveria siempre la IP del proxy y TODO el throttling por IP
         * (login incluido) colapsaria en un unico cubo global.
         */
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
        );

        // Escudo de cabeceras HTTP sobre toda respuesta de la aplicacion.
        $middleware->append(SecurityHeaders::class);

        // Escudo global anti-saturacion: 100 req/min por usuario o IP.
        $middleware->api(prepend: [
            ThrottleRequests::class.':api',
        ]);

        $middleware->alias([
            'user.active' => EnsureUserIsActive::class,
            'role' => EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        /*
         * Red de seguridad: cualquier throttle sin `->response()` propio
         * (por ejemplo un `throttle:10,1` puntual en una ruta) tambien
         * responde con el catalogo de errores corporativo.
         */
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $headers = $e->getHeaders();

            return response()->json([
                'status' => 'error',
                'code' => 'ERR_API_RATE_LIMIT_EXCEEDED',
                'message' => 'Demasiadas peticiones. Espera unos segundos antes de reintentar.',
                'errors' => [],
                'metadata' => [
                    'retry_after_seconds' => (int) ($headers['Retry-After'] ?? 60),
                ],
            ], 429, $headers);
        });
    })->create();
