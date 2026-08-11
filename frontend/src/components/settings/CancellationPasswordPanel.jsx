import { useState, useEffect, useCallback } from 'react';
import { Password } from 'primereact/password';
import { Button } from 'primereact/button';
import { Tag } from 'primereact/tag';
import { toast } from 'sonner';
import api from '../../api/axios';

/**
 * Authorization password used to release table cancellations.
 *
 * It is stored hashed and is deliberately independent of every login
 * credential: a supervisor hands it to a shift lead so they can void a table
 * without ever sharing their own account. Because only the hash is kept, the
 * panel can report whether a password exists and when it was last rotated, but
 * never show it — the only operation available is replacing it.
 */
export default function CancellationPasswordPanel() {
  const [status, setStatus] = useState({ is_configured: false, updated_at: null });
  const [loading, setLoading] = useState(true);
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [saving, setSaving] = useState(false);
  const [fieldErrors, setFieldErrors] = useState({});

  const fetchStatus = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get('/admin/settings/cancellation-password');
      setStatus(res.data.data);
    } catch {
      toast.error('Error al consultar la contraseña de autorización.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchStatus(); }, [fetchStatus]);

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    setFieldErrors({});

    try {
      const res = await api.put('/admin/settings/cancellation-password', {
        password,
        password_confirmation: confirmation,
      });
      setStatus(res.data.data);
      setPassword('');
      setConfirmation('');
      toast.success('Contraseña de autorización actualizada.');
    } catch (err) {
      const errors = err.response?.data?.errors;
      if (errors) {
        setFieldErrors(Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v[0]])));
        toast.warning('Verifica los campos marcados.');
      } else {
        toast.error(err.response?.data?.message || 'Error al guardar la contraseña.');
      }
    } finally {
      setSaving(false);
    }
  };

  const canSave = password.length >= 6 && password === confirmation;

  return (
    <div className="max-w-lg space-y-5 p-6">
      <div>
        <div className="flex flex-wrap items-center gap-2">
          <h3 className="text-sm font-semibold text-slate-900">Contraseña de Autorización para Cancelaciones</h3>
          {!loading && (
            <Tag
              value={status.is_configured ? 'Configurada' : 'Sin configurar'}
              severity={status.is_configured ? 'success' : 'warning'}
              className="text-xs"
            />
          )}
        </div>
        <p className="mt-1.5 text-sm text-slate-500">
          Se solicita a los usuarios <strong>sin rol de administrador</strong> al cancelar una mesa.
          Un administrador cancela con su propio rol y nunca ve este prompt.
        </p>
        <p className="mt-1.5 text-xs text-slate-400">
          Se guarda cifrada (hash) y no está ligada a la contraseña de acceso de ningún usuario:
          puedes compartirla con un encargado de turno sin entregar tu cuenta, y rotarla cuando quieras.
        </p>
        {status.updated_at && (
          <p className="mt-1.5 text-xs text-slate-400">
            Última actualización: {new Date(status.updated_at).toLocaleString('es-MX')}
          </p>
        )}
      </div>

      <form onSubmit={handleSave} className="space-y-4">
        <div>
          <label className="mb-1.5 block text-sm font-medium text-slate-700">
            {status.is_configured ? 'Nueva contraseña' : 'Contraseña'}
          </label>
          <Password
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            feedback={false}
            toggleMask
            disabled={saving}
            placeholder="Mínimo 6 caracteres"
            className="w-full"
            inputClassName="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
            pt={{ root: { className: 'w-full' } }}
          />
          {fieldErrors.password && <p className="mt-1 text-xs text-red-500">{fieldErrors.password}</p>}
        </div>

        <div>
          <label className="mb-1.5 block text-sm font-medium text-slate-700">Confirmar contraseña</label>
          <Password
            value={confirmation}
            onChange={(e) => setConfirmation(e.target.value)}
            feedback={false}
            toggleMask
            disabled={saving}
            placeholder="Repite la contraseña"
            className="w-full"
            inputClassName="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
            pt={{ root: { className: 'w-full' } }}
          />
          {confirmation.length > 0 && password !== confirmation && (
            <p className="mt-1 text-xs text-red-500">Las contraseñas no coinciden.</p>
          )}
        </div>

        <Button
          type="submit"
          label={saving ? 'Guardando...' : status.is_configured ? 'Rotar Contraseña' : 'Definir Contraseña'}
          loading={saving}
          disabled={saving || !canSave}
          className="cursor-pointer rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
          pt={{ root: { className: 'border-0' } }}
        />
      </form>
    </div>
  );
}
