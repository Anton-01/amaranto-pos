import { useState, useEffect } from 'react';
import { Tag } from 'primereact/tag';
import api from '../../api/axios';

const reasonLabels = {
  provider_payment: 'Pago a proveedor',
  supplies_purchase: 'Compra de insumos',
  change_delivery: 'Entrega de cambio',
  emergency: 'Emergencia',
};

export default function AuditTicket({ transactionId, onClose }) {
  const [data, setData] = useState(null);
  const [integrityValid, setIntegrityValid] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!transactionId) return;
    const fetch = async () => {
      setLoading(true);
      try {
        const res = await api.get(`/petty-cash/${transactionId}`);
        setData(res.data.data);
        setIntegrityValid(res.data.metadata.integrity_valid);
      } catch {
        setData(null);
      } finally {
        setLoading(false);
      }
    };
    fetch();
  }, [transactionId]);

  if (loading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className="h-6 w-6 animate-spin rounded-full border-3 border-indigo-600 border-t-transparent" />
      </div>
    );
  }

  if (!data) {
    return <p className="text-center text-sm text-slate-500">No se pudo cargar la transaccion.</p>;
  }

  const snapshot = data.immutable_snapshot;
  const seal = snapshot?.security_seal || '';
  const sealShort = seal.substring(0, 16) + '...' + seal.substring(seal.length - 16);

  return (
    <div className="mx-auto max-w-sm">
      {/* Thermal ticket style */}
      <div className="rounded-lg border-2 border-dashed border-slate-300 bg-white p-6 font-mono text-xs leading-relaxed">
        {/* Header */}
        <div className="mb-4 text-center">
          <p className="text-sm font-bold tracking-widest">CRONOS POS</p>
          <p className="text-slate-500">COMPROBANTE DE CAJA CHICA</p>
          <p className="mt-1 text-slate-400">{'='.repeat(36)}</p>
        </div>

        {/* Body */}
        <div className="space-y-2">
          <div className="flex justify-between">
            <span className="text-slate-500">Folio:</span>
            <span className="text-right font-semibold text-slate-900">{data.id.substring(0, 8).toUpperCase()}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-slate-500">Fecha:</span>
            <span className="text-slate-900">{snapshot?.timestamp ? new Date(snapshot.timestamp).toLocaleString('es-MX') : '-'}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-slate-500">Operador:</span>
            <span className="text-slate-900">{snapshot?.operator_name}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-slate-500">Email:</span>
            <span className="text-slate-900 text-right truncate ml-2">{snapshot?.operator_email}</span>
          </div>

          <p className="text-slate-400">{'-'.repeat(36)}</p>

          <div className="flex justify-between">
            <span className="text-slate-500">Motivo:</span>
            <span className="text-slate-900">{reasonLabels[snapshot?.reason] || snapshot?.reason}</span>
          </div>
          <div>
            <span className="text-slate-500">Descripcion:</span>
            <p className="mt-0.5 text-slate-900 break-words">{snapshot?.description}</p>
          </div>

          <p className="text-slate-400">{'-'.repeat(36)}</p>

          <div className="flex justify-between">
            <span className="text-slate-500">Saldo anterior:</span>
            <span className="text-slate-900">${parseFloat(snapshot?.balance_before || 0).toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
          </div>
          <div className="flex justify-between text-sm font-bold">
            <span className="text-slate-700">RETIRO:</span>
            <span className="text-rose-600">-${parseFloat(snapshot?.amount || 0).toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
          </div>
          <div className="flex justify-between">
            <span className="text-slate-500">Saldo posterior:</span>
            <span className="text-slate-900">${parseFloat(snapshot?.balance_after || 0).toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
          </div>
        </div>

        {/* Security seal */}
        <div className="mt-4">
          <p className="text-slate-400">{'-'.repeat(36)}</p>
          <div className="mt-2 text-center">
            <p className="text-slate-500 uppercase tracking-wider">Sello Criptografico SHA256</p>
            <p className="mt-1 break-all text-slate-700">{sealShort}</p>

            <div className="mt-3">
              {integrityValid === true ? (
                <Tag value="INTEGRIDAD VERIFICADA" severity="success" className="text-xs tracking-wide" />
              ) : integrityValid === false ? (
                <Tag value="INTEGRIDAD COMPROMETIDA" severity="danger" className="text-xs tracking-wide" />
              ) : (
                <Tag value="SIN VERIFICAR" severity="warning" className="text-xs tracking-wide" />
              )}
            </div>
          </div>
          <p className="mt-2 text-slate-400">{'-'.repeat(36)}</p>
        </div>

        {/* Footer */}
        <div className="mt-3 text-center text-slate-400">
          <p>Documento digital inmutable</p>
          <p>No requiere firma fisica</p>
        </div>
      </div>

      {onClose && (
        <button
          onClick={onClose}
          className="mt-4 w-full rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 transition-colors"
        >
          Cerrar
        </button>
      )}
    </div>
  );
}
