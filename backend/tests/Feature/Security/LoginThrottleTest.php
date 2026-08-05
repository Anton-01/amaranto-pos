<?php

namespace Tests\Feature\Security;

use App\Support\LoginThrottle;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Politica anti fuerza bruta del login (Fase 9), probada directamente sobre
 * el componente: 5 intentos fallidos por minuto sobre la clave email + IP y,
 * al desbordarla, candado de 15 minutos.
 */
class LoginThrottleTest extends TestCase
{
    private function request(string $email = 'cajero@cronos.pos', string $ip = '187.190.1.10'): Request
    {
        return Request::create(
            '/api/auth/login',
            'POST',
            ['email' => $email],
            server: ['REMOTE_ADDR' => $ip]
        );
    }

    public function test_tolera_cinco_fallos_y_al_sexto_intento_activa_el_candado(): void
    {
        $request = $this->request();

        for ($i = 0; $i < 5; $i++) {
            $this->assertNull(
                LoginThrottle::lockedSeconds($request),
                'El intento #'.($i + 1).' no debia estar bloqueado.'
            );
            LoginThrottle::recordFailure($request);
        }

        $seconds = LoginThrottle::lockedSeconds($request);

        $this->assertSame(900, $seconds, 'El candado debe durar 15 minutos.');
    }

    public function test_el_candado_persiste_en_peticiones_posteriores(): void
    {
        $request = $this->request();

        for ($i = 0; $i < 5; $i++) {
            LoginThrottle::recordFailure($request);
        }

        LoginThrottle::lockedSeconds($request);

        $remaining = LoginThrottle::lockedSeconds($this->request());

        $this->assertNotNull($remaining);
        $this->assertGreaterThan(0, $remaining);
        $this->assertLessThanOrEqual(900, $remaining);
    }

    public function test_la_identidad_combina_email_e_ip(): void
    {
        $victima = $this->request('cajero@cronos.pos', '187.190.1.10');

        for ($i = 0; $i < 5; $i++) {
            LoginThrottle::recordFailure($victima);
        }
        LoginThrottle::lockedSeconds($victima);

        // Mismo cajero desde otro equipo de la sucursal: no queda bloqueado.
        $this->assertNull(LoginThrottle::lockedSeconds($this->request('cajero@cronos.pos', '187.190.1.11')));

        // Misma IP, otra cuenta: tampoco. Evita que un atacante bloquee a
        // toda la sucursal fallando adrede contra un correo conocido.
        $this->assertNull(LoginThrottle::lockedSeconds($this->request('gerente@cronos.pos', '187.190.1.10')));
    }

    public function test_el_email_se_normaliza_a_minusculas(): void
    {
        for ($i = 0; $i < 5; $i++) {
            LoginThrottle::recordFailure($this->request('Cajero@Cronos.POS'));
        }

        $this->assertSame(900, LoginThrottle::lockedSeconds($this->request('cajero@cronos.pos')));
    }

    public function test_un_login_exitoso_libera_contador_y_candado(): void
    {
        $request = $this->request();

        for ($i = 0; $i < 5; $i++) {
            LoginThrottle::recordFailure($request);
        }
        LoginThrottle::lockedSeconds($request);

        LoginThrottle::clear($request);

        $this->assertNull(LoginThrottle::lockedSeconds($request));
        $this->assertSame(5, LoginThrottle::remainingAttempts($request));
    }

    public function test_expone_los_intentos_restantes_para_avisar_al_cajero(): void
    {
        $request = $this->request();

        $this->assertSame(5, LoginThrottle::remainingAttempts($request));

        LoginThrottle::recordFailure($request);
        $this->assertSame(4, LoginThrottle::remainingAttempts($request));

        LoginThrottle::recordFailure($request);
        $this->assertSame(3, LoginThrottle::remainingAttempts($request));
    }
}
