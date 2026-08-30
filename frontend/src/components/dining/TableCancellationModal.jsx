import { useState, useEffect } from 'react';
import { Dialog } from 'primereact/dialog';
import { InputTextarea } from 'primereact/inputtextarea';
import { Password } from 'primereact/password';
import { Button } from 'primereact/button';
import { toast } from 'sonner';
import api from '../../api/axios';
import { useAuth } from '../../context/AuthContext';
import { fmtCurrency } from './tableStatus';
import { dialogClass, DIALOG_PT } from '../../lib/responsive';

/**
 * Strict confirmation for voiding a live table account.
 *
 * Two things gate the operation. The reason is always mandatory — it is the
 * text an auditor reads months later to tell a legitimate void from a leak.
 * The authorization password is only asked of non-admins: an administrator
 * already holds the authority the shared password exists to delegate, so the
 * field is hidden for them rather than merely optional.
 *
 * The frontend decides what to *show*; the backend independently re-checks the
 * role and the hash, so hiding the field is a convenience, never the control.
 */
export default function TableCancellationModal({ visible, table, session, onHide, onCanceled }) {
  const { user } = useAuth();
  const isAdmin = user?.roles?.includes('admin');

  const [reason, setReason] = useState('');
  const [authorizationPassword, setAuthorizationPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState({});

  useEffect(() => {
    if (!visible) return;
    setReason('');
    setAuthorizationPassword('');
    setFieldErrors({});
  }, [visible]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSubmitting(true);
    setFieldErrors({});

    try {
      const res = await api.post(`/tables/${table.id}/cancel`, {
        reason: reason.trim(),
        // Admins never send a password: the backend authorizes them by role.
        authorization_password: isAdmin ? undefined : authorizationPassword,
      });

      toast.success('Mesa cancelada', {
        description: `Se anuló un consumo de ${fmtCurrency(res.data.data?.canceled_amount)}. La mesa quedó libre.`,
      });
      onCanceled?.(res.data.data);
    } catch (err) {
      const data = err.response?.data;

      if (data?.errors) {
        setFieldErrors(Object.fromEntries(Object.entries(data.errors).map(([k, v]) => [k, v[0]])));
        toast.warning('Verifica los campos marcados.');
      } else if (data?.code === 'ERR_TABLE_CANCEL_UNAUTHORIZED') {
        setFieldErrors({ authorization_password: 'Contraseña de autorización incorrecta.' });
        toast.error('Autorización rechazada.');
      } else if (data?.code === 'ERR_CANCEL_PASSWORD_NOT_CONFIGURED') {
        toast.error('Sin contraseña de autorización', { description: data.message });
      } else if (data?.code === 'ERR_TABLE_NO_OPEN_SESSION') {
        toast.info('La mesa ya no tiene una cuenta abierta.');
        onCanceled?.(null);
      } else {
        toast.error(data?.message || 'Error al cancelar la mesa.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  const canSubmit = reason.trim().length >= 5 && (isAdmin || authorizationPassword.length > 0);

  return (
    <Dialog
      visible={visible}
      onHide={() => !submitting && onHide()}
      modal
      draggable={false}
      closable={!submitting}
      header={
        <div className="flex items-center gap-2">
          <i className="pi pi-exclamation-triangle text-rose-500" />
          <span className="font-bold text-slate-900">Cancelar Mesa</span>
        </div>
      }
      className={dialogClass('md')}
      pt={DIALOG_PT}
    >
      <form onSubmit={handleSubmit} className="space-y-4">
        <div className="rounded-lg border border-rose-100 bg-rose-50 px-3 py-2.5 text-sm text-rose-700">
          Se anulará el consumo de <strong>{table?.name}</strong> por{' '}
          <strong>{fmtCurrency(session?.total)}</strong> ({session?.items?.length ?? 0} partida
          {(session?.items?.length ?? 0) !== 1 ? 's' : ''}) y la mesa quedará libre.
          El movimiento queda registrado con tu usuario y no puede deshacerse.
        </div>

        <div>
          <label className="mb-1.5 block text-sm font-medium text-slate-700">Motivo de cancelación *</label>
          <InputTextarea
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            rows={3}
            autoResize
            disabled={submitting}
            placeholder="Ej. El cliente se retiró antes de consumir; se comandó en la mesa equivocada..."
            className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
            pt={{ root: { className: 'w-full' } }}
          />
          <p className="mt-1 text-xs text-slate-400">
            Quedará asentado en la auditoría. Sé específico: mínimo 5 caracteres.
          </p>
          {fieldErrors.reason && <p className="mt-1 text-xs text-red-500">{fieldErrors.reason}</p>}
        </div>

        {isAdmin ? (
          <div className="flex items-start gap-2 rounded-lg bg-indigo-50 px-3 py-2.5">
            <i className="pi pi-shield mt-0.5 text-indigo-500" />
            <p className="text-xs text-indigo-700">
              Tu rol de administrador autoriza la cancelación directamente. No se requiere contraseña.
            </p>
          </div>
        ) : (
          <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">Contraseña de Autorización *</label>
            <Password
              value={authorizationPassword}
              onChange={(e) => setAuthorizationPassword(e.target.value)}
              feedback={false}
              toggleMask
              disabled={submitting}
              placeholder="Solicítala a un administrador"
              className="w-full"
              inputClassName="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
              pt={{ root: { className: 'w-full' } }}
            />
            <p className="mt-1 text-xs text-slate-400">
              Es la contraseña definida en Configuración del Sistema, no la de tu cuenta.
            </p>
            {fieldErrors.authorization_password && (
              <p className="mt-1 text-xs text-red-500">{fieldErrors.authorization_password}</p>
            )}
          </div>
        )}

        <div className="flex flex-col-reverse gap-2 pt-1 sm:flex-row sm:justify-end">
          <Button
            type="button"
            label="Volver"
            severity="secondary"
            text
            onClick={onHide}
            disabled={submitting}
            className="cursor-pointer"
          />
          <Button
            type="submit"
            label={submitting ? 'Cancelando...' : 'Cancelar Mesa'}
            severity="danger"
            loading={submitting}
            disabled={submitting || !canSubmit}
            className="cursor-pointer rounded-lg px-5 py-2.5 text-sm font-semibold"
          />
        </div>
      </form>
    </Dialog>
  );
}
