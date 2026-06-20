import { useState, useEffect, useCallback } from 'react';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { Calendar } from 'primereact/calendar';
import { InputNumber } from 'primereact/inputnumber';
import { Chips } from 'primereact/chips';
import { Tag } from 'primereact/tag';
import { SelectButton } from 'primereact/selectbutton';
import { Tooltip } from 'primereact/tooltip';
import { ProgressSpinner } from 'primereact/progressspinner';
import { toast } from 'sonner';
import api from '../../api/axios';
import AppLayout from '../../components/layout/AppLayout';

const fmtMXN = (v) =>
  new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(v ?? 0);

const fmtDate = (d) =>
  d ? new Date(d).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' }) : '—';

const quickOptions = [
  { label: 'Hoy', value: 'today' },
  { label: 'Semana', value: 'week' },
  { label: 'Mes', value: 'month' },
];

export default function CashRegisterClosingsPage() {
  const [closings, setClosings] = useState([]);
  const [loading, setLoading] = useState(false);
  const [page, setPage] = useState(0);
  const [totalRecords, setTotalRecords] = useState(0);
  const perPage = 20;

  const [quickFilter, setQuickFilter] = useState(null);
  const [dateRange, setDateRange] = useState(null);
  const [dateFrom, setDateFrom] = useState(null);
  const [dateTo, setDateTo] = useState(null);

  // Close register modal
  const [showCloseModal, setShowCloseModal] = useState(false);
  const [openRegister, setOpenRegister] = useState(null);
  const [paymentMethods, setPaymentMethods] = useState([]);
  const [declarations, setDeclarations] = useState({});
  const [closingLoading, setClosingLoading] = useState(false);
  const [registerLoading, setRegisterLoading] = useState(false);

  // Detail modal
  const [showDetailModal, setShowDetailModal] = useState(false);
  const [selectedClosing, setSelectedClosing] = useState(null);

  // Email modal
  const [showEmailModal, setShowEmailModal] = useState(false);
  const [emails, setEmails] = useState([]);
  const [sendingEmail, setSendingEmail] = useState(false);

  // Excel export
  const [exportingExcel, setExportingExcel] = useState(false);

  const buildDateParams = useCallback(() => {
    const params = {};
    if (dateFrom) params.date_from = dateFrom;
    if (dateTo) params.date_to = dateTo;
    return params;
  }, [dateFrom, dateTo]);

  const fetchClosings = useCallback(async (p = 0) => {
    setLoading(true);
    try {
      const res = await api.get('/cash-registers/closings', {
        params: { ...buildDateParams(), per_page: perPage, page: p + 1 },
      });
      setClosings(res.data.data);
      setTotalRecords(res.data.metadata?.total ?? 0);
    } catch {
      toast.error('Error al cargar el historial de cierres.');
    } finally {
      setLoading(false);
    }
  }, [buildDateParams]);

  useEffect(() => {
    fetchClosings(page);
  }, [fetchClosings, page]);

  const handleQuickFilter = (val) => {
    setQuickFilter(val);
    setDateRange(null);
    const today = new Date();
    const fmt = (d) => d.toISOString().split('T')[0];

    if (val === 'today') {
      setDateFrom(fmt(today));
      setDateTo(fmt(today));
    } else if (val === 'week') {
      const from = new Date(today);
      from.setDate(today.getDate() - 6);
      setDateFrom(fmt(from));
      setDateTo(fmt(today));
    } else if (val === 'month') {
      const from = new Date(today.getFullYear(), today.getMonth(), 1);
      setDateFrom(fmt(from));
      setDateTo(fmt(today));
    } else {
      setDateFrom(null);
      setDateTo(null);
    }
  };

  const handleRangeChange = (e) => {
    setDateRange(e.value);
    setQuickFilter(null);
    if (e.value && e.value[0]) {
      setDateFrom(e.value[0].toISOString().split('T')[0]);
    }
    if (e.value && e.value[1]) {
      setDateTo(e.value[1].toISOString().split('T')[0]);
    }
  };

  const openCloseModal = async () => {
    setRegisterLoading(true);
    setShowCloseModal(true);
    try {
      const res = await api.get('/cash-registers/current');
      setOpenRegister(res.data.data.cash_register);
      const pms = res.data.data.payment_methods ?? [];
      setPaymentMethods(pms);
      const init = {};
      pms.forEach((pm) => (init[pm.id] = 0));
      setDeclarations(init);
    } catch {
      toast.error('Error al cargar la información de la caja.');
      setShowCloseModal(false);
    } finally {
      setRegisterLoading(false);
    }
  };

  const totalDeclared = Object.values(declarations).reduce((a, b) => a + (b ?? 0), 0);

  const handleCloseRegister = async () => {
    if (!openRegister) {
      toast.error('No hay una caja abierta para cerrar.');
      return;
    }
    setClosingLoading(true);
    try {
      await api.post('/cash-registers/close', { declarations });
      toast.success('¡Caja cerrada exitosamente! El arqueo ha sido registrado.');
      setShowCloseModal(false);
      fetchClosings(0);
    } catch (err) {
      const msg = err.response?.data?.message || 'Error al cerrar la caja.';
      const code = err.response?.data?.code;
      if (code === 'ERR_NO_OPEN_REGISTER') {
        toast.error('No tienes una caja abierta para cerrar.');
      } else {
        toast.error(msg);
      }
    } finally {
      setClosingLoading(false);
    }
  };

  const handleExportExcel = async () => {
    setExportingExcel(true);
    try {
      const res = await api.get('/cash-registers/closings/export-excel', {
        params: buildDateParams(),
        responseType: 'blob',
      });
      const url = window.URL.createObjectURL(new Blob([res.data]));
      const a = document.createElement('a');
      a.href = url;
      a.download = `cierres-caja-${new Date().toISOString().split('T')[0]}.xlsx`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);
      toast.success('Exportación Excel lista.');
    } catch {
      toast.error('Error al exportar Excel.');
    } finally {
      setExportingExcel(false);
    }
  };

  const handleExportPdf = async (closing) => {
    try {
      const res = await api.get(`/cash-registers/closings/${closing.id}/pdf`, {
        responseType: 'blob',
      });
      const url = window.URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }));
      const a = document.createElement('a');
      a.href = url;
      a.download = `arqueo-${closing.id.substring(0, 8)}.pdf`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);
    } catch {
      toast.error('Error al generar el PDF.');
    }
  };

  const handleSendEmail = async () => {
    if (emails.length === 0) {
      toast.error('Agrega al menos un correo destinatario.');
      return;
    }
    setSendingEmail(true);
    try {
      await api.post('/cash-registers/closings/send-email', {
        emails,
        ...buildDateParams(),
      });
      toast.success(`Reporte enviado a ${emails.length} destinatario(s).`);
      setShowEmailModal(false);
      setEmails([]);
    } catch (err) {
      const errors = err.response?.data?.errors;
      if (errors?.emails) {
        toast.error('Uno o más correos no tienen formato válido.');
      } else {
        toast.error(err.response?.data?.message || 'Error al enviar el correo.');
      }
    } finally {
      setSendingEmail(false);
    }
  };

  // — Column templates —
  const differenceTemplate = (row) => {
    const d = parseFloat(row.difference_amount ?? 0);
    const cls = d < 0 ? 'text-red-600 font-bold' : d > 0 ? 'text-emerald-600 font-bold' : 'text-slate-500';
    const sign = d < 0 ? '−' : d > 0 ? '+' : '';
    const label = d < 0 ? 'FALTANTE' : d > 0 ? 'SOBRANTE' : 'EXACTO';
    const sev = d < 0 ? 'danger' : d > 0 ? 'success' : 'info';
    return (
      <div className="flex flex-col gap-0.5">
        <span className={cls}>{sign}{fmtMXN(Math.abs(d))}</span>
        <Tag value={label} severity={sev} className="text-[10px]" />
      </div>
    );
  };

  const actionsTemplate = (row) => (
    <div className="flex items-center gap-1">
      <Button
        icon="pi pi-eye"
        rounded
        text
        severity="info"
        tooltip="Ver detalle"
        tooltipOptions={{ position: 'top' }}
        className="cursor-pointer"
        onClick={() => { setSelectedClosing(row); setShowDetailModal(true); }}
      />
      <Button
        icon="pi pi-file-pdf"
        rounded
        text
        severity="danger"
        tooltip="Descargar PDF"
        tooltipOptions={{ position: 'top' }}
        className="cursor-pointer"
        onClick={() => handleExportPdf(row)}
      />
      <Button
        icon="pi pi-envelope"
        rounded
        text
        severity="secondary"
        tooltip="Enviar por correo"
        tooltipOptions={{ position: 'top' }}
        className="cursor-pointer"
        onClick={() => setShowEmailModal(true)}
      />
    </div>
  );

  const operatorTemplate = (row) =>
    row.closedByUser ? (
      <div>
        <p className="font-medium text-slate-800 text-sm">{row.closedByUser.name}</p>
        <p className="text-xs text-slate-400">{row.closedByUser.email}</p>
      </div>
    ) : <span className="text-slate-400">—</span>;

  const cashRegIdTemplate = (row) => (
    <span className="font-mono text-xs text-slate-500">
      {row.cash_register_id?.substring(0, 8).toUpperCase()}...
    </span>
  );

  return (
    <AppLayout>
      <Tooltip target=".p-button" />

      {/* Page header */}
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Cierres de Caja</h1>
          <p className="mt-0.5 text-sm text-slate-500">Historial forense e inmutable de arqueos por turno</p>
        </div>
        <Button
          label="Cerrar Caja Actual"
          icon="pi pi-lock"
          severity="warning"
          className="cursor-pointer rounded-lg px-4 py-2.5 text-sm font-semibold"
          onClick={openCloseModal}
        />
      </div>

      {/* Filters bar */}
      <div className="mb-5 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <div className="flex flex-wrap items-center gap-4">
          <SelectButton
            value={quickFilter}
            options={quickOptions}
            onChange={(e) => handleQuickFilter(e.value)}
            className="text-sm"
          />
          <Calendar
            value={dateRange}
            onChange={handleRangeChange}
            selectionMode="range"
            placeholder="Rango personalizado"
            dateFormat="dd/mm/yy"
            showIcon
            className="text-sm"
            inputClassName="rounded-lg border-slate-200 px-3 py-2 text-sm"
          />
          {(dateFrom || dateTo) && (
            <button
              onClick={() => {
                setDateFrom(null);
                setDateTo(null);
                setDateRange(null);
                setQuickFilter(null);
              }}
              className="cursor-pointer text-xs text-slate-400 hover:text-slate-700 underline"
            >
              Limpiar filtros
            </button>
          )}
          <div className="ml-auto flex items-center gap-2">
            <Button
              label={exportingExcel ? 'Exportando...' : 'Excel'}
              icon="pi pi-file-excel"
              severity="success"
              outlined
              size="small"
              loading={exportingExcel}
              onClick={handleExportExcel}
              className="cursor-pointer"
            />
            <Button
              label="Enviar Email"
              icon="pi pi-envelope"
              severity="info"
              outlined
              size="small"
              onClick={() => setShowEmailModal(true)}
              className="cursor-pointer"
            />
          </div>
        </div>
      </div>

      {/* DataTable */}
      <div className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200 overflow-hidden">
        <DataTable
          value={closings}
          loading={loading}
          lazy
          paginator
          rows={perPage}
          totalRecords={totalRecords}
          first={page * perPage}
          onPage={(e) => setPage(e.page)}
          emptyMessage="No hay cierres registrados para el periodo seleccionado."
          className="text-sm"
          rowHover
          stripedRows
        >
          <Column
            header="ID Caja"
            body={cashRegIdTemplate}
            style={{ width: '110px' }}
          />
          <Column
            field="created_at"
            header="Fecha / Hora"
            body={(r) => fmtDate(r.created_at)}
            style={{ width: '150px' }}
          />
          <Column
            header="Operador"
            body={operatorTemplate}
          />
          <Column
            field="expected_amount"
            header="Monto Esperado"
            body={(r) => <span className="font-mono text-sm">{fmtMXN(r.expected_amount)}</span>}
            style={{ width: '150px' }}
          />
          <Column
            field="declared_amount"
            header="Monto Declarado"
            body={(r) => <span className="font-mono text-sm">{fmtMXN(r.declared_amount)}</span>}
            style={{ width: '155px' }}
          />
          <Column
            field="difference_amount"
            header="Diferencia"
            body={differenceTemplate}
            style={{ width: '140px' }}
          />
          <Column
            header="Acciones"
            body={actionsTemplate}
            style={{ width: '120px' }}
          />
        </DataTable>
      </div>

      {/* ── Modal: Cerrar Caja (CIEGO) ── */}
      <Dialog
        visible={showCloseModal}
        onHide={() => !closingLoading && setShowCloseModal(false)}
        header={
          <div className="flex items-center gap-2">
            <i className="pi pi-lock text-amber-500 text-lg" />
            <span className="font-bold text-slate-900">Cierre de Caja — Arqueo Ciego</span>
          </div>
        }
        style={{ width: '480px' }}
        modal
        closable={!closingLoading}
        draggable={false}
      >
        {registerLoading ? (
          <div className="flex justify-center py-8">
            <ProgressSpinner style={{ width: '40px', height: '40px' }} />
          </div>
        ) : !openRegister ? (
          <div className="rounded-lg bg-slate-50 border border-slate-200 p-6 text-center">
            <i className="pi pi-inbox text-3xl text-slate-300 mb-3 block" />
            <p className="text-slate-600 font-medium">No tienes una caja abierta</p>
            <p className="text-sm text-slate-400 mt-1">Realiza al menos una venta para abrir una caja automáticamente.</p>
          </div>
        ) : (
          <>
            <div className="mb-4 rounded-lg bg-amber-50 border border-amber-200 p-3 text-sm text-amber-800">
              <strong>⚠ Arqueo Ciego:</strong> Declara el dinero que tienes físicamente <em>sin ver</em> el saldo esperado. El sistema calculará la diferencia al confirmar.
            </div>

            <div className="mb-5 rounded-lg bg-slate-50 p-3 text-xs text-slate-500">
              <span className="font-semibold">Caja ID:</span>{' '}
              {openRegister.id?.substring(0, 8).toUpperCase()}...
              <span className="ml-3 font-semibold">Apertura:</span>{' '}
              {fmtDate(openRegister.opened_at)}
            </div>

            <div className="space-y-3">
              {paymentMethods.map((pm) => (
                <div key={pm.id} className="flex items-center gap-3">
                  <label className="w-40 flex-shrink-0 text-sm font-medium text-slate-700">
                    {pm.name}
                  </label>
                  <InputNumber
                    value={declarations[pm.id] ?? 0}
                    onValueChange={(e) =>
                      setDeclarations((prev) => ({ ...prev, [pm.id]: e.value ?? 0 }))
                    }
                    mode="currency"
                    currency="MXN"
                    locale="es-MX"
                    minFractionDigits={2}
                    min={0}
                    disabled={closingLoading}
                    className="flex-1"
                    inputClassName="w-full rounded-lg border-slate-200 px-3 py-2 text-sm"
                    pt={{ root: { className: 'w-full' } }}
                  />
                </div>
              ))}
            </div>

            <div className="mt-4 flex items-center justify-between rounded-lg bg-indigo-50 border border-indigo-100 px-4 py-3">
              <span className="text-sm font-semibold text-slate-700">Total Declarado:</span>
              <span className="text-lg font-bold text-indigo-700">{fmtMXN(totalDeclared)}</span>
            </div>

            <div className="mt-4 rounded-lg bg-rose-50 border border-rose-100 p-3 text-xs text-rose-700">
              <strong>Este cierre es definitivo e inmutable.</strong> Una vez confirmado, no podrá modificarse ni eliminarse. Se registrará con sellado forense de auditoría.
            </div>

            <div className="mt-5 flex justify-end gap-3">
              <Button
                label="Cancelar"
                outlined
                severity="secondary"
                onClick={() => setShowCloseModal(false)}
                disabled={closingLoading}
                className="cursor-pointer"
              />
              <Button
                label={closingLoading ? 'Cerrando caja...' : 'Confirmar Cierre'}
                icon="pi pi-lock"
                severity="warning"
                loading={closingLoading}
                onClick={handleCloseRegister}
                className="cursor-pointer"
              />
            </div>
          </>
        )}
      </Dialog>

      {/* ── Modal: Detalle del Cierre ── */}
      <Dialog
        visible={showDetailModal}
        onHide={() => setShowDetailModal(false)}
        header={
          <div className="flex items-center gap-2">
            <i className="pi pi-chart-bar text-indigo-500" />
            <span className="font-bold text-slate-900">Detalle del Arqueo</span>
          </div>
        }
        style={{ width: '560px' }}
        modal
        draggable={false}
      >
        {selectedClosing && (
          <>
            <div className="mb-4 grid grid-cols-2 gap-3 text-sm">
              <div className="rounded-lg bg-slate-50 p-3">
                <p className="text-xs text-slate-400 mb-0.5">Fecha / Hora</p>
                <p className="font-semibold text-slate-800">{fmtDate(selectedClosing.created_at)}</p>
              </div>
              <div className="rounded-lg bg-slate-50 p-3">
                <p className="text-xs text-slate-400 mb-0.5">Operador</p>
                <p className="font-semibold text-slate-800">{selectedClosing.closedByUser?.name ?? '—'}</p>
              </div>
              <div className="rounded-lg bg-slate-50 p-3">
                <p className="text-xs text-slate-400 mb-0.5">Monto Esperado</p>
                <p className="font-semibold text-slate-800">{fmtMXN(selectedClosing.expected_amount)}</p>
              </div>
              <div className="rounded-lg bg-slate-50 p-3">
                <p className="text-xs text-slate-400 mb-0.5">Monto Declarado</p>
                <p className="font-semibold text-slate-800">{fmtMXN(selectedClosing.declared_amount)}</p>
              </div>
            </div>

            <table className="w-full text-sm border-collapse">
              <thead>
                <tr className="bg-indigo-600 text-white">
                  <th className="px-3 py-2 text-left text-xs font-semibold">Método de Pago</th>
                  <th className="px-3 py-2 text-right text-xs font-semibold">Esperado</th>
                  <th className="px-3 py-2 text-right text-xs font-semibold">Declarado</th>
                  <th className="px-3 py-2 text-right text-xs font-semibold">Diferencia</th>
                </tr>
              </thead>
              <tbody>
                {(selectedClosing.payment_breakdown ?? []).map((pm, i) => {
                  const d = parseFloat(pm.difference ?? 0);
                  const cls = d < 0 ? 'text-red-600 font-bold' : d > 0 ? 'text-emerald-600 font-bold' : 'text-slate-500';
                  return (
                    <tr key={pm.payment_method_id} className={i % 2 === 0 ? 'bg-white' : 'bg-slate-50'}>
                      <td className="px-3 py-2 font-medium text-slate-700">{pm.name}</td>
                      <td className="px-3 py-2 text-right font-mono text-slate-600">{fmtMXN(pm.expected)}</td>
                      <td className="px-3 py-2 text-right font-mono text-slate-600">{fmtMXN(pm.declared)}</td>
                      <td className={`px-3 py-2 text-right font-mono ${cls}`}>
                        {d < 0 ? '−' : d > 0 ? '+' : ''}{fmtMXN(Math.abs(d))}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>

            <div className="mt-4 flex items-center justify-between">
              <Button
                label="Descargar PDF"
                icon="pi pi-file-pdf"
                severity="danger"
                outlined
                size="small"
                className="cursor-pointer"
                onClick={() => handleExportPdf(selectedClosing)}
              />
              {(() => {
                const d = parseFloat(selectedClosing.difference_amount ?? 0);
                const sev = d < 0 ? 'danger' : d > 0 ? 'success' : 'info';
                const label = d < 0 ? `FALTANTE ${fmtMXN(Math.abs(d))}` : d > 0 ? `SOBRANTE ${fmtMXN(d)}` : 'SIN DIFERENCIA';
                return <Tag value={label} severity={sev} className="text-sm px-3 py-1.5" />;
              })()}
            </div>
          </>
        )}
      </Dialog>

      {/* ── Modal: Enviar Email ── */}
      <Dialog
        visible={showEmailModal}
        onHide={() => !sendingEmail && setShowEmailModal(false)}
        header={
          <div className="flex items-center gap-2">
            <i className="pi pi-envelope text-blue-500" />
            <span className="font-bold text-slate-900">Enviar Reporte por Correo</span>
          </div>
        }
        style={{ width: '460px' }}
        modal
        closable={!sendingEmail}
        draggable={false}
      >
        <div className="space-y-4">
          <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">
              Destinatarios *
            </label>
            <Chips
              value={emails}
              onChange={(e) => setEmails(e.value)}
              separator=","
              placeholder="Escribe un email y presiona Enter"
              disabled={sendingEmail}
              className="w-full"
              pt={{ container: { className: 'w-full rounded-lg border-slate-200 p-2 min-h-[80px]' } }}
            />
            <p className="mt-1 text-xs text-slate-400">
              Presiona <kbd className="rounded bg-slate-100 px-1 py-0.5 text-[10px]">Enter</kbd> o <kbd className="rounded bg-slate-100 px-1 py-0.5 text-[10px]">,</kbd> para agregar cada correo. Máx 20 destinatarios.
            </p>
          </div>

          {(dateFrom || dateTo) && (
            <div className="rounded-lg bg-blue-50 border border-blue-100 px-3 py-2 text-sm text-blue-700">
              📅 Se enviará el reporte filtrado:{' '}
              <strong>{dateFrom ?? '—'}</strong> al <strong>{dateTo ?? 'hoy'}</strong>
            </div>
          )}

          <div className="flex justify-end gap-3 pt-2">
            <Button
              label="Cancelar"
              outlined
              severity="secondary"
              onClick={() => setShowEmailModal(false)}
              disabled={sendingEmail}
              className="cursor-pointer"
            />
            <Button
              label={sendingEmail ? 'Enviando...' : `Enviar a ${emails.length} correo(s)`}
              icon="pi pi-send"
              severity="info"
              loading={sendingEmail}
              onClick={handleSendEmail}
              disabled={emails.length === 0}
              className="cursor-pointer"
            />
          </div>
        </div>
      </Dialog>
    </AppLayout>
  );
}
