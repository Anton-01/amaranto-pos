import { useCallback, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { InputText } from 'primereact/inputtext';
import { Password } from 'primereact/password';
import { Button } from 'primereact/button';
import { toast } from 'sonner';
import api from '../../api/axios';
import { useAuth } from '../../context/AuthContext';
import TwoFactorModal from '../../components/auth/TwoFactorModal';
import TurnstileWidget from '../../components/auth/TurnstileWidget';
import { TURNSTILE_ENABLED } from '../../lib/turnstile';
import useRateLimitLockout from '../../hooks/useRateLimitLockout';

export default function LoginPage() {
  const navigate = useNavigate();
  const { login } = useAuth();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [show2fa, setShow2fa] = useState(false);
  const [tempToken, setTempToken] = useState(null);
  const [verifying2fa, setVerifying2fa] = useState(false);

  // --- Escudo anti-bots (Cloudflare Turnstile) ---
  const [turnstileToken, setTurnstileToken] = useState(null);
  const [turnstileError, setTurnstileError] = useState(null);
  const [turnstileReset, setTurnstileReset] = useState(0);

  // --- Escudo de throttling (HTTP 429) ---
  const lockout = useRateLimitLockout();

  const handleTurnstileToken = useCallback((token) => {
    setTurnstileToken(token);
    if (token) {
      setTurnstileError(null);
    }
  }, []);

  const handleTurnstileError = useCallback((message) => {
    setTurnstileError(message);
  }, []);

  // El token de Turnstile es de un solo uso: tras cada intento hay que
  // solicitar uno nuevo antes de permitir el siguiente envio.
  const refreshTurnstile = useCallback(() => {
    if (TURNSTILE_ENABLED) {
      setTurnstileReset((n) => n + 1);
    }
  }, []);

  const handleLogin = async (e) => {
    e.preventDefault();

    if (lockout.locked) {
      return;
    }

    setLoading(true);

    try {
      const payload = { email, password };
      if (turnstileToken) {
        payload.cf_turnstile_token = turnstileToken;
      }

      const response = await api.post('/auth/login', payload);
      const data = response.data;

      if (data.status === '2FA_CHALLENGE') {
        setTempToken(data.metadata.temp_token);
        setShow2fa(true);
        toast.info('Verificacion requerida', {
          description: 'Ingresa el codigo de tu app de autenticacion.',
        });
      } else if (data.status === 'success') {
        lockout.clear();
        login(data.data.token, data.data.user);
        toast.success('Bienvenido', {
          description: `Hola, ${data.data.user.name}`,
        });
        navigate('/dashboard');
      }
    } catch (error) {
      const status = error.response?.status;
      const data = error.response?.data;

      if (status === 429) {
        // El servidor manda `Retry-After`; el interceptor ya lo normalizo.
        lockout.start(error.retryAfter ?? data?.metadata?.retry_after_seconds ?? 60);
        setPassword('');
      } else {
        const remaining = data?.metadata?.remaining_attempts;

        toast.error('Error de autenticacion', {
          description:
            typeof remaining === 'number' && remaining > 0
              ? `${data?.message || 'Credenciales incorrectas.'} Te quedan ${remaining} intento${remaining === 1 ? '' : 's'}.`
              : data?.message || 'Ocurrio un error inesperado.',
        });
      }

      refreshTurnstile();
    } finally {
      setLoading(false);
    }
  };

  const handle2faVerify = async (code) => {
    setVerifying2fa(true);

    try {
      const response = await api.post('/auth/2fa/verify', { code }, {
        headers: { Authorization: `Bearer ${tempToken}` },
      });

      const data = response.data;
      login(data.data.token, data.data.user);
      setShow2fa(false);
      setTempToken(null);
      toast.success('Bienvenido', {
        description: `Hola, ${data.data.user.name}`,
      });
      navigate('/dashboard');
    } catch (error) {
      const status = error.response?.status;
      const data = error.response?.data;

      if (status === 429) {
        setShow2fa(false);
        setTempToken(null);
        lockout.start(error.retryAfter ?? 60);
      } else {
        toast.error('Codigo incorrecto', {
          description: data?.message || 'Verifica el codigo e intenta de nuevo.',
        });
      }
    } finally {
      setVerifying2fa(false);
    }
  };

  const handleCancel2fa = () => {
    setShow2fa(false);
    setTempToken(null);
  };

  const submitDisabled = loading || lockout.locked || !email || !password;

  const buttonLabel = lockout.locked
    ? `Acceso bloqueado — ${lockout.countdown}`
    : loading
      ? 'Iniciando sesion...'
      : 'Iniciar Sesion';

  return (
    <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 px-4">
      <div className="w-full max-w-md">
        <div className="rounded-2xl bg-white/95 p-8 shadow-2xl shadow-black/20 ring-1 ring-white/10 backdrop-blur-sm">
          <div className="mb-8 text-center">
            <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-xl bg-indigo-600 shadow-lg shadow-indigo-200">
              <svg className="h-7 w-7 text-white" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 0 0 2.25 1.016c.896 0 1.7-.393 2.25-1.015a3.001 3.001 0 0 0 3.75.614m-16.5 0a3.004 3.004 0 0 1-.621-4.72l1.189-1.19A1.5 1.5 0 0 1 5.378 3h13.243a1.5 1.5 0 0 1 1.06.44l1.19 1.189a3 3 0 0 1-.621 4.72M6.75 18h3.75a.75.75 0 0 0 .75-.75V13.5a.75.75 0 0 0-.75-.75H6.75a.75.75 0 0 0-.75.75v3.75c0 .414.336.75.75.75Z" />
              </svg>
            </div>
            <h1 className="text-2xl font-bold tracking-tight text-slate-900">
              Cronos POS
            </h1>
            <p className="mt-1 text-sm text-slate-500">
              Ingresa tus credenciales para continuar
            </p>
          </div>

          {lockout.locked && (
            <div
              role="alert"
              aria-live="assertive"
              className="mb-6 rounded-xl border border-rose-200 bg-rose-50 p-4"
            >
              <div className="flex items-start gap-3">
                <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-rose-100">
                  <i className="pi pi-lock text-sm text-rose-600" />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-semibold text-rose-900">
                    Acceso bloqueado temporalmente
                  </p>
                  <p className="mt-1 text-sm leading-relaxed text-rose-700">
                    Por seguridad, tu acceso ha sido bloqueado temporalmente.
                    Intenta nuevamente en {lockout.humanized}.
                  </p>
                  <div className="mt-3 flex items-center gap-2">
                    <span className="font-mono text-lg font-bold tabular-nums text-rose-900">
                      {lockout.countdown}
                    </span>
                    <span className="text-xs font-medium uppercase tracking-wide text-rose-500">
                      restante
                    </span>
                  </div>
                </div>
              </div>
            </div>
          )}

          <form onSubmit={handleLogin} className="space-y-5">
            <div>
              <label htmlFor="email" className="mb-1.5 block text-sm font-medium text-slate-700">
                Correo electronico
              </label>
              <InputText
                id="email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="usuario@cronos.pos"
                required
                disabled={loading || lockout.locked}
                className="login-field border-slate-200 shadow-sm transition-all focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
              />
            </div>

            <div>
              <label htmlFor="password" className="mb-1.5 block text-sm font-medium text-slate-700">
                Contrasena
              </label>
              <Password
                id="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="••••••••"
                required
                disabled={loading || lockout.locked}
                toggleMask
                feedback={false}
                className="login-password"
                inputClassName="login-field border-slate-200 shadow-sm transition-all focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
              />
            </div>

            {/*
              Turnstile invisible: no ocupa espacio ni exige interaccion salvo
              que Cloudflare detecte trafico sospechoso.
            */}
            <TurnstileWidget
              onToken={handleTurnstileToken}
              onError={handleTurnstileError}
              resetSignal={turnstileReset}
            />

            {turnstileError && !lockout.locked && (
              <p className="flex items-start gap-2 text-xs leading-relaxed text-amber-700">
                <i className="pi pi-exclamation-triangle mt-0.5 text-[11px]" />
                <span>
                  {turnstileError} Revisa tu conexion y recarga la pagina si el
                  acceso es rechazado.
                </span>
              </p>
            )}

            <Button
              type="submit"
              label={buttonLabel}
              icon={lockout.locked ? 'pi pi-lock' : undefined}
              disabled={submitDisabled}
              loading={loading}
              className="w-full rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-200 hover:bg-indigo-500 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-600 disabled:opacity-50 transition-all duration-200"
              pt={{ root: { className: 'border-0' } }}
            />
          </form>
        </div>

        <p className="mt-6 text-center text-xs text-slate-400/70">
          Sistema protegido con autenticacion de dos factores
          {TURNSTILE_ENABLED && ' y verificacion anti-bots'}
        </p>
      </div>

      <TwoFactorModal
        visible={show2fa}
        onSubmit={handle2faVerify}
        onCancel={handleCancel2fa}
        loading={verifying2fa}
      />
    </div>
  );
}
