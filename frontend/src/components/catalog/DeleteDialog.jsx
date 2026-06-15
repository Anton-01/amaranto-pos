import { useState } from 'react';
import { Dialog } from 'primereact/dialog';
import { InputText } from 'primereact/inputtext';
import { Button } from 'primereact/button';

export default function DeleteDialog({ visible, onHide, onConfirm, loading, itemName }) {
  const [reason, setReason] = useState('');

  const handleConfirm = () => {
    if (reason.trim()) {
      onConfirm(reason.trim());
      setReason('');
    }
  };

  const handleHide = () => {
    setReason('');
    onHide();
  };

  return (
    <Dialog
      visible={visible}
      onHide={handleHide}
      closable={!loading}
      dismissableMask={!loading}
      modal
      header={null}
      className="w-full max-w-md"
      pt={{
        mask: { className: 'backdrop-blur-sm bg-black/30' },
        root: { className: 'rounded-2xl border-0 shadow-2xl' },
        content: { className: 'p-0' },
      }}
    >
      <div className="p-6">
        <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-rose-50">
          <svg className="h-6 w-6 text-rose-600" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
          </svg>
        </div>

        <h3 className="mb-1 text-center text-lg font-semibold text-slate-900">
          Eliminar {itemName}
        </h3>
        <p className="mb-5 text-center text-sm text-slate-500">
          Esta accion movera el registro a la papelera. Indica el motivo.
        </p>

        <div className="mb-5">
          <label className="mb-1.5 block text-sm font-medium text-slate-700">
            Motivo de eliminacion *
          </label>
          <InputText
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="Ej: Producto descontinuado"
            disabled={loading}
            className="w-full rounded-lg border-slate-200 px-3 py-2 text-sm"
            pt={{ root: { className: 'w-full' } }}
          />
        </div>

        <div className="flex gap-3">
          <Button
            label="Cancelar"
            onClick={handleHide}
            disabled={loading}
            className="flex-1 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
            pt={{ root: { className: 'border border-slate-200' } }}
          />
          <Button
            label={loading ? 'Eliminando...' : 'Eliminar'}
            onClick={handleConfirm}
            disabled={!reason.trim() || loading}
            loading={loading}
            className="flex-1 rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-rose-500 disabled:opacity-50"
            pt={{ root: { className: 'border-0' } }}
          />
        </div>
      </div>
    </Dialog>
  );
}
