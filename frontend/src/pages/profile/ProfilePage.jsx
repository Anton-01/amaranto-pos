import { useState, useEffect, useCallback, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { InputText } from 'primereact/inputtext';
import { InputMask } from 'primereact/inputmask';
import { Dropdown } from 'primereact/dropdown';
import { Slider } from 'primereact/slider';
import { Checkbox } from 'primereact/checkbox';
import { Button } from 'primereact/button';
import { Tag } from 'primereact/tag';
import { toast } from 'sonner';
import api from '../../api/axios';
import { useAuth } from '../../context/AuthContext';
import AppLayout from '../../components/layout/AppLayout';

const countryOptions = [
  { label: 'Mexico (+52)', value: '+52', mask: '(999) 999-9999' },
  { label: 'USA (+1)', value: '+1', mask: '(999) 999-9999' },
];

function parseUA(ua) {
  if (!ua) return 'Desconocido';
  if (ua.includes('Chrome')) return 'Chrome';
  if (ua.includes('Firefox')) return 'Firefox';
  if (ua.includes('Safari')) return 'Safari';
  if (ua.includes('Edge')) return 'Edge';
  return ua.substring(0, 30) + '...';
}

function formatDate(d) {
  if (!d) return '--';
  return new Date(d).toLocaleString('es-MX', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function generatePassword(length, options) {
  const chars = {
    upper: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
    lower: 'abcdefghijklmnopqrstuvwxyz',
    numbers: '0123456789',
    symbols: '@#$%&*!?_-+=',
  };
  let pool = '';
  if (options.upper) pool += chars.upper;
  if (options.lower) pool += chars.lower;
  if (options.numbers) pool += chars.numbers;
  if (options.symbols) pool += chars.symbols;
  if (!pool) pool = chars.lower + chars.numbers;
  let result = '';
  const array = new Uint32Array(length);
  crypto.getRandomValues(array);
  for (let i = 0; i < length; i++) {
    result += pool[array[i] % pool.length];
  }
  return result;
}

export default function ProfilePage() {
  const { user, fetchUser, logout } = useAuth();
  const navigate = useNavigate();
  const [profile, setProfile] = useState(null);
  const [sessions, setSessions] = useState([]);
  const [loading, setLoading] = useState(true);

  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [phoneCountryCode, setPhoneCountryCode] = useState('+52');
  const [savingProfile, setSavingProfile] = useState(false);

  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [savingPassword, setSavingPassword] = useState(false);
  const [passwordError, setPasswordError] = useState('');

  const [genLength, setGenLength] = useState(16);
  const [genOptions, setGenOptions] = useState({ upper: true, lower: true, numbers: true, symbols: true });
  const [generatedPassword, setGeneratedPassword] = useState('');

  const fetchProfile = useCallback(async () => {
    setLoading(true);
    try {
      const [profileRes, sessionsRes] = await Promise.all([
        api.get('/profile'),
        api.get('/profile/sessions'),
      ]);
      const p = profileRes.data.data;
      setProfile(p);
      setName(p.name || '');
      setPhone(p.phone || '');
      setPhoneCountryCode(p.phone_country_code || '+52');
      setSessions(sessionsRes.data.data);
    } catch {
      toast.error('Error al cargar perfil.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchProfile(); }, [fetchProfile]);

  const selectedCountry = useMemo(() =>
    countryOptions.find(c => c.value === phoneCountryCode) || countryOptions[0],
  [phoneCountryCode]);

  const handleUpdateProfile = async (e) => {
    e.preventDefault();
    setSavingProfile(true);
    try {
      await api.put('/profile', { name, phone, phone_country_code: phoneCountryCode });
      toast.success('Perfil actualizado.');
      fetchUser();
    } catch {
      toast.error('Error al actualizar perfil.');
    } finally {
      setSavingProfile(false);
    }
  };

  const handleChangePassword = async (e) => {
    e.preventDefault();
    setPasswordError('');
    if (newPassword !== confirmPassword) {
      setPasswordError('Las contrasenas no coinciden.');
      return;
    }
    setSavingPassword(true);
    try {
      await api.put('/profile/password', {
        current_password: currentPassword,
        password: newPassword,
        password_confirmation: confirmPassword,
      });
      toast.success('Contrasena actualizada. Redirigiendo al login...');
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');
      setTimeout(async () => {
        await logout();
        navigate('/login');
      }, 1500);
    } catch (err) {
      const code = err.response?.data?.code;
      if (code === 'ERR_PROFILE_WRONG_PASSWORD') {
        setPasswordError('La contrasena actual es incorrecta.');
      } else {
        const errors = err.response?.data?.errors;
        if (errors) {
          setPasswordError(Object.values(errors).flat()[0]);
        } else {
          toast.error('Error al cambiar contrasena.');
        }
      }
    } finally {
      setSavingPassword(false);
    }
  };

  const handleRevokeSession = async (sessionId) => {
    try {
      await api.post(`/profile/sessions/${sessionId}/revoke`);
      toast.success('Sesion revocada.');
      fetchProfile();
    } catch {
      toast.error('Error al revocar sesion.');
    }
  };

  const handleGenerate = () => {
    setGeneratedPassword(generatePassword(genLength, genOptions));
  };

  const handleApplyGenerated = () => {
    if (!generatedPassword) { toast.warning('Genera una contrasena primero.'); return; }
    setNewPassword(generatedPassword);
    setConfirmPassword(generatedPassword);
    toast.success('Contrasena aplicada a los campos del formulario.');
  };

  const role = profile?.roles?.[0] || user?.roles?.[0] || 'vendor';
  const roleColors = { admin: 'danger', manager: 'warning', vendor: 'info' };
  const initials = (name || 'U').split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase();

  if (loading) {
    return (
      <AppLayout>
        <div className="flex h-64 items-center justify-center">
          <div className="h-8 w-8 animate-spin rounded-full border-4 border-indigo-600 border-t-transparent" />
        </div>
      </AppLayout>
    );
  }

  return (
    <AppLayout>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-900">Mi Perfil</h1>
        <p className="text-sm text-slate-500">Gestiona tu informacion personal y seguridad.</p>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {/* Personal Data */}
        <div className="rounded-xl bg-white p-4 shadow-sm sm:p-6 ring-1 ring-slate-200">
          <div className="mb-5 flex items-center gap-4">
            <div className="flex h-14 w-14 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-700 text-lg font-bold text-white shadow-md shadow-indigo-200">
              {initials}
            </div>
            <div>
              <h2 className="text-base font-semibold text-slate-900">Datos Personales</h2>
              <Tag value={role} severity={roleColors[role]} className="mt-1 text-xs capitalize" />
            </div>
          </div>

          <form onSubmit={handleUpdateProfile} className="space-y-4">
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Nombre</label>
              <InputText value={name} onChange={(e) => setName(e.target.value)} disabled={savingProfile}
                className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm" pt={{ root: { className: 'w-full' } }} />
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Email</label>
              <InputText value={profile?.email || ''} disabled
                className="w-full rounded-lg border-slate-200 bg-slate-50 px-3 py-2.5 text-sm" pt={{ root: { className: 'w-full' } }} />
              <p className="mt-1 text-xs text-slate-400">El email no es modificable.</p>
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Telefono</label>
              <div className="flex gap-2">
                <Dropdown
                  value={phoneCountryCode}
                  options={countryOptions}
                  onChange={(e) => { setPhoneCountryCode(e.value); setPhone(''); }}
                  disabled={savingProfile}
                  className="shrink-0 text-sm"
                  pt={{ root: { className: 'w-40' } }}
                />
                <InputMask
                  value={phone}
                  onChange={(e) => setPhone(e.target.value)}
                  mask={selectedCountry.mask}
                  placeholder={selectedCountry.mask.replace(/9/g, '_')}
                  disabled={savingProfile}
                  className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>
            </div>
            <Button
              type="submit"
              label={savingProfile ? 'Guardando...' : 'Guardar Cambios'}
              loading={savingProfile}
              disabled={savingProfile}
              className="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 cursor-pointer"
              pt={{ root: { className: 'border-0' } }}
            />
          </form>
        </div>

        {/* Password */}
        <div className="rounded-xl bg-white p-4 shadow-sm sm:p-6 ring-1 ring-slate-200">
          <h2 className="mb-5 text-base font-semibold text-slate-900">Seguridad</h2>
          <form onSubmit={handleChangePassword} className="space-y-4">
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Contrasena Actual</label>
              <InputText type="password" value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} disabled={savingPassword}
                className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm" pt={{ root: { className: 'w-full' } }} />
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Nueva Contrasena</label>
              <InputText type="password" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} disabled={savingPassword}
                className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm" pt={{ root: { className: 'w-full' } }} />
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Confirmar Nueva Contrasena</label>
              <InputText type="password" value={confirmPassword} onChange={(e) => setConfirmPassword(e.target.value)} disabled={savingPassword}
                className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm" pt={{ root: { className: 'w-full' } }} />
            </div>
            {passwordError && <p className="text-sm text-rose-500">{passwordError}</p>}
            <Button
              type="submit"
              label={savingPassword ? 'Cambiando...' : 'Cambiar Contrasena'}
              loading={savingPassword}
              disabled={savingPassword || !currentPassword || !newPassword || !confirmPassword}
              className="w-full rounded-lg bg-slate-800 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-700 cursor-pointer"
              pt={{ root: { className: 'border-0' } }}
            />
          </form>

          {/* Password Generator */}
          <div className="mt-5 rounded-lg border border-slate-200 p-4">
            <h3 className="mb-3 text-sm font-semibold text-slate-700">Generador de Contrasenas</h3>
            <div className="space-y-3">
              <div>
                <div className="flex items-center justify-between mb-1">
                  <label className="text-xs font-medium text-slate-600">Longitud: {genLength} caracteres</label>
                </div>
                <Slider value={genLength} onChange={(e) => setGenLength(e.value)} min={8} max={32} className="w-full" />
              </div>
              <div className="flex flex-wrap gap-3">
                {[
                  { key: 'upper', label: 'Mayusculas' },
                  { key: 'lower', label: 'Minusculas' },
                  { key: 'numbers', label: 'Numeros' },
                  { key: 'symbols', label: 'Simbolos (@#$)' },
                ].map(opt => (
                  <div key={opt.key} className="flex items-center gap-1.5">
                    <Checkbox
                      inputId={`gen_${opt.key}`}
                      checked={genOptions[opt.key]}
                      onChange={(e) => setGenOptions(prev => ({ ...prev, [opt.key]: e.checked }))}
                    />
                    <label htmlFor={`gen_${opt.key}`} className="text-xs text-slate-600 cursor-pointer">{opt.label}</label>
                  </div>
                ))}
              </div>
              {generatedPassword && (
                <div className="rounded-lg bg-slate-50 px-3 py-2 font-mono text-sm text-slate-800 break-all select-all ring-1 ring-slate-200">
                  {generatedPassword}
                </div>
              )}
              <div className="flex gap-2">
                <Button
                  type="button"
                  icon="pi pi-refresh"
                  label="Generar"
                  onClick={handleGenerate}
                  className="flex-1 rounded-lg bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 cursor-pointer"
                  pt={{ root: { className: 'border border-indigo-200' } }}
                />
                <Button
                  type="button"
                  label="Aplicar Contrasena"
                  onClick={handleApplyGenerated}
                  disabled={!generatedPassword}
                  className="flex-1 rounded-lg bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-100 disabled:opacity-50 cursor-pointer"
                  pt={{ root: { className: 'border border-emerald-200' } }}
                />
              </div>
            </div>
          </div>

          <div className="mt-5 rounded-lg bg-slate-50 p-4">
            <div className="flex items-center justify-between">
              <span className="text-sm text-slate-700">Autenticacion 2FA</span>
              <Tag
                value={profile?.two_factor_enabled ? 'HABILITADO' : 'DESHABILITADO'}
                severity={profile?.two_factor_enabled ? 'success' : 'warning'}
                className="text-xs"
              />
            </div>
          </div>
        </div>

        {/* Sessions */}
        <div className="rounded-xl bg-white p-4 shadow-sm sm:p-6 ring-1 ring-slate-200 lg:col-span-2">
          <h2 className="mb-4 text-base font-semibold text-slate-900">Auditoria de Sesiones</h2>
          {sessions.length === 0 ? (
            <p className="text-sm text-slate-400">Sin registros de sesion.</p>
          ) : (
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {sessions.map((session) => (
                <div key={session.id} className="rounded-lg border border-slate-200 p-3">
                  <div className="flex items-start justify-between">
                    <div>
                      <div className="flex items-center gap-2">
                        <span className="text-sm font-medium text-slate-900">{session.ip_address || '--'}</span>
                        {!session.logout_at && (
                          <span className="flex items-center gap-1 text-xs text-emerald-600">
                            <span className="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse" />
                            Activa
                          </span>
                        )}
                      </div>
                      <p className="mt-0.5 text-xs text-slate-500">{parseUA(session.user_agent)}</p>
                      <p className="text-xs text-slate-400">
                        {formatDate(session.login_at)}
                        {session.logout_at && <> · {formatDate(session.logout_at)}</>}
                      </p>
                    </div>
                    {!session.logout_at && (
                      <Button
                        icon="pi pi-sign-out"
                        severity="danger"
                        text
                        rounded
                        onClick={() => handleRevokeSession(session.id)}
                        className="cursor-pointer !h-8 !w-8"
                        tooltip="Revocar sesion"
                        tooltipOptions={{ position: 'top' }}
                      />
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </AppLayout>
  );
}
