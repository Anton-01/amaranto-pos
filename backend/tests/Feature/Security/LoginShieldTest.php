<?php

namespace Tests\Feature\Security;

use App\Support\LoginThrottle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Contrato HTTP de la puerta de entrada del POS.
 *
 * Las capas verificadas aqui (candado de fuerza bruta y escudo Turnstile) se
 * resuelven ANTES de consultar PostgreSQL, por lo que las pruebas no
 * requieren base de datos: ese es justamente el diseño que se valida.
 */
class LoginShieldTest extends TestCase
{
    private const SECRET = '0xFAKE_TURNSTILE_SECRET';

    private function primeLockout(string $email = 'cajero@cronos.pos'): void
    {
        $request = Request::create(
            '/api/auth/login',
            'POST',
            ['email' => $email],
            server: ['REMOTE_ADDR' => '127.0.0.1']
        );

        for ($i = 0; $i < 5; $i++) {
            LoginThrottle::recordFailure($request);
        }
    }

    public function test_el_login_bloqueado_responde_429_con_retry_after(): void
    {
        $this->primeLockout();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'cajero@cronos.pos',
            'password' => 'lo-que-sea',
        ]);

        $response->assertStatus(429);
        $response->assertJsonPath('code', 'ERR_AUTH_TOO_MANY_ATTEMPTS');
        $response->assertJsonPath('metadata.retry_after_seconds', 900);
        $response->assertJsonPath('metadata.retry_after_minutes', 15);
        $response->assertHeader('Retry-After', '900');

        $response->assertJsonPath(
            'message',
            'Por seguridad, tu acceso ha sido bloqueado temporalmente. Intenta nuevamente en 15 minutos.'
        );
    }

    public function test_sin_secreto_configurado_el_escudo_turnstile_queda_inactivo(): void
    {
        config(['services.turnstile.secret_key' => null]);
        Http::fake();

        $this->primeLockout('anonimo@cronos.pos');

        // Llega al candado, no al captcha: prueba que Turnstile no intervino.
        $this->postJson('/api/auth/login', [
            'email' => 'anonimo@cronos.pos',
            'password' => 'lo-que-sea',
        ])->assertStatus(429);

        Http::assertNothingSent();
    }

    public function test_turnstile_activo_rechaza_el_login_sin_token(): void
    {
        config(['services.turnstile.secret_key' => self::SECRET]);
        Http::fake();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'cajero@cronos.pos',
            'password' => 'lo-que-sea',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'ERR_AUTH_CAPTCHA_REQUIRED');

        // Un token ausente se descarta en local: no se gasta viaje a Cloudflare.
        Http::assertNothingSent();
    }

    public function test_turnstile_rechaza_un_token_invalido_antes_de_tocar_la_base_de_datos(): void
    {
        config(['services.turnstile.secret_key' => self::SECRET]);

        Http::fake([
            'challenges.cloudflare.com/*' => Http::response([
                'success' => false,
                'error-codes' => ['invalid-input-response'],
            ]),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'cajero@cronos.pos',
            'password' => 'lo-que-sea',
            'cf_turnstile_token' => 'token-falsificado-por-un-bot',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('code', 'ERR_AUTH_CAPTCHA_FAILED');

        Http::assertSent(fn ($request) => $request['secret'] === self::SECRET
            && $request['response'] === 'token-falsificado-por-un-bot');
    }

    public function test_si_cloudflare_no_responde_se_falla_cerrado(): void
    {
        config([
            'services.turnstile.secret_key' => self::SECRET,
            'services.turnstile.fail_open' => false,
        ]);

        Http::fake(['challenges.cloudflare.com/*' => Http::response('', 500)]);

        $this->postJson('/api/auth/login', [
            'email' => 'cajero@cronos.pos',
            'password' => 'lo-que-sea',
            'cf_turnstile_token' => 'token-valido',
        ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'ERR_AUTH_CAPTCHA_UNAVAILABLE');
    }

    public function test_el_candado_se_evalua_antes_que_el_captcha(): void
    {
        config(['services.turnstile.secret_key' => self::SECRET]);
        Http::fake();

        $this->primeLockout('bloqueado@cronos.pos');

        // Un atacante bloqueado no debe poder gastarnos cuota de Cloudflare.
        $this->postJson('/api/auth/login', [
            'email' => 'bloqueado@cronos.pos',
            'password' => 'lo-que-sea',
            'cf_turnstile_token' => 'token-valido',
        ])
            ->assertStatus(429)
            ->assertJsonPath('code', 'ERR_AUTH_TOO_MANY_ATTEMPTS');

        Http::assertNothingSent();
    }
}
