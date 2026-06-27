import { useState, useEffect, useCallback, useMemo } from 'react';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { Dropdown } from 'primereact/dropdown';
import { Calendar } from 'primereact/calendar';
import { InputNumber } from 'primereact/inputnumber';
import { InputText } from 'primereact/inputtext';
import { Password } from 'primereact/password';
import { SelectButton } from 'primereact/selectbutton';
import { Tag } from 'primereact/tag';
import { toast } from 'sonner';
import api from '../../api/axios';
import AppLayout from '../../components/layout/AppLayout';
import TicketPreview from '../../components/pos/TicketPreview';
import { useAuth } from '../../context/AuthContext';

const quickFilters = [
  { label: 'Hoy', value: 'today' },
  { label: 'Última Semana', value: 'week' },
  { label: 'Último Mes', value: 'month' },
];

const statusOptions = [
  { label: 'Todos', value: null },
  { label: 'Completado', value: 'completed' },
  { label: 'Cancelado', value: 'canceled' },
];

const fmt = (v) => `$${Number(v).toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;

export default function SalesHistoryPage() {
  const { user } = useAuth();
  const userRoles = user?.roles || [];
  const isAdminOrManager = userRoles.includes('admin') || userRoles.includes('manager');

  const [orders, setOrders] = useState([]);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(0);
  const [totalRecords, setTotalRecords] = useState(0);
  const [perPage] = useState(20);

  const [quickFilter, setQuickFilter] = useState('today');
  const [showAdvanced, setShowAdvanced] = useState(false);
  const [dateRange, setDateRange] = useState(null);
  const [paymentMethods, setPaymentMethods] = useState([]);
  const [selectedPaymentMethod, setSelectedPaymentMethod] = useState(null);
  const [users, setUsers] = useState([]);
  const [selectedUser, setSelectedUser] = useState(null);
  const [totalMin, setTotalMin] = useState(null);
  const [totalMax, setTotalMax] = useState(null);
  const [selectedStatus, setSelectedStatus] = useState(null);

  const [detailOrder, setDetailOrder] = useState(null);
  const [showDetail, setShowDetail] = useState(false);
  const [detailLoading, setDetailLoading] = useState(false);

  const [printOrder, setPrintOrder] = useState(null);
  const [printTicketConfig, setPrintTicketConfig] = useState(null);
  const [showPrint, setShowPrint] = useState(false);

  const [cancelOrder, setCancelOrder] = useState(null);
  const [showCancel, setShowCancel] = useState(false);
  const [cancelPassword, setCancelPassword] = useState('');
  const [cancelReason, setCancelReason] = useState('');
  const [canceling, setCanceling] = useState(false);

  const [exporting, setExporting] = useState(false);
  const [taxRate, setTaxRate] = useState(0.16);

  useEffect(() => {
    api.get('/payment-methods').then(res => setPaymentMethods(res.data.data)).catch(() => {});
    api.get('/tax-rate').then(res => setTaxRate(res.data.data.rate)).catch(() => {});
    if (isAdminOrManager) {
      api.get('/admin/users').then(res => setUsers(res.data.data.map(u => ({ label: u.name, value: u.id })))).catch(() => {});
    }
  }, [isAdminOrManager]);

  const getDateParams = useCallback(() => {
    if (dateRange && dateRange[0]) {
      const from = dateRange[0].toISOString().split('T')[0];
      const to = dateRange[1] ? dateRange[1].toISOString().split('T')[0] : from;
      return { date_from: from, date_to: to };
    }
    const today = new Date();
    const todayStr = today.toISOString().split('T')[0];
    if (quickFilter === 'today') {
      return { date_from: todayStr, date_to: todayStr };
    }
    if (quickFilter === 'week') {
      const d = new Date(today);
      d.setDate(d.getDate() - 7);
      return { date_from: d.toISOString().split('T')[0], date_to: todayStr };
    }
    if (quickFilter === 'month') {
      const d = new Date(today);
      d.setMonth(d.getMonth() - 1);
      return { date_from: d.toISOString().split('T')[0], date_to: todayStr };
    }
    return {};
  }, [quickFilter, dateRange]);

  const fetchOrders = useCallback(async (pageNum = 0) => {
    setLoading(true);
    try {
      const params = {
        ...getDateParams(),
        page: pageNum + 1,
        per_page: perPage,
      };
      if (selectedPaymentMethod) params.payment_method_id = selectedPaymentMethod;
      if (selectedUser) params.user_id = selectedUser;
      if (totalMin != null) params.total_min = totalMin;
      if (totalMax != null) params.total_max = totalMax;
      if (selectedStatus) params.status = selectedStatus;

      const res = await api.get('/orders', { params });
      setOrders(res.data.data);
      setTotalRecords(res.data.metadata.total);
    } catch {
      toast.error('Error al cargar historial de ventas.');
    } finally {
      setLoading(false);
    }
  }, [getDateParams, perPage, selectedPaymentMethod, selectedUser, totalMin, totalMax, selectedStatus]);

  useEffect(() => {
    setPage(0);
    fetchOrders(0);
  }, [fetchOrders]);

  const onPageChange = (e) => {
    const newPage = e.first / perPage;
    setPage(newPage);
    fetchOrders(newPage);
  };

  const openDetail = async (order) => {
    setShowDetail(true);
    setDetailLoading(true);
    try {
      const res = await api.get(`/orders/${order.id}`);
      setDetailOrder(res.data.data);
    } catch {
      toast.error('Error al cargar detalle.');
    } finally {
      setDetailLoading(false);
    }
  };

  const openPrint = async (order) => {
    try {
      const [orderRes, configRes] = await Promise.all([
        api.get(`/orders/${order.id}`),
        api.get('/ticket-configs/active'),
      ]);
      setPrintOrder(orderRes.data.data);
      setPrintTicketConfig(configRes.data.data);
      setShowPrint(true);
    } catch {
      toast.error('Error al preparar impresion.');
    }
  };

  const handlePrint = () => {
    setTimeout(() => window.print(), 350);
  };

  const [directPrinting, setDirectPrinting] = useState(false);

  const handleDirectPrint = async (orderId) => {
    setDirectPrinting(true);
    try {
      await api.post(`/orders/${orderId}/print`);
      toast.success('Ticket enviado a la impresora.');
    } catch {
      toast.error('No se pudo imprimir. Verifique la conexión de la impresora.');
    } finally {
      setDirectPrinting(false);
    }
  };

  const openCancel = (order) => {
    setCancelOrder(order);
    setCancelPassword('');
    setCancelReason('');
    setShowCancel(true);
  };

  const handleCancel = async () => {
    if (!cancelPassword.trim() || !cancelReason.trim()) {
      toast.error('Todos los campos son obligatorios.');
      return;
    }
    setCanceling(true);
    try {
      await api.post(`/orders/${cancelOrder.id}/cancel`, {
        admin_password: cancelPassword,
        reason: cancelReason,
      });
      toast.success('Orden cancelada exitosamente.');
      setShowCancel(false);
      fetchOrders(page);
    } catch (err) {
      const data = err.response?.data;
      toast.error(data?.message || 'Error al cancelar la orden.');
    } finally {
      setCanceling(false);
    }
  };

  const handleExport = async () => {
    setExporting(true);
    try {
      const params = { ...getDateParams() };
      if (selectedPaymentMethod) params.payment_method_id = selectedPaymentMethod;
      if (selectedUser) params.user_id = selectedUser;
      if (totalMin != null) params.total_min = totalMin;
      if (totalMax != null) params.total_max = totalMax;
      if (selectedStatus) params.status = selectedStatus;

      const res = await api.get('/sales/export', { params, responseType: 'blob' });
      const url = window.URL.createObjectURL(new Blob([res.data]));
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', `ventas_cronos_${new Date().toISOString().split('T')[0]}.xlsx`);
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);
      toast.success('Reporte exportado exitosamente.');
    } catch {
      toast.error('Error al exportar.');
    } finally {
      setExporting(false);
    }
  };

  const paymentMethodOptions = useMemo(() => [
    { label: 'Todos', value: null },
    ...paymentMethods.map(pm => ({ label: pm.name, value: pm.id })),
  ], [paymentMethods]);

  const ticketIdTemplate = (row) => (
    <span className="font-mono text-xs font-semibold text-slate-800">
      {row.id?.substring(0, 8).toUpperCase()}
    </span>
  );

  const dateTemplate = (row) => {
    const d = new Date(row.created_at);
    return (
      <span className="text-xs text-slate-600">
        {d.toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' })}{' '}
        {d.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' })}
      </span>
    );
  };

  const vendorTemplate = (row) => (
    <span className="text-sm text-slate-700">{row.cash_register?.user?.name || 'N/A'}</span>
  );

  const paymentTemplate = (row) => (
    <Tag value={row.payment_method?.name || 'N/A'} className="text-xs" severity="info" />
  );

  const totalTemplate = (row) => (
    <span className="text-sm font-semibold text-slate-900">{fmt(row.total)}</span>
  );

  const receivedTemplate = (row) => {
    const val = row.amount_received;
    if (val == null || parseFloat(val) === 0) return <span className="text-xs text-slate-400">-</span>;
    return <span className="text-sm text-slate-700">{fmt(val)}</span>;
  };

  const changeTemplate = (row) => {
    const val = row.amount_change;
    if (val == null || parseFloat(val) === 0) return <span className="text-xs text-slate-400">-</span>;
    return <span className="text-sm font-semibold text-emerald-600">{fmt(val)}</span>;
  };

  const statusTemplate = (row) => (
    <Tag
      value={row.status === 'completed' ? 'Completado' : 'Cancelado'}
      severity={row.status === 'completed' ? 'success' : 'danger'}
      className="text-xs"
    />
  );

  const actionsTemplate = (row) => (
    <div className="flex gap-1">
      <Button
        icon="pi pi-eye"
        severity="info"
        text
        rounded
        onClick={() => openDetail(row)}
        className="cursor-pointer !h-8 !w-8"
        tooltip="Ver detalle"
        tooltipOptions={{ position: 'top' }}
      />
      <Button
        icon="pi pi-print"
        severity="secondary"
        text
        rounded
        onClick={() => openPrint(row)}
        className="cursor-pointer !h-8 !w-8"
        tooltip="Reimprimir ticket"
        tooltipOptions={{ position: 'top' }}
      />
      {isAdminOrManager && row.status !== 'canceled' && (
        <Button
          icon="pi pi-ban"
          severity="danger"
          text
          rounded
          onClick={() => openCancel(row)}
          className="cursor-pointer !h-8 !w-8"
          tooltip="Cancelar orden"
          tooltipOptions={{ position: 'top' }}
        />
      )}
    </div>
  );

  return (
    <AppLayout>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-900">Historial de Ventas</h1>
        <p className="mt-1 text-sm text-slate-500">Consulta y audita todas las transacciones del POS.</p>
      </div>

      {/* Quick filters */}
      <div className="mb-4 flex flex-wrap items-center gap-4">
        <SelectButton
          value={quickFilter}
          onChange={(e) => { setQuickFilter(e.value); setDateRange(null); }}
          options={quickFilters}
          className="text-sm"
        />
        <Button
          label={showAdvanced ? 'Ocultar Filtros' : 'Filtros Avanzados'}
          icon={showAdvanced ? 'pi pi-chevron-up' : 'pi pi-filter'}
          onClick={() => setShowAdvanced(!showAdvanced)}
          className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50"
          pt={{ root: { className: 'border border-slate-200' } }}
        />
        <div className="ml-auto">
          <Button
            label={exporting ? 'Exportando...' : 'Exportar Excel'}
            icon="pi pi-download"
            onClick={handleExport}
            disabled={exporting}
            loading={exporting}
            className="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-500"
            pt={{ root: { className: 'border-0' } }}
          />
        </div>
      </div>

      {/* Advanced filters panel */}
      {showAdvanced && (
        <div className="mb-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-600">Rango de Fechas</label>
              <Calendar
                value={dateRange}
                onChange={(e) => { setDateRange(e.value); setQuickFilter(null); }}
                selectionMode="range"
                readOnlyInput
                dateFormat="dd/mm/yy"
                placeholder="Seleccionar rango"
                className="w-full text-sm"
                pt={{ root: { className: 'w-full' } }}
              />
            </div>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-600">Método de Pago</label>
              <Dropdown
                value={selectedPaymentMethod}
                options={paymentMethodOptions}
                optionLabel="label"
                optionValue="value"
                onChange={(e) => setSelectedPaymentMethod(e.value)}
                placeholder="Todos"
                className="w-full text-sm"
                pt={{ root: { className: 'w-full' } }}
              />
            </div>
            {isAdminOrManager && (
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-600">Operador/Cajero</label>
                <Dropdown
                  value={selectedUser}
                  options={[{ label: 'Todos', value: null }, ...users]}
                  onChange={(e) => setSelectedUser(e.value)}
                  placeholder="Todos"
                  className="w-full text-sm"
                  pt={{ root: { className: 'w-full' } }}
                  filter
                />
              </div>
            )}
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-600">Monto Mínimo</label>
              <InputNumber
                value={totalMin}
                onValueChange={(e) => setTotalMin(e.value)}
                mode="currency"
                currency="MXN"
                locale="es-MX"
                placeholder="Min"
                className="w-full"
                inputClassName="w-full text-sm rounded-lg border-slate-200 px-3 py-2"
                pt={{ root: { className: 'w-full' } }}
              />
            </div>
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-600">Estatus</label>
              <Dropdown
                value={selectedStatus}
                options={statusOptions}
                optionLabel="label"
                optionValue="value"
                onChange={(e) => setSelectedStatus(e.value)}
                placeholder="Todos"
                className="w-full text-sm"
                pt={{ root: { className: 'w-full' } }}
              />
            </div>
          </div>
        </div>
      )}

      {/* DataTable */}
      <div className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <DataTable
          value={orders}
          loading={loading}
          paginator
          lazy
          first={page * perPage}
          rows={perPage}
          totalRecords={totalRecords}
          onPage={onPageChange}
          emptyMessage="No se encontraron ventas."
          className="text-sm"
          stripedRows
          pt={{
            wrapper: { className: 'rounded-xl' },
          }}
        >
          <Column body={ticketIdTemplate} header="ID Ticket" style={{ width: '100px' }} />
          <Column body={dateTemplate} header="Fecha/Hora" style={{ width: '160px' }} />
          <Column body={vendorTemplate} header="Vendedor" />
          <Column body={paymentTemplate} header="Método de Pago" style={{ width: '140px' }} />
          <Column body={totalTemplate} header="Total" style={{ width: '100px' }} />
          <Column body={(row) => {
            const val = parseFloat(row.discount_total ?? 0);
            if (val === 0) return <span className="text-xs text-slate-400">-</span>;
            return <span className="text-sm font-semibold text-amber-600">-{fmt(val)}</span>;
          }} header="Descuento" style={{ width: '100px' }} />
          <Column body={receivedTemplate} header="Recibido" style={{ width: '100px' }} />
          <Column body={changeTemplate} header="Cambio" style={{ width: '90px' }} />
          <Column body={statusTemplate} header="Estatus" style={{ width: '100px' }} />
          <Column body={actionsTemplate} header="Acciones" style={{ width: '100px' }} />
        </DataTable>
      </div>

      {/* Detail Modal */}
      <Dialog
        visible={showDetail}
        onHide={() => setShowDetail(false)}
        modal
        header={null}
        className="w-full max-w-2xl"
        pt={{
          mask: { className: 'backdrop-blur-sm bg-black/30' },
          root: { className: 'rounded-2xl border-0 shadow-2xl' },
          content: { className: 'p-0' },
        }}
      >
        <div className="p-6">
          <div className="mb-5 flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-50">
              <i className="pi pi-eye text-indigo-600" />
            </div>
            <div>
              <h3 className="text-lg font-semibold text-slate-900">Detalle del Pedido</h3>
              {detailOrder && (
                <p className="text-xs text-slate-500">
                  Folio: {detailOrder.id?.substring(0, 8).toUpperCase()}
                </p>
              )}
            </div>
          </div>

          {detailLoading ? (
            <div className="flex h-40 items-center justify-center">
              <div className="h-6 w-6 animate-spin rounded-full border-3 border-indigo-600 border-t-transparent" />
            </div>
          ) : detailOrder ? (
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-3 text-sm">
                <div className="rounded-lg bg-slate-50 p-3">
                  <p className="text-xs font-medium text-slate-500">Fecha/Hora</p>
                  <p className="mt-1 font-semibold text-slate-900">
                    {new Date(detailOrder.created_at).toLocaleString('es-MX')}
                  </p>
                </div>
                <div className="rounded-lg bg-slate-50 p-3">
                  <p className="text-xs font-medium text-slate-500">Cajero</p>
                  <p className="mt-1 font-semibold text-slate-900">
                    {detailOrder.cash_register?.user?.name || 'N/A'}
                  </p>
                </div>
                <div className="rounded-lg bg-slate-50 p-3">
                  <p className="text-xs font-medium text-slate-500">Método de Pago</p>
                  <p className="mt-1 font-semibold text-slate-900">
                    {detailOrder.payment_method?.name || 'N/A'}
                  </p>
                </div>
                <div className="rounded-lg bg-slate-50 p-3">
                  <p className="text-xs font-medium text-slate-500">Estatus</p>
                  <Tag
                    value={detailOrder.status === 'completed' ? 'Completado' : 'Cancelado'}
                    severity={detailOrder.status === 'completed' ? 'success' : 'danger'}
                    className="mt-1 text-xs"
                  />
                </div>
              </div>

              {detailOrder.status === 'canceled' && (
                <div className="rounded-lg bg-rose-50 p-3 text-sm">
                  <p className="font-medium text-rose-700">Cancelado por: {detailOrder.canceled_by_user?.name || 'N/A'}</p>
                  <p className="text-rose-600">Motivo: {detailOrder.cancellation_reason}</p>
                  <p className="text-xs text-rose-500 mt-1">
                    {detailOrder.canceled_at && new Date(detailOrder.canceled_at).toLocaleString('es-MX')}
                  </p>
                </div>
              )}

              <div className="rounded-lg border border-slate-200">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-slate-200 bg-slate-50">
                      <th className="px-3 py-2 text-left text-xs font-semibold text-slate-600">Producto</th>
                      <th className="px-3 py-2 text-right text-xs font-semibold text-slate-600">Qty</th>
                      <th className="px-3 py-2 text-right text-xs font-semibold text-slate-600">P. Unit.</th>
                      <th className="px-3 py-2 text-right text-xs font-semibold text-slate-600">Desc.</th>
                      <th className="px-3 py-2 text-right text-xs font-semibold text-slate-600">Subtotal</th>
                    </tr>
                  </thead>
                  <tbody>
                    {detailOrder.items?.map((item, i) => (
                      <tr key={i} className="border-b border-slate-100">
                        <td className="px-3 py-2 text-slate-900">
                          {item.product?.name || 'Producto eliminado'}
                          {item.promotion && (
                            <span className="ml-1 text-xs text-emerald-600">({item.promotion.name})</span>
                          )}
                        </td>
                        <td className="px-3 py-2 text-right text-slate-700">{item.quantity}</td>
                        <td className="px-3 py-2 text-right text-slate-700">{fmt(item.base_price_at_sale)}</td>
                        <td className="px-3 py-2 text-right text-emerald-600">
                          {parseFloat(item.discount_amount_at_sale) > 0 ? `-${fmt(item.discount_amount_at_sale)}` : '-'}
                        </td>
                        <td className="px-3 py-2 text-right font-semibold text-slate-900">{fmt(item.final_price_at_sale)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <div className="rounded-lg bg-slate-50 p-3">
                {parseFloat(detailOrder.discount_total ?? 0) > 0 && (
                  <div className="flex justify-between text-sm font-semibold text-amber-700">
                    <span>
                      Descuento
                      {detailOrder.promotion && (
                        <span className="ml-1 text-xs font-normal text-amber-600">({detailOrder.promotion.name})</span>
                      )}
                      {detailOrder.discount_type === 'percentage' && (
                        <span className="ml-1 text-xs font-normal text-amber-600">({detailOrder.discount_value}%)</span>
                      )}
                    </span>
                    <span>-{fmt(detailOrder.discount_total)}</span>
                  </div>
                )}
                <div className="flex justify-between text-sm text-slate-600">
                  <span>Subtotal</span><span>{fmt(detailOrder.subtotal)}</span>
                </div>
                <div className="flex justify-between text-sm text-slate-600">
                  <span>IVA ({(taxRate * 100).toFixed(0)}%)</span><span>{fmt(detailOrder.iva_total)}</span>
                </div>
                <div className="mt-2 flex justify-between border-t border-slate-200 pt-2 text-lg font-bold text-slate-900">
                  <span>Total</span><span>{fmt(detailOrder.total)}</span>
                </div>
                {detailOrder.amount_received != null && parseFloat(detailOrder.amount_received) > 0 && (
                  <>
                    <div className="mt-2 flex justify-between border-t border-slate-200 pt-2 text-sm text-slate-600">
                      <span>Efectivo Recibido</span><span>{fmt(detailOrder.amount_received)}</span>
                    </div>
                    <div className="flex justify-between text-sm font-semibold text-emerald-600">
                      <span>Cambio Devuelto</span><span>{fmt(detailOrder.amount_change)}</span>
                    </div>
                  </>
                )}
              </div>
            </div>
          ) : null}
        </div>
      </Dialog>

      {/* Print Modal */}
      <Dialog
        visible={showPrint}
        onHide={() => setShowPrint(false)}
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
          <div className="mb-4 flex items-center justify-between print:hidden">
            <h3 className="text-lg font-semibold text-slate-900">Reimprimir Ticket</h3>
            <div className="flex gap-2">
              <Button
                label="Ticketera"
                icon="pi pi-server"
                onClick={() => handleDirectPrint(printOrder.id)}
                loading={directPrinting}
                disabled={directPrinting}
                className="cursor-pointer rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-500"
                pt={{ root: { className: 'border-0' } }}
                tooltip="Imprimir directo a impresora térmica ESC/POS"
                tooltipOptions={{ position: 'top' }}
              />
              <Button
                label="Navegador"
                icon="pi pi-print"
                onClick={handlePrint}
                className="cursor-pointer rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-500"
                pt={{ root: { className: 'border-0' } }}
                tooltip="Imprimir desde el navegador"
                tooltipOptions={{ position: 'top' }}
              />
            </div>
          </div>
          {printOrder && printTicketConfig && (
            <TicketPreview order={printOrder} ticketConfig={printTicketConfig} taxRate={taxRate} />
          )}
        </div>
      </Dialog>

      {/* Cancel Modal */}
      <Dialog
        visible={showCancel}
        onHide={() => !canceling && setShowCancel(false)}
        closable={!canceling}
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
          <div className="mb-5 flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-50">
              <i className="pi pi-ban text-rose-600" />
            </div>
            <div>
              <h3 className="text-lg font-semibold text-slate-900">Cancelar Orden</h3>
              {cancelOrder && (
                <p className="text-xs text-slate-500">
                  Folio: {cancelOrder.id?.substring(0, 8).toUpperCase()} — Total: {fmt(cancelOrder.total)}
                </p>
              )}
            </div>
          </div>

          <div className="mb-4 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700 font-medium">
            Esta accion revertira el stock de los productos y marcara la orden como cancelada. Requiere contraseña de Administrador.
          </div>

          <div className="space-y-4">
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Motivo de cancelacion *</label>
              <InputText
                value={cancelReason}
                onChange={(e) => setCancelReason(e.target.value)}
                placeholder="Describe el motivo de la cancelacion..."
                disabled={canceling}
                className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                pt={{ root: { className: 'w-full' } }}
              />
            </div>

            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Contraseña de Administrador *</label>
              <Password
                value={cancelPassword}
                onChange={(e) => setCancelPassword(e.target.value)}
                feedback={false}
                toggleMask
                disabled={canceling}
                placeholder="Ingresa la contraseña del Admin"
                className="w-full"
                inputClassName="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                pt={{ root: { className: 'w-full' } }}
              />
            </div>
          </div>

          <div className="mt-6 flex gap-3">
            <Button
              label="Cancelar"
              onClick={() => setShowCancel(false)}
              disabled={canceling}
              className="flex-1 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
              pt={{ root: { className: 'border border-slate-200' } }}
            />
            <Button
              label={canceling ? 'Procesando...' : 'Confirmar Cancelacion'}
              onClick={handleCancel}
              disabled={canceling || !cancelPassword.trim() || !cancelReason.trim()}
              loading={canceling}
              className="flex-1 rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-rose-500 disabled:opacity-50"
              pt={{ root: { className: 'border-0' } }}
            />
          </div>
        </div>
      </Dialog>

      {/* Hidden print-only ticket rendered outside any Dialog */}
      {printOrder && printTicketConfig && (
        <div className="hidden print:block">
          <TicketPreview order={printOrder} ticketConfig={printTicketConfig} taxRate={taxRate} />
        </div>
      )}
    </AppLayout>
  );
}
