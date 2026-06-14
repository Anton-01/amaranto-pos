import { useState, useRef, useEffect } from 'react';
import { Dialog } from 'primereact/dialog';
import { InputOtp } from 'primereact/inputotp';
import { Button } from 'primereact/button';

export default function TwoFactorModal({ visible, onSubmit, onCancel, loading }) {
  const [code, setCode] = useState('');

  useEffect(() => {
    if (visible) setCode('');
  }, [visible]);

  const handleSubmit = (e) => {
    e.preventDefault();
    if (code.length === 6) {
      onSubmit(code);
    }
  };

  return (
    <Dialog
      visible={visible}
      onHide={onCancel}
      closable={!loading}
      closeOnEscape={!loading}
      dismissableMask={false}
      modal
      header={null}
      className="w-full max-w-md"
      pt={{
        mask: { className: 'backdrop-blur-sm bg-black/30' },
        root: { className: 'rounded-2xl border-0 shadow-2xl' },
        content: { className: 'p-0' },
      }}
    >
      <form onSubmit={handleSubmit} className="p-8 text-center">
        <div className="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-full bg-indigo-50">
          <svg className="h-8 w-8 text-indigo-600" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
          </svg>
        </div>

        <h2 className="mb-2 text-xl font-semibold text-slate-900">
          Verificacion de Seguridad
        </h2>
        <p className="mb-8 text-sm text-slate-500">
          Ingresa el codigo de 6 digitos de tu app de autenticacion.
        </p>

        <div className="mb-8 flex justify-center">
          <InputOtp
            value={code}
            onChange={(e) => setCode(e.value ?? '')}
            length={6}
            integerOnly
            disabled={loading}
            pt={{
              input: {
                root: {
                  className: 'w-12 h-14 text-center text-lg font-semibold border-2 border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 transition-all duration-200',
                },
              },
            }}
          />
        </div>

        <Button
          type="submit"
          label={loading ? 'Verificando...' : 'Verificar Codigo'}
          disabled={code.length !== 6 || loading}
          loading={loading}
          className="w-full rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-600 disabled:opacity-50 transition-all duration-200"
          pt={{ root: { className: 'border-0' } }}
        />

        <button
          type="button"
          onClick={onCancel}
          disabled={loading}
          className="mt-4 text-sm text-slate-500 hover:text-slate-700 transition-colors"
        >
          Cancelar
        </button>
      </form>
    </Dialog>
  );
}
