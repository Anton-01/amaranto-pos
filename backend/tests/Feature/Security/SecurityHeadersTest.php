<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_inyecta_las_cabeceras_de_seguridad_obligatorias(): void
    {
        $response = $this->postJson('/api/auth/login', []);

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-XSS-Protection', '1; mode=block');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');
    }

    public function test_hsts_se_omite_sobre_http_y_se_emite_sobre_https(): void
    {
        // Sin TLS el anuncio de HSTS no tiene efecto y en desarrollo local
        // llegaria a dejar el dominio inaccesible por http.
        $this->postJson('/api/auth/login', [])
            ->assertHeaderMissing('Strict-Transport-Security');

        config(['app.url' => 'https://pos.test']);

        $this->postJson('https://pos.test/api/auth/login', [])
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_la_csp_habilita_turnstile_y_el_agente_local_de_impresion(): void
    {
        $csp = $this->postJson('/api/auth/login', [])
            ->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);

        // Anti clickjacking (equivalente moderno de X-Frame-Options: DENY).
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);

        // Cloudflare Turnstile necesita script + iframe + siteverify.
        $this->assertStringContainsString('script-src \'self\' https://challenges.cloudflare.com', $csp);
        $this->assertStringContainsString('frame-src \'self\' https://challenges.cloudflare.com', $csp);

        // Cronos POS Agent (impresion ESC/POS en el equipo del cajero).
        $this->assertStringContainsString('http://127.0.0.1:9100', $csp);

        // Tailwind v4 / PrimeReact inyectan estilos en linea en runtime.
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);

        // ...pero JAMAS scripts en linea.
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
    }

    public function test_el_modo_report_only_no_bloquea(): void
    {
        config(['security.csp.report_only' => true]);

        $response = $this->postJson('/api/auth/login', []);

        $response->assertHeaderMissing('Content-Security-Policy');
        $this->assertNotNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }
}
