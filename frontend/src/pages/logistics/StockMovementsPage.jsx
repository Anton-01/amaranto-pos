import { useState, useEffect, useCallback } from 'react';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { InputText } from 'primereact/inputtext';
import { InputNumber } from 'primereact/inputnumber';
import { Dropdown } from 'primereact/dropdown';
import { Tag } from 'primereact/tag';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { toast } from 'sonner';
import api from '../../api/axios';
import AppLayout from '../../components/layout/AppLayout';
import { STACK_TABLE, STACK_CLASS, HIDE_BELOW } from '../../lib/responsive';

const typeOptions = [
  { label: 'Entrada (Compra)', value: 'purchase_input' },
  { label: 'Merma (Salida)', value: 'merma_output' },
  { label: 'Ajuste', value: 'adjustment' },
];

const reasonOptions = [
  { label: 'Caducado', value: 'expired' },
  { label: 'Dañado / Derramado', value: 'damaged_spilled' },
  { label: 'Consumo Interno', value: 'internal_consumption' },
  { label: 'Robo / Extravío', value: 'theft_loss' },
];

const typeLabels = {
  purchase_input: 'Entrada',
  merma_output: 'Merma',
  sale_output: 'Venta',
  adjustment: 'Ajuste',
};

const typeColors = {
  purchase_input: 'success',
  merma_output: 'danger',
  sale_output: 'info',
  adjustment: 'warning',
};

const reasonLabels = {
  expired: 'Caducado',
  damaged_spilled: 'Dañado/Derramado',
  internal_consumption: 'Consumo Interno',
  theft_loss: 'Robo/Extravío',
};

const emptyForm = {
  product_id: null,
  type: null,
  quantity: null,
  cost_price_at_movement: null,
  reason: null,
};

