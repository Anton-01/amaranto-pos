import { useState, useEffect } from 'react';
import { Dialog } from 'primereact/dialog';
import { InputText } from 'primereact/inputtext';
import { InputNumber } from 'primereact/inputnumber';
import { Dropdown } from 'primereact/dropdown';
import { Button } from 'primereact/button';
import { toast } from 'sonner';
import api from '../../api/axios';

const reasonOptions = [
  { label: 'Pago a proveedor', value: 'provider_payment' },
  { label: 'Compra de insumos', value: 'supplies_purchase' },
  { label: 'Entrega de cambio', value: 'change_delivery' },
  { label: 'Emergencia', value: 'emergency' },
];

const emptyForm = { amount: null, reason: null, description: '' };

export default function WithdrawModal({ visible, onHide, onSuccess }) {
  const [formData, setFormData] = useState({ ...emptyForm });
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (visible) setFormData({ ...emptyForm });
  }, [visible]);

  const set = (field, value) => setFormData(prev => ({ ...prev, [field]: value }));

  const handleSubmit = async (e) => {
    e.preventDefault();

    if (!formData.amount || formData.amount <= 0) {
      toast.error('El monto debe ser mayor a $0.');
      return;
    }
    if (!formData.reason) {
      toast.error('Selecciona un motivo.');
      return;
    }
    if (!formData.description.trim()) {
      toast.error('La descripcion es obligatoria.');
      return;
    }

    setSaving(true);
    try {
      const res = await api.post('/petty-cash', formData);
      toast.success('Retiro registrado', {
        description: `$${parseFloat(formData.amount).toFixed(2)} retirado exitosamente.`,
      });
      onSuccess?.(res.data.data);
      onHide();
    } catch (err) {
      const data = err.response?.data;
      if (data?.code === 'ERR_FIN_PETTY_CASH_LIMIT_EXCEEDED') {
        toast.error('Limite de caja chica excedido', { description: data.message });
      } else {
        const fieldErrors = data?.errors;
        if (fieldErrors) {
          Object.values(fieldErrors).flat().forEach(e => toast.error(e));
        } else {
          toast.error(data?.message || 'Error al registrar el retiro.');
        }
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog
      visible={visible}
      onHide={onHide}
      closable={!saving}
      dismissableMask={!saving}
      modal
      header={null}
      className="w-full max-w-md"
      pt={{
        mask: { className: 'backdrop-blur-sm bg-black/30' },
        root: { className: 'rounded-2xl border-0 shadow-2xl' },
        content: { className: 'p-0' },
      }}
    >
      <form onSubmit={handleSubmit} className="p-4 sm:p-6">
        <div className="mb-5 flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-full bg-amber-50">
            <svg className="h-5 w-5 text-amber-600" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V10.5Zm-12 0h.008v.008H6V10.5Z" />
            </svg>
          </div>
          <div>
            <h3 className="text-lg font-semibold text-slate-900">Retiro de Caja Chica</h3>
            <p className="text-xs text-slate-500">Este movimiento genera un comprobante inmutable.</p>
          </div>
        </div>

        <div className="space-y-4">
          <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">Monto *</label>
            <InputNumber
              value={formData.amount}
              onValueChange={(e) => set('amount', e.value)}
              mode="currency"
              currency="MXN"
              locale="es-MX"
              minFractionDigits={2}
              min={0.01}
              disabled={saving}
              className="w-full"
              inputClassName="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
              pt={{ root: { className: 'w-full' } }}
            />
          </div>

          <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">Motivo *</label>
            <Dropdown
              value={formData.reason}
              options={reasonOptions}
              onChange={(e) => set('reason', e.value)}
              placeholder="Seleccionar motivo"
              disabled={saving}
              className="w-full rounded-lg border-slate-200 text-sm"
              pt={{ root: { className: 'w-full' } }}
            />
          </div>

          <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">Descripcion *</label>
            <InputText
              value={formData.description}
              onChange={(e) => set('description', e.target.value)}
              placeholder="Describe el motivo detallado del retiro"
              disabled={saving}
              className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
              pt={{ root: { className: 'w-full' } }}
            />
          </div>
        </div>

        <div className="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">
          Al confirmar, se generara un sello SHA256 inmutable y se notificara a los administradores.
        </div>

        <div className="mt-5 flex flex-col-reverse gap-3 sm:flex-row">
          <Button
            type="button"
            label="Cancelar"
            onClick={onHide}
            disabled={saving}
            className="flex-1 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
            pt={{ root: { className: 'border border-slate-200' } }}
          />
          <Button
            type="submit"
            label={saving ? 'Registrando...' : 'Confirmar Retiro'}
            disabled={saving}
            loading={saving}
            className="flex-1 rounded-lg bg-amber-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-amber-500 disabled:opacity-50"
            pt={{ root: { className: 'border-0' } }}
          />
        </div>
      </form>
    </Dialog>
  );
}
