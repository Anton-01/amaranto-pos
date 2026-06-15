import { useState, useEffect, useCallback } from 'react';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { InputText } from 'primereact/inputtext';
import { Tag } from 'primereact/tag';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { toast } from 'sonner';
import api from '../../api/axios';
import { useAuth } from '../../context/AuthContext';
import AppLayout from '../../components/layout/AppLayout';
import WithdrawModal from '../../components/finance/WithdrawModal';
import AuditTicket from '../../components/finance/AuditTicket';

const reasonLabels = {
  provider_payment: 'Pago a proveedor',
  supplies_purchase: 'Compra de insumos',
  change_delivery: 'Entrega de cambio',
  emergency: 'Emergencia',
};

const reasonColors = {
  provider_payment: 'info',
  supplies_purchase: 'warning',
  change_delivery: 'success',
  emergency: 'danger',
};

export default function PettyCashPage() {
  const { user } = useAuth();
  const isAdmin = user?.roles?.some(r => r === 'admin' || r === 'manager');

  const [transactions, setTransactions] = useState([]);
  const [summary, setSummary] = useState(null);
  const [loading, setLoading] = useState(true);
  const [globalFilter, setGlobalFilter] = useState('');
  const [showWithdraw, setShowWithdraw] = useState(false);
  const [auditTarget, setAuditTarget] = useState(null);

  const fetchData = useCallback(async () => {
    setLoading(true);
    try {
      const [txRes, sumRes] = await Promise.all([
        api.get('/petty-cash'),
        api.get('/petty-cash/summary'),
      ]);
      setTransactions(txRes.data.data);
      setSummary(sumRes.data.data);
    } catch {
      toast.error('Error al cargar datos de caja chica.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchData(); }, [fetchData]);

  const handleWithdrawSuccess = () => {
    fetchData();
  };

  const amountTemplate = (row) => (
    <span className="font-semibold text-rose-600">
      -${parseFloat(row.amount).toLocaleString('es-MX', { minimumFractionDigits: 2 })}
    </span>
  );

  const reasonTemplate = (row) => (
    <Tag
      value={reasonLabels[row.reason] || row.reason}
      severity={reasonColors[row.reason] || 'info'}
      className="text-xs"
    />
  );

  const dateTemplate = (row) => {
    const d = new Date(row.created_at);
    return d.toLocaleString('es-MX', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  };

  const sealTemplate = (row) => {
    const seal = row.immutable_snapshot?.security_seal;
    if (!seal) return <Tag value="SIN SELLO" severity="danger" className="text-xs" />;
    return (
      <span className="font-mono text-xs text-slate-500" title={seal}>
        {seal.substring(0, 12)}...
      </span>
    );
  };

  const actionsTemplate = (row) => (
    <button
      onClick={() => setAuditTarget(row.id)}
      className="text-indigo-600 hover:text-indigo-800 text-sm font-medium"
    >
      Auditar
    </button>
  );

  return (
    <AppLayout>
      {/* Summary cards */}
      {summary && (
        <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <p className="text-sm text-slate-500">Retiros Hoy</p>
            <p className="mt-1 text-2xl font-bold text-slate-900">{summary.transactions_today}</p>
            <p className="mt-0.5 text-sm text-rose-600">-${parseFloat(summary.total_today).toLocaleString('es-MX', { minimumFractionDigits: 2 })}</p>
          </div>
          <div className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <p className="text-sm text-slate-500">Total Acumulado</p>
            <p className="mt-1 text-2xl font-bold text-rose-600">
              -${parseFloat(summary.total_withdrawals).toLocaleString('es-MX', { minimumFractionDigits: 2 })}
            </p>
          </div>
          <div className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <p className="text-sm text-slate-500">Desglose por Motivo</p>
            <div className="mt-2 space-y-1">
              {summary.by_reason?.map((r, i) => (
                <div key={i} className="flex justify-between text-xs">
                  <span className="text-slate-600">{reasonLabels[r.reason] || r.reason}</span>
                  <span className="font-medium text-slate-900">{r.count}x (${parseFloat(r.total).toFixed(2)})</span>
                </div>
              ))}
              {(!summary.by_reason || summary.by_reason.length === 0) && (
                <p className="text-xs text-slate-400">Sin movimientos</p>
              )}
            </div>
          </div>
        </div>
      )}

      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Caja Chica</h1>
          <p className="text-sm text-slate-500">Registro de egresos con auditoria inmutable SHA256.</p>
        </div>
        <Button
          label="Nuevo Retiro"
          onClick={() => setShowWithdraw(true)}
          className="rounded-lg bg-amber-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-amber-500"
          pt={{ root: { className: 'border-0' } }}
        />
      </div>

      <div className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <div className="border-b border-slate-200 p-4">
          <InputText
            value={globalFilter}
            onChange={(e) => setGlobalFilter(e.target.value)}
            placeholder="Buscar por operador o descripcion..."
            className="w-72 rounded-lg border-slate-200 px-3 py-2 text-sm"
            pt={{ root: { className: 'w-72' } }}
          />
        </div>

        <DataTable
          value={transactions}
          loading={loading}
          globalFilter={globalFilter}
          emptyMessage="No hay registros de caja chica."
          sortField="created_at"
          sortOrder={-1}
          stripedRows
          paginator
          rows={15}
          pt={{ root: { className: 'text-sm' }, thead: { className: 'bg-slate-50' } }}
        >
          <Column field="created_at" header="Fecha" body={dateTemplate} sortable style={{ width: '18%' }} />
          <Column field="user.name" header="Operador" sortable style={{ width: '15%' }} />
          <Column field="amount" header="Monto" body={amountTemplate} sortable style={{ width: '12%' }} />
          <Column field="reason" header="Motivo" body={reasonTemplate} sortable style={{ width: '15%' }} />
          <Column field="description" header="Descripcion" sortable className="truncate max-w-xs" />
          <Column header="Sello" body={sealTemplate} style={{ width: '12%' }} />
          <Column header="" body={actionsTemplate} style={{ width: '8%' }} />
        </DataTable>
      </div>

      <WithdrawModal
        visible={showWithdraw}
        onHide={() => setShowWithdraw(false)}
        onSuccess={handleWithdrawSuccess}
      />

      <Dialog
        visible={!!auditTarget}
        onHide={() => setAuditTarget(null)}
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
          <h3 className="mb-4 text-center text-sm font-semibold uppercase tracking-wider text-slate-500">
            Comprobante de Auditoria
          </h3>
          <AuditTicket
            transactionId={auditTarget}
            onClose={() => setAuditTarget(null)}
          />
        </div>
      </Dialog>
    </AppLayout>
  );
}