export default function StockMovementsPage() {
  const [movements, setMovements] = useState([]);
  const [loading, setLoading] = useState(true);
  const [globalFilter, setGlobalFilter] = useState('');
  const [showForm, setShowForm] = useState(false);
  const [formData, setFormData] = useState({ ...emptyForm });
  const [saving, setSaving] = useState(false);
  const [products, setProducts] = useState([]);
  const [summary, setSummary] = useState(null);

  const fetchData = useCallback(async () => {
    setLoading(true);
    try {
      const [movRes, sumRes] = await Promise.all([
        api.get('/stock-movements', { params: { per_page: 100 } }),
        api.get('/stock-movements/summary'),
      ]);
      setMovements(movRes.data.data);
      setSummary(sumRes.data.data);
    } catch {
      toast.error('Error al cargar movimientos de stock.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchData(); }, [fetchData]);

  const fetchProducts = async () => {
    try {
      const res = await api.get('/products', { params: { per_page: 500 } });
      setProducts(res.data.data.map(p => ({ label: `${p.name} (${p.sku})`, value: p.id, cost: p.cost_price })));
    } catch {
      toast.error('Error al cargar productos.');
    }
  };

  const openForm = () => {
    setFormData({ ...emptyForm });
    fetchProducts();
    setShowForm(true);
  };

  const set = (field, value) => {
    setFormData(prev => {
      const next = { ...prev, [field]: value };
      if (field === 'product_id') {
        const prod = products.find(p => p.value === value);
        if (prod) next.cost_price_at_movement = parseFloat(prod.cost);
      }
      if (field === 'type' && value !== 'merma_output') {
        next.reason = null;
      }
      return next;
    });
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!formData.product_id || !formData.type || !formData.quantity) {
      toast.error('Completa todos los campos obligatorios.');
      return;
    }
    if (formData.type === 'merma_output' && !formData.reason) {
      toast.error('El motivo de merma es obligatorio.');
      return;
    }
    setSaving(true);
    try {
      await api.post('/stock-movements', formData);
      toast.success('Movimiento registrado exitosamente.');
      setShowForm(false);
      fetchData();
    } catch (err) {
      const msg = err.response?.data?.message || 'Error al registrar movimiento.';
      toast.error(msg);
    } finally {
      setSaving(false);
    }
  };

  const typeTemplate = (row) => (
    <Tag value={typeLabels[row.type] || row.type} severity={typeColors[row.type] || 'info'} className="text-xs" />
  );

  const reasonTemplate = (row) => {
    if (!row.reason) return <span className="text-slate-400">—</span>;
    return <span className="text-xs text-slate-600">{reasonLabels[row.reason] || row.reason}</span>;
  };

  const qtyTemplate = (row) => {
    const isInput = row.type === 'purchase_input';
    return (
      <span className={`font-semibold ${isInput ? 'text-emerald-600' : 'text-rose-600'}`}>
        {isInput ? '+' : '-'}{row.quantity}
      </span>
    );
  };

  const costTemplate = (row) => (
    <span className="text-sm">${parseFloat(row.cost_price_at_movement).toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
  );

  const totalTemplate = (row) => {
    const total = parseFloat(row.cost_price_at_movement) * row.quantity;
    return <span className="text-sm font-medium">${total.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>;
  };

  const dateTemplate = (row) => {
    const d = new Date(row.created_at);
    return d.toLocaleString('es-MX', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  };

  const purchaseSummary = summary?.by_type?.find(t => t.type === 'purchase_input');
  const mermaSummary = summary?.by_type?.find(t => t.type === 'merma_output');

  return (
    <AppLayout>
      {/* Summary cards */}
      {summary && (
        <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <p className="text-sm text-slate-500">Entradas (Compras)</p>
            <p className="mt-1 text-2xl font-bold text-emerald-600">+{purchaseSummary?.total_quantity || 0} uds</p>
            <p className="mt-0.5 text-sm text-slate-500">${parseFloat(purchaseSummary?.total_cost || 0).toLocaleString('es-MX', { minimumFractionDigits: 2 })} invertido</p>
          </div>
          <div className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <p className="text-sm text-slate-500">Mermas (Perdidas)</p>
            <p className="mt-1 text-2xl font-bold text-rose-600">-{mermaSummary?.total_quantity || 0} uds</p>
            <p className="mt-0.5 text-sm text-slate-500">${parseFloat(mermaSummary?.total_cost || 0).toLocaleString('es-MX', { minimumFractionDigits: 2 })} perdido</p>
          </div>
          <div className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
            <p className="text-sm text-slate-500">Desglose Merma por Motivo</p>
            <div className="mt-2 space-y-1">
              {summary.merma_by_reason?.map((r, i) => (
                <div key={i} className="flex justify-between text-xs">
                  <span className="text-slate-600">{reasonLabels[r.reason] || r.reason}</span>
                  <span className="font-medium text-slate-900">{r.count}x ({r.total_quantity} uds)</span>
                </div>
              ))}
              {(!summary.merma_by_reason || summary.merma_by_reason.length === 0) && (
                <p className="text-xs text-slate-400">Sin mermas registradas</p>
              )}
            </div>
          </div>
        </div>
      )}

      <div className="mb-5 flex flex-col gap-3 sm:mb-6 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Movimientos de Stock</h1>
          <p className="text-sm text-slate-500">Registro de entradas, mermas y ajustes de inventario.</p>
        </div>
        <Button
          label="Nuevo Movimiento"
          onClick={openForm}
          className="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
          pt={{ root: { className: 'border-0' } }}
        />
      </div>

      <div className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <div className="border-b border-slate-200 p-4">
          <InputText
            value={globalFilter}
            onChange={(e) => setGlobalFilter(e.target.value)}
            placeholder="Buscar por producto o usuario..."
            className="w-full rounded-lg border-slate-200 px-3 py-2 text-sm sm:w-72"
            pt={{ root: { className: 'w-full sm:w-72' } }}
          />
        </div>

        <DataTable
          value={movements}
          loading={loading}
          globalFilter={globalFilter}
          emptyMessage="No hay movimientos de stock."
          sortField="created_at"
          sortOrder={-1}
          stripedRows
          paginator
          rows={15}
          {...STACK_TABLE}
          pt={{ root: { className: `text-sm ${STACK_CLASS}` }, thead: { className: 'bg-slate-50' } }}
        >
          <Column field="created_at" header="Fecha" body={dateTemplate} sortable style={{ width: '18%' }} />
          <Column field="product.name" header="Producto" sortable style={{ width: '20%' }} />
          <Column field="type" header="Tipo" body={typeTemplate} sortable style={{ width: '10%' }} />
          <Column field="quantity" header="Cantidad" body={qtyTemplate} sortable style={{ width: '10%' }} />
          {/* Unit cost is derivable from the total; on a phone only the total
              earns a line. */}
          <Column field="cost_price_at_movement" header="Costo Unit." body={costTemplate} sortable className={HIDE_BELOW.md} style={{ width: '12%' }} />
          <Column header="Costo Total" body={totalTemplate} style={{ width: '12%' }} />
          <Column field="reason" header="Motivo" body={reasonTemplate} sortable style={{ width: '12%' }} />
          <Column field="user.name" header="Usuario" sortable className={HIDE_BELOW.lg} style={{ width: '10%' }} />
        </DataTable>
      </div>

      {/* New Movement Dialog */}
      <Dialog
        visible={showForm}
        onHide={() => !saving && setShowForm(false)}
        closable={!saving}
        modal
        header={null}
        className="w-full max-w-md"
        pt={{
          mask: { className: 'backdrop-blur-sm bg-black/30' },
          root: { className: 'rounded-2xl border-0 shadow-2xl' },
          content: { className: 'p-0' },
        }}
      >
        <form onSubmit={handleSubmit} className="p-6">
          <div className="mb-5">
            <h3 className="text-lg font-semibold text-slate-900">Nuevo Movimiento de Stock</h3>
            <p className="text-xs text-slate-500">Registra entradas, mermas o ajustes de inventario.</p>
          </div>

          <div className="space-y-4">
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Producto *</label>
              <Dropdown
                value={formData.product_id}
                options={products}
                onChange={(e) => set('product_id', e.value)}
                placeholder="Seleccionar producto"
                filter
                disabled={saving}
                className="w-full text-sm"
                pt={{ root: { className: 'w-full' } }}
              />
            </div>

            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Tipo de Movimiento *</label>
              <Dropdown
                value={formData.type}
                options={typeOptions}
                onChange={(e) => set('type', e.value)}
                placeholder="Seleccionar tipo"
                disabled={saving}
                className="w-full text-sm"
                pt={{ root: { className: 'w-full' } }}
              />
            </div>

            {formData.type === 'merma_output' && (
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Motivo de Merma *</label>
                <Dropdown
                  value={formData.reason}
                  options={reasonOptions}
                  onChange={(e) => set('reason', e.value)}
                  placeholder="Seleccionar motivo"
                  disabled={saving}
                  className="w-full text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>
            )}

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Cantidad *</label>
                <InputNumber
                  value={formData.quantity}
                  onValueChange={(e) => set('quantity', e.value)}
                  min={1}
                  disabled={saving}
                  className="w-full"
                  inputClassName="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Costo Unitario *</label>
                <InputNumber
                  value={formData.cost_price_at_movement}
                  onValueChange={(e) => set('cost_price_at_movement', e.value)}
                  mode="currency"
                  currency="MXN"
                  locale="es-MX"
                  minFractionDigits={2}
                  min={0}
                  disabled={saving}
                  className="w-full"
                  inputClassName="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>
            </div>
          </div>

          {formData.type === 'merma_output' && (
            <div className="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700">
              Las mermas reducen el stock del producto y se registran como perdida contable.
            </div>
          )}

          <div className="mt-5 flex gap-3">
            <Button
              type="button"
              label="Cancelar"
              onClick={() => setShowForm(false)}
              disabled={saving}
              className="flex-1 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
              pt={{ root: { className: 'border border-slate-200' } }}
            />
            <Button
              type="submit"
              label={saving ? 'Registrando...' : 'Registrar Movimiento'}
              disabled={saving}
              loading={saving}
              className="flex-1 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
              pt={{ root: { className: 'border-0' } }}
            />
          </div>
        </form>
      </Dialog>
    </AppLayout>
  );
}
