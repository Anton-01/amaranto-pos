<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ApiRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('api');
    }

    public function test_el_escudo_global_corta_al_superar_el_limite_por_minuto(): void
    {
        config(['security.throttle.api.max_attempts' => 5]);

        // Peticiones dentro del cupo: llegan al validador (422), no al escudo.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [])->assertStatus(422);
        }

        $response = $this->postJson('/api/auth/login', []);

        $response->assertStatus(429);
        $response->assertJsonPath('code', 'ERR_API_RATE_LIMIT_EXCEEDED');
        $response->assertJsonPath('status', 'error');

        // El frontend construye su temporizador con este encabezado.
        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertGreaterThan(0, $response->json('metadata.retry_after_seconds'));
    }

    public function test_la_respuesta_429_conserva_las_cabeceras_de_seguridad(): void
    {
        config(['security.throttle.api.max_attempts' => 1]);

        $this->postJson('/api/auth/login', []);

        $this->postJson('/api/auth/login', [])
            ->assertStatus(429)
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
