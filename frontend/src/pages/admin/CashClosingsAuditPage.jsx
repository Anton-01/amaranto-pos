import { useState, useEffect, useCallback } from 'react';
import { Navigate } from 'react-router-dom';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { Dialog } from 'primereact/dialog';
import { Dropdown } from 'primereact/dropdown';
import { Calendar } from 'primereact/calendar';
import { InputText } from 'primereact/inputtext';
import { Tag } from 'primereact/tag';
import { Button } from 'primereact/button';
import { toast } from 'sonner';
import api from '../../api/axios';
import AppLayout from '../../components/layout/AppLayout';
import { useAuth } from '../../context/AuthContext';

const fmt = (v) => `$${Number(v ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;

const toYmd = (d) => (d ? `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}` : null);

/**
 * Auditoria Historica de Cierres de Caja — EXCLUSIVA de administradores.
 *
 * Doble candado: este gate de frontend redirige a cualquier no-admin, y el
 * backend protege ambos endpoints con middleware role:admin. La vista es de
 * solo lectura por diseno: no existe ninguna accion de edicion o borrado, y el
 * modelo CashRegisterClosing bloquea toda mutacion a nivel de Eloquent
 * (ledger insert-only).
 */
export default function CashClosingsAuditPage() {
  const { user } = useAuth();

  const [closings, setClosings] = useState([]);
  const [summary, setSummary] = useState(null);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(0);
  const [totalRecords, setTotalRecords] = useState(0);

  const [typeFilter, setTypeFilter] = useState(null);
  const [dateRange, setDateRange] = useState(null);
  const [search, setSearch] = useState('');

  const [detail, setDetail] = useState(null);

  const isAdmin = user?.roles?.includes('admin');

  const fetchClosings = useCallback(async (targetPage = 0) => {
    setLoading(true);
    try {
      const res = await api.get('/admin/cash-closings-audit', {
        params: {
          page: targetPage + 1,
          per_page: 15,
          type: typeFilter ?? undefined,
          date_from: toYmd(dateRange?.[0]) ?? undefined,
          date_to: toYmd(dateRange?.[1]) ?? undefined,
          search: search || undefined,
        },
      });
      setClosings(res.data.data);
      setTotalRecords(res.data.metadata.total);
      setSummary(res.data.metadata.summary);
      setPage(targetPage);
    } catch (err) {
      toast.error(err.response?.data?.message || 'Error al cargar la auditoría de cierres.');
    } finally {
      setLoading(false);
    }
  }, [typeFilter, dateRange, search]);

  useEffect(() => {
    if (isAdmin) fetchClosings(0);
  }, [isAdmin, fetchClosings]);

  if (user && !isAdmin) {
    return <Navigate to="/dashboard" replace />;
  }

  const typeTemplate = (row) => (
    row.is_automated ? (
      <Tag value="AUTOMÁTICO" severity="info" icon="pi pi-cog" className="text-[10px]" />
    ) : (
      <Tag value="MANUAL" severity="success" icon="pi pi-user" className="text-[10px]" />
    )
  );

  const responsibleTemplate = (row) => (
    row.is_automated ? (
      <div className="flex items-center gap-2">
        <div className="flex h-7 w-7 items-center justify-center rounded-full bg-slate-100">
          <i className="pi pi-cog text-xs text-slate-500" />
        </div>
        <div>
          <p className="text-sm font-medium text-slate-700">System Job</p>
          <p className="text-[11px] text-slate-400">Proceso automatizado 21:00</p>
        </div>
      </div>
    ) : (
      <div>
        <p className="text-sm font-medium text-slate-900">{row.closed_by_user?.name ?? 'N/D'}</p>
        <p className="text-[11px] text-slate-500">{row.closed_by_user?.email ?? ''}</p>
      </div>
    )
  );

  const differenceTemplate = (row) => {
    const diff = parseFloat(row.difference_amount);
    return (
      <span className={`font-semibold ${diff < 0 ? 'text-rose-600' : diff > 0 ? 'text-emerald-600' : 'text-slate-500'}`}>
        {fmt(diff)}
      </span>
    );
  };

  const summaryChips = summary ? [
    { label: `${summary.manual_count} manuales`, cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    { label: `${summary.automated_count} automáticos`, cls: 'bg-indigo-50 text-indigo-700 ring-indigo-200' },
    { label: `${summary.with_difference_count} con diferencia`, cls: 'bg-amber-50 text-amber-700 ring-amber-200' },
  ] : [];

  return (
    <AppLayout>
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-xl font-semibold text-slate-900">Auditoría de Cierres de Caja</h1>
            <Tag value="ADMIN" severity="danger" className="text-[10px]" />
            <Tag value="SOLO LECTURA" severity="secondary" icon="pi pi-lock" className="text-[10px]" />
          </div>
          <p className="text-sm text-slate-500">
            Registro forense inmutable de todos los arqueos, manuales y automáticos.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {summaryChips.map((chip) => (
            <span key={chip.label} className={`rounded-lg px-3 py-1.5 text-xs font-semibold ring-1 ${chip.cls}`}>
              {chip.label}
            </span>
          ))}
        </div>
      </div>

      {/* Filtros */}
      <div className="mb-4 flex flex-wrap items-center gap-2">
        <Dropdown
          value={typeFilter}
          options={[
            { label: 'Todos los tipos', value: null },
            { label: 'Manuales', value: 'manual' },
            { label: 'Automáticos (System Job)', value: 'automated' },
          ]}
          onChange={(e) => setTypeFilter(e.value)}
          className="w-56 text-sm"
          pt={{ root: { className: 'w-56' } }}
        />
        <Calendar
          value={dateRange}
          onChange={(e) => setDateRange(e.value)}
          selectionMode="range"
          readOnlyInput
          placeholder="Rango de fechas"
          dateFormat="dd/mm/yy"
          showButtonBar
          className="w-64"
          inputClassName="rounded-lg border-slate-200 px-3 py-2 text-sm"
        />
        <InputText
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && fetchClosings(0)}
          placeholder="Buscar operador..."
          className="w-56 rounded-lg border-slate-200 px-3 py-2 text-sm"
        />
        <Button
          icon="pi pi-search"
          onClick={() => fetchClosings(0)}
          className="cursor-pointer h-9 w-9 rounded-lg bg-indigo-600 text-white hover:bg-indigo-500"
          pt={{ root: { className: 'border-0' } }}
        />
      </div>

      <div className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <DataTable
          value={closings}
          loading={loading}
          lazy
          paginator
          rows={15}
          first={page * 15}
          totalRecords={totalRecords}
          onPage={(e) => fetchClosings(e.page)}
          emptyMessage="Sin cierres registrados con los filtros aplicados."
          className="text-sm"
          onRowClick={(e) => setDetail(e.data)}
          rowClassName={() => 'cursor-pointer hover:bg-slate-50'}
        >
          <Column
            header="Caja"
            body={(row) => (
              <span className="font-mono text-xs font-semibold text-slate-700">
                {row.cash_register_id?.substring(0, 8).toUpperCase()}
              </span>
            )}
            style={{ width: '100px' }}
          />
          <Column
            header="Fecha / Hora"
            body={(row) => new Date(row.created_at).toLocaleString('es-MX', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}
            style={{ width: '170px' }}
          />
          <Column header="Operador de Caja" body={(row) => row.cash_register?.user?.name ?? 'N/D'} />
          <Column header="Cerrado por" body={responsibleTemplate} />
          <Column header="Tipo" body={typeTemplate} style={{ width: '130px' }} />
          <Column header="Esperado" body={(row) => <span className="font-medium">{fmt(row.expected_amount)}</span>} align="right" style={{ width: '120px' }} />
          <Column header="Declarado" body={(row) => fmt(row.declared_amount)} align="right" style={{ width: '120px' }} />
          <Column header="Diferencia" body={differenceTemplate} align="right" style={{ width: '120px' }} />
          <Column
            header=""
            body={() => <i className="pi pi-eye text-slate-400" />}
            style={{ width: '50px' }}
            align="center"
          />
        </DataTable>
      </div>

      {/* Radiografia forense — solo lectura */}
      <Dialog
        visible={detail !== null}
        onHide={() => setDetail(null)}
        modal
        header={null}
        className="w-full max-w-2xl"
        pt={{
          mask: { className: 'backdrop-blur-sm bg-black/30' },
          root: { className: 'rounded-2xl border-0 shadow-2xl' },
          content: { className: 'p-0' },
        }}
      >
        {detail && (
          <div className="p-6">
            <div className="mb-5 flex items-start justify-between gap-3">
              <div className="flex items-center gap-3">
                <div className={`flex h-10 w-10 items-center justify-center rounded-xl ${detail.is_automated ? 'bg-indigo-50' : 'bg-emerald-50'}`}>
                  <i className={`pi ${detail.is_automated ? 'pi-cog text-indigo-600' : 'pi-lock text-emerald-600'} text-lg`} />
                </div>
                <div>
                  <h3 className="text-lg font-semibold text-slate-900">
                    Radiografía del Cierre {detail.cash_register_id?.substring(0, 8).toUpperCase()}
                  </h3>
                  <p className="text-xs text-slate-500">
                    {new Date(detail.created_at).toLocaleString('es-MX', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })}
                  </p>
                </div>
              </div>
              <Tag value="REGISTRO INMUTABLE" severity="secondary" icon="pi pi-shield" className="text-[10px]" />
            </div>

            <div className="mb-4 grid grid-cols-2 gap-3 text-sm">
              <div className="rounded-lg bg-slate-50 p-3">
                <p className="text-xs font-medium text-slate-500">Operador de la Caja</p>
                <p className="mt-1 font-semibold text-slate-900">{detail.cash_register?.user?.name ?? 'N/D'}</p>
                <p className="text-[11px] text-slate-400">{detail.cash_register?.user?.email ?? ''}</p>
              </div>
              <div className="rounded-lg bg-slate-50 p-3">
                <p className="text-xs font-medium text-slate-500">Ejecutado por</p>
                {detail.is_automated ? (
                  <>
                    <p className="mt-1 font-semibold text-slate-900">System Automated Process</p>
                    <p className="text-[11px] text-slate-400">Job programado · 21:00</p>
                  </>
                ) : (
                  <>
                    <p className="mt-1 font-semibold text-slate-900">{detail.closed_by_user?.name ?? 'N/D'}</p>
                    <p className="text-[11px] text-slate-400">{detail.closed_by_user?.email ?? ''}</p>
                  </>
                )}
              </div>
            </div>

            <div className="mb-4 grid grid-cols-3 gap-3">
              <div className="rounded-xl bg-indigo-50 p-3 text-center">
                <p className="text-[11px] font-medium text-indigo-600">Dinero Calculado</p>
                <p className="mt-1 text-lg font-bold text-indigo-900">{fmt(detail.expected_amount)}</p>
              </div>
              <div className="rounded-xl bg-slate-100 p-3 text-center">
                <p className="text-[11px] font-medium text-slate-600">Dinero Declarado</p>
                <p className="mt-1 text-lg font-bold text-slate-900">{fmt(detail.declared_amount)}</p>
              </div>
              <div className={`rounded-xl p-3 text-center ${parseFloat(detail.difference_amount) < 0 ? 'bg-rose-50' : parseFloat(detail.difference_amount) > 0 ? 'bg-emerald-50' : 'bg-slate-50'}`}>
                <p className={`text-[11px] font-medium ${parseFloat(detail.difference_amount) < 0 ? 'text-rose-600' : parseFloat(detail.difference_amount) > 0 ? 'text-emerald-600' : 'text-slate-500'}`}>
                  Diferencia
                </p>
                <p className={`mt-1 text-lg font-bold ${parseFloat(detail.difference_amount) < 0 ? 'text-rose-900' : parseFloat(detail.difference_amount) > 0 ? 'text-emerald-900' : 'text-slate-700'}`}>
                  {fmt(detail.difference_amount)}
                </p>
              </div>
            </div>

            {/* Desglose por metodo de pago */}
            <div className="rounded-lg border border-slate-200 overflow-hidden">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50">
                    <th className="px-3 py-2 text-left text-xs font-semibold text-slate-600">Método</th>
                    <th className="px-3 py-2 text-right text-xs font-semibold text-slate-600">Esperado</th>
                    <th className="px-3 py-2 text-right text-xs font-semibold text-slate-600">Declarado</th>
                    <th className="px-3 py-2 text-right text-xs font-semibold text-slate-600">Diferencia</th>
                  </tr>
                </thead>
                <tbody>
                  {(detail.payment_breakdown ?? []).map((pm, i) => (
                    <tr key={i} className="border-b border-slate-100 last:border-0">
                      <td className="px-3 py-2 text-slate-900">{pm.name}</td>
                      <td className="px-3 py-2 text-right text-slate-700">{fmt(pm.expected)}</td>
                      <td className="px-3 py-2 text-right text-slate-700">{fmt(pm.declared)}</td>
                      <td className={`px-3 py-2 text-right font-semibold ${pm.difference < 0 ? 'text-rose-600' : pm.difference > 0 ? 'text-emerald-600' : 'text-slate-400'}`}>
                        {fmt(pm.difference)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {detail.notes && (
              <div className="mt-4 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2.5 text-xs text-amber-800">
                <span className="font-semibold">Nota del sistema: </span>{detail.notes}
              </div>
            )}
          </div>
        )}
      </Dialog>
    </AppLayout>
  );
}
