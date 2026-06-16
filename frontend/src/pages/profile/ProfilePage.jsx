import { useState, useEffect, useCallback } from 'react';
import { InputText } from 'primereact/inputtext';
import { Button } from 'primereact/button';
import { Tag } from 'primereact/tag';
import { toast } from 'sonner';
import api from '../../api/axios';
import { useAuth } from '../../context/AuthContext';
import AppLayout from '../../components/layout/AppLayout';

function parseUA(ua) {
  if (!ua) return 'Desconocido';
  if (ua.includes('Chrome')) return 'Chrome';
  if (ua.includes('Firefox')) return 'Firefox';
  if (ua.includes('Safari')) return 'Safari';
  if (ua.includes('Edge')) return 'Edge';
  return ua.substring(0, 30) + '...';
}

function formatDate(d) {
  if (!d) return '—';
  return new Date(d).toLocaleString('es-MX', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export default function ProfilePage() {
  const { user, fetchUser } = useAuth();
  const [profile, setProfile] = useState(null);
  const [sessions, setSessions] = useState([]);
  const [loading, setLoading] = useState(true);

  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [savingProfile, setSavingProfile] = useState(false);

  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [savingPassword, setSavingPassword] = useState(false);
  const [passwordError, setPasswordError] = useState('');

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
      setSessions(sessionsRes.data.data);
    } catch {
      toast.error('Error al cargar perfil.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchProfile(); }, [fetchProfile]);

  const handleUpdateProfile = async (e) => {
    e.preventDefault();
    setSavingProfile(true);
    try {
      await api.put('/profile', { name, phone });
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
      setPasswordError('Las contraseñas no coinciden.');
      return;
    }
    setSavingPassword(true);
    try {
      await api.put('/profile/password', {
        current_password: currentPassword,
        password: newPassword,
        password_confirmation: confirmPassword,
      });
      toast.success('Contraseña actualizada.');
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');
    } catch (err) {
      const code = err.response?.data?.code;
      if (code === 'ERR_PROFILE_WRONG_PASSWORD') {
        setPasswordError('La contraseña actual es incorrecta.');
      } else {
        const errors = err.response?.data?.errors;
        if (errors) {
          setPasswordError(Object.values(errors).flat()[0]);
        } else {
          toast.error('Error al cambiar contraseña.');
        }
      }
    } finally {
      setSavingPassword(false);
    }
  };

  const handleRevokeSession = async (sessionId) => {
    try {
      await api.post(`/profile/sessions/${sessionId}/revoke`);
      toast.success('Sesión revocada.');
      fetchProfile();
    } catch {
      toast.error('Error al revocar sesión.');
    }
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
        <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
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
              <InputText value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="Ej: 55-1234-5678" disabled={savingProfile}
                className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm" pt={{ root: { className: 'w-full' } }} />
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
        <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
          <h2 className="mb-5 text-base font-semibold text-slate-900">Seguridad</h2>
          <form onSubmit={handleChangePassword} className="space-y-4">
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Contraseña Actual</label>
              <InputText type="password" value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} disabled={savingPassword}
                className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm" pt={{ root: { className: 'w-full' } }} />
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Nueva Contraseña</label>
              <InputText type="password" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} disabled={savingPassword}
                className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm" pt={{ root: { className: 'w-full' } }} />
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Confirmar Nueva Contraseña</label>
              <InputText type="password" value={confirmPassword} onChange={(e) => setConfirmPassword(e.target.value)} disabled={savingPassword}
                className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm" pt={{ root: { className: 'w-full' } }} />
            </div>
            {passwordError && <p className="text-sm text-rose-500">{passwordError}</p>}
            <Button
              type="submit"
              label={savingPassword ? 'Cambiando...' : 'Cambiar Contraseña'}
              loading={savingPassword}
              disabled={savingPassword || !currentPassword || !newPassword || !confirmPassword}
              className="w-full rounded-lg bg-slate-800 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-700 cursor-pointer"
              pt={{ root: { className: 'border-0' } }}
            />
          </form>

          <div className="mt-6 rounded-lg bg-slate-50 p-4">
            <div className="flex items-center justify-between">
              <span className="text-sm text-slate-700">Autenticación 2FA</span>
              <Tag
                value={profile?.two_factor_enabled ? 'HABILITADO' : 'DESHABILITADO'}
                severity={profile?.two_factor_enabled ? 'success' : 'warning'}
                className="text-xs"
              />
            </div>
          </div>
        </div>

        {/* Sessions */}
        <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 lg:col-span-2">
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
                        <span className="text-sm font-medium text-slate-900">{session.ip_address || '—'}</span>
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
