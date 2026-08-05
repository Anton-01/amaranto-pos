<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Escudo de cabeceras HTTP (Fase 9).
 *
 * Middleware GLOBAL: se ejecuta sobre absolutamente toda respuesta emitida
 * por Laravel (API, storage, previews de correo, health check). Las cabeceras
 * se escriben con `set()` — es decir, se imponen — para que ningun upstream ni
 * paquete de terceros pueda relajarlas.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('security.headers.enabled', true)) {
            return $response;
        }

        foreach ($this->baseHeaders($request) as $header => $value) {
            $response->headers->set($header, $value);
        }

        if (config('security.csp.enabled', true)) {
            $headerName = config('security.csp.report_only', false)
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';

            $response->headers->set($headerName, $this->contentSecurityPolicy());
        }

        return $response;
    }

    /**
     * Cabeceras deterministas exigidas por la politica de seguridad.
     *
     * @return array<string, string>
     */
    private function baseHeaders(Request $request): array
    {
        $headers = [
            // Bloqueo absoluto de embebido en iframes -> anti clickjacking.
            'X-Frame-Options' => 'DENY',

            // Filtro XSS legado (navegadores antiguos). Los modernos usan CSP.
            'X-XSS-Protection' => '1; mode=block',

            // Prohibe al navegador adivinar el MIME type de la respuesta.
            'X-Content-Type-Options' => 'nosniff',

            'Referrer-Policy' => (string) config('security.headers.referrer_policy'),
            'Permissions-Policy' => (string) config('security.headers.permissions_policy'),
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ];

        if ($hsts = $this->strictTransportSecurity($request)) {
            $headers['Strict-Transport-Security'] = $hsts;
        }

        return $headers;
    }

    /**
     * HSTS solo se emite sobre TLS: anunciarlo en http:// no tiene efecto y
     * en desarrollo local llegaria a bloquear el acceso por http al dominio.
     */
    private function strictTransportSecurity(Request $request): ?string
    {
        $forced = (bool) config('security.headers.hsts.force', false);

        if (! $forced && ! $request->isSecure()) {
            return null;
        }

        $value = 'max-age='.(int) config('security.headers.hsts.max_age', 31536000);

        if (config('security.headers.hsts.include_subdomains', true)) {
            $value .= '; includeSubDomains';
        }

        if (config('security.headers.hsts.preload', false)) {
            $value .= '; preload';
        }

        return $value;
    }

    /**
     * Construye la CSP a partir de las necesidades reales del stack:
     * SPA React de mismo origen, Cloudflare Turnstile, WebSockets de Reverb
     * y el Cronos POS Agent local de impresion.
     */
    private function contentSecurityPolicy(): string
    {
        $turnstile = (string) config('security.csp.turnstile_origin');
        $agents = (array) config('security.csp.local_agents', []);

        $scriptSrc = $this->merge(["'self'", $turnstile], config('security.csp.extra_script_src'));
        $imgSrc = $this->merge(["'self'", 'data:', 'blob:'], config('security.csp.extra_img_src'));

        $connectSrc = $this->merge(
            array_merge(["'self'", $turnstile], $agents, $this->reverbOrigins()),
            config('security.csp.extra_connect_src')
        );

        $directives = [
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],

            // Equivalente moderno de X-Frame-Options: DENY.
            'frame-ancestors' => ["'none'"],

            'object-src' => ["'none'"],

            'script-src' => $scriptSrc,

            // Tailwind v4 y PrimeReact escriben estilos en linea en runtime.
            'style-src' => ["'self'", "'unsafe-inline'"],

            'img-src' => $imgSrc,
            'font-src' => ["'self'", 'data:'],
            'connect-src' => $connectSrc,

            // Turnstile se pinta dentro de un iframe propio; 'self' habilita
            // el iframe srcDoc del visor de plantillas de correo.
            'frame-src' => ["'self'", $turnstile],

            'worker-src' => ["'self'", 'blob:'],
            'manifest-src' => ["'self'"],
        ];

        return collect($directives)
            ->map(fn (array $sources, string $directive) => $directive.' '.implode(' ', array_unique($sources)))
            ->implode('; ');
    }

    /**
     * Reverb puede vivir en otro host/puerto que la API (proxy /app en Nginx).
     * Sin estos origenes el navegador cortaria el WebSocket de tiempo real.
     *
     * @return list<string>
     */
    private function reverbOrigins(): array
    {
        $host = (string) config('broadcasting.connections.reverb.options.host');

        // En produccion REVERB_HOST es la direccion de bind del demonio
        // (0.0.0.0), no el host publico: en ese caso manda el dominio de la app.
        if (blank($host) || in_array($host, ['0.0.0.0', '::'], true)) {
            $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        }

        if (blank($host)) {
            return ['ws:', 'wss:'];
        }

        $port = config('broadcasting.connections.reverb.options.port');
        $suffix = ($port && ! in_array((int) $port, [80, 443], true)) ? ':'.$port : '';

        return ["ws://{$host}{$suffix}", "wss://{$host}{$suffix}"];
    }

    /**
     * Anexa origenes extra declarados por entorno (lista separada por espacios).
     *
     * @param  list<string>  $base
     * @return list<string>
     */
    private function merge(array $base, mixed $extra): array
    {
        $extra = preg_split('/\s+/', trim((string) $extra), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_merge($base, $extra));
    }
}
