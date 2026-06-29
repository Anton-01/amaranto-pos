import { useState, useEffect, useCallback } from 'react';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { InputText } from 'primereact/inputtext';
import { InputNumber } from 'primereact/inputnumber';
import { Dropdown } from 'primereact/dropdown';
import { MultiSelect } from 'primereact/multiselect';
import { Calendar } from 'primereact/calendar';
import { Dialog } from 'primereact/dialog';
import { Tag } from 'primereact/tag';
import { InputSwitch } from 'primereact/inputswitch';
import { Button } from 'primereact/button';
import { toast } from 'sonner';
import api from '../../api/axios';
import { useAuth } from '../../context/AuthContext';
import AppLayout from '../../components/layout/AppLayout';
import DeleteDialog from '../../components/catalog/DeleteDialog';

const typeOptions = [
  { label: 'Porcentaje', value: 'percentage' },
  { label: 'Monto Fijo', value: 'fixed_amount' },
  { label: 'Gratis (100%)', value: 'freebie_100' },
];

const typeLabels = { percentage: 'Porcentaje', fixed_amount: 'Monto Fijo', freebie_100: 'Gratis' };

const emptyForm = {
  name: '',
  type: null,
  value: null,
  start_date: null,
  end_date: null,
  is_active: true,
  product_ids: [],
};

export default function PromotionsPage() {
  const { user } = useAuth();
  const canManage = user?.roles?.some(r => r === 'admin' || r === 'manager');

  const [promotions, setPromotions] = useState([]);
  const [products, setProducts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [globalFilter, setGlobalFilter] = useState('');

  const [showForm, setShowForm] = useState(false);
  const [editingPromo, setEditingPromo] = useState(null);
  const [formData, setFormData] = useState({ ...emptyForm });
  const [saving, setSaving] = useState(false);

  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleting, setDeleting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState({});

  const fetchData = useCallback(async () => {
    setLoading(true);
    try {
      const [promoRes, prodRes] = await Promise.all([
        api.get('/promotions'),
        api.get('/products', { params: { is_active: true } }),
      ]);
      setPromotions(promoRes.data.data);
      setProducts(prodRes.data.data.map(p => ({ label: `${p.sku} - ${p.name}`, value: p.id })));
    } catch {
      toast.error('Error al cargar datos.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchData(); }, [fetchData]);

  const set = (field, value) => setFormData(prev => ({ ...prev, [field]: value }));

  const openCreate = () => {
    setEditingPromo(null);
    setFormData({ ...emptyForm });
    setFieldErrors({});
    setShowForm(true);
  };

  const openEdit = (promo) => {
    setEditingPromo(promo);
    setFormData({
      name: promo.name,
      type: promo.type,
      value: parseFloat(promo.value),
      start_date: new Date(promo.start_date),
      end_date: new Date(promo.end_date),
      is_active: promo.is_active,
      product_ids: promo.products?.map(p => p.id) || [],
    });
    setShowForm(true);
  };

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      const payload = {
        ...formData,
        start_date: formData.start_date?.toISOString(),
        end_date: formData.end_date?.toISOString(),
      };

      if (editingPromo) {
        await api.put(`/promotions/${editingPromo.id}`, payload);
        toast.success('Promocion actualizada.');
      } else {
        await api.post('/promotions', payload);
        toast.success('Promocion creada.');
      }
      setShowForm(false);
      fetchData();
    } catch (err) {
      const errors = err.response?.data?.errors;
      if (errors) {
        setFieldErrors(Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v[0]])));
        toast.warning('Verifica los campos marcados.');
      } else {
        toast.error(err.response?.data?.message || 'Error al guardar.');
      }
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (reason) => {
    setDeleting(true);
    try {
      await api.delete(`/promotions/${deleteTarget.id}`, { data: { deletion_reason: reason } });
      toast.success('Promocion eliminada.');
      setDeleteTarget(null);
      fetchData();
    } catch (err) {
      toast.error(err.response?.data?.message || 'Error al eliminar.');
    } finally {
      setDeleting(false);
    }
  };

  const typeTemplate = (row) => {
    const colors = { percentage: 'info', fixed_amount: 'warning', freebie_100: 'success' };
    return <Tag value={typeLabels[row.type] || row.type} severity={colors[row.type]} className="text-xs" />;
  };

  const valueTemplate = (row) => {
    if (row.type === 'percentage') return `${parseFloat(row.value)}%`;
    if (row.type === 'freebie_100') return '100%';
    return `$${parseFloat(row.value).toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;
  };

  const handleToggleStatus = async (row) => {
    if (!canManage) return;
    const prev = row.is_active;
    setPromotions(ps => ps.map(p => p.id === row.id ? { ...p, is_active: !prev } : p));
    try {
      await api.patch(`/promotions/${row.id}/toggle-status`);
      toast.success('¡Estatus actualizado correctamente!');
    } catch {
      toast.error('Error: No se pudo actualizar el estatus.');
      setPromotions(ps => ps.map(p => p.id === row.id ? { ...p, is_active: prev } : p));
    }
  };

  const statusTemplate = (row) => {
    const now = new Date();
    const isCurrentlyActive = row.is_active && new Date(row.start_date) <= now && new Date(row.end_date) >= now;
    const label = isCurrentlyActive ? 'Vigente' : row.is_active ? 'Programada' : 'Inactiva';
    return (
      <div className="flex flex-col items-center gap-1">
        <InputSwitch
          checked={row.is_active}
          onChange={() => handleToggleStatus(row)}
          disabled={!canManage}
          style={{ cursor: canManage ? 'pointer' : 'not-allowed' }}
        />
        <span className={`text-xs font-medium transition-colors duration-200 ${isCurrentlyActive ? 'text-emerald-600' : row.is_active ? 'text-blue-600' : 'text-slate-500'}`}>
          {label}
        </span>
      </div>
    );
  };

  const dateTemplate = (row, field) => {
    const d = new Date(row[field]);
    return d.toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' });
  };

  const productsTemplate = (row) => {
    const count = row.products?.length || 0;
    return (
      <span className="text-xs text-slate-500">
        {count === 0 ? 'Todos' : `${count} producto(s)`}
      </span>
    );
  };

  const actionsTemplate = (row) => {
    if (!canManage) return null;
    return (
      <div className="flex gap-1">
        <Button
          icon="pi pi-pencil"
          severity="info"
          text
          rounded
          onClick={() => openEdit(row)}
          className="cursor-pointer !h-8 !w-8"
          tooltip="Editar"
          tooltipOptions={{ position: 'top' }}
        />
        <Button
          icon="pi pi-trash"
          severity="danger"
          text
          rounded
          onClick={() => setDeleteTarget(row)}
          className="cursor-pointer !h-8 !w-8"
          tooltip="Eliminar"
          tooltipOptions={{ position: 'top' }}
        />
      </div>
    );
  };

  return (
    <AppLayout>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Promociones</h1>
          <p className="text-sm text-slate-500">{promotions.length} promocion(es) registrada(s). Limite: 1 por ticket.</p>
        </div>
        {canManage && (
          <Button
            label="Nueva Promocion"
            onClick={openCreate}
            className="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
            pt={{ root: { className: 'border-0' } }}
          />
        )}
      </div>

      <div className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <div className="border-b border-slate-200 p-4">
          <InputText
            value={globalFilter}
            onChange={(e) => setGlobalFilter(e.target.value)}
            placeholder="Buscar promocion..."
            className="w-72 rounded-lg border-slate-200 px-3 py-2 text-sm"
            pt={{ root: { className: 'w-72' } }}
          />
        </div>

        <DataTable
          value={promotions}
          loading={loading}
          globalFilter={globalFilter}
          emptyMessage="No se encontraron promociones."
          sortField="created_at"
          sortOrder={-1}
          stripedRows
          paginator
          rows={15}
          pt={{ root: { className: 'text-sm' }, thead: { className: 'bg-slate-50' } }}
        >
          <Column field="name" header="Nombre" sortable />
          <Column field="type" header="Tipo" body={typeTemplate} sortable style={{ width: '10%' }} />
          <Column field="value" header="Valor" body={valueTemplate} sortable style={{ width: '10%' }} />
          <Column header="Productos" body={productsTemplate} style={{ width: '10%' }} />
          <Column field="start_date" header="Inicio" body={(r) => dateTemplate(r, 'start_date')} sortable style={{ width: '12%' }} />
          <Column field="end_date" header="Fin" body={(r) => dateTemplate(r, 'end_date')} sortable style={{ width: '12%' }} />
          <Column field="is_active" header="Estatus" body={statusTemplate} sortable style={{ width: '10%' }} />
          {canManage && <Column header="Acciones" body={actionsTemplate} style={{ width: '12%' }} />}
        </DataTable>
      </div>

      {/* Create/Edit Dialog */}
      <Dialog
        visible={showForm}
        onHide={() => setShowForm(false)}
        closable={!saving}
        modal
        header={null}
        className="w-full max-w-lg"
        pt={{
          mask: { className: 'backdrop-blur-sm bg-black/30' },
          root: { className: 'rounded-2xl border-0 shadow-2xl' },
          content: { className: 'p-0' },
        }}
      >
        <form onSubmit={handleSave} className="p-6">
          <h3 className="mb-5 text-lg font-semibold text-slate-900">
            {editingPromo ? 'Editar Promocion' : 'Nueva Promocion'}
          </h3>

          <div className="space-y-4">
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Nombre *</label>
              <InputText
                value={formData.name}
                onChange={(e) => set('name', e.target.value)}
                placeholder="Ej: 2x1 Hamburguesas"
                required disabled={saving}
                className="w-full rounded-lg border-slate-200 px-3 py-2 text-sm"
                pt={{ root: { className: 'w-full' } }}
              />
              {fieldErrors.name && <p className="mt-1 text-xs text-rose-500">{fieldErrors.name}</p>}
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Tipo *</label>
                <Dropdown
                  value={formData.type}
                  options={typeOptions}
                  onChange={(e) => set('type', e.value)}
                  placeholder="Seleccionar"
                  disabled={saving}
                  className="w-full rounded-lg border-slate-200 text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">
                  {formData.type === 'percentage' ? 'Porcentaje *' : formData.type === 'freebie_100' ? 'Valor (auto: 100)' : 'Monto *'}
                </label>
                <InputNumber
                  value={formData.type === 'freebie_100' ? 100 : formData.value}
                  onValueChange={(e) => set('value', e.value)}
                  disabled={saving || formData.type === 'freebie_100'}
                  suffix={formData.type === 'percentage' ? '%' : ''}
                  mode={formData.type === 'fixed_amount' ? 'currency' : 'decimal'}
                  currency="MXN"
                  locale="es-MX"
                  min={0}
                  max={formData.type === 'percentage' ? 100 : undefined}
                  minFractionDigits={2}
                  className="w-full"
                  inputClassName="w-full rounded-lg border-slate-200 px-3 py-2 text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Inicio *</label>
                <Calendar
                  value={formData.start_date}
                  onChange={(e) => set('start_date', e.value)}
                  showTime
                  dateFormat="dd/mm/yy"
                  disabled={saving}
                  className="w-full"
                  inputClassName="w-full rounded-lg border-slate-200 px-3 py-2 text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Fin *</label>
                <Calendar
                  value={formData.end_date}
                  onChange={(e) => set('end_date', e.value)}
                  showTime
                  dateFormat="dd/mm/yy"
                  disabled={saving}
                  minDate={formData.start_date || undefined}
                  className="w-full"
                  inputClassName="w-full rounded-lg border-slate-200 px-3 py-2 text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>
            </div>

            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Productos aplicables</label>
              <MultiSelect
                value={formData.product_ids}
                options={products}
                onChange={(e) => set('product_ids', e.value)}
                placeholder="Todos los productos (dejar vacio)"
                disabled={saving}
                filter
                maxSelectedLabels={3}
                className="w-full text-sm"
                pt={{ root: { className: 'w-full' } }}
              />
              <p className="mt-1 text-xs text-slate-400">Si no seleccionas productos, aplica a todo el catalogo.</p>
            </div>

            <div className="flex items-center gap-2">
              <input
                type="checkbox"
                id="promo_active"
                checked={formData.is_active}
                onChange={(e) => set('is_active', e.target.checked)}
                disabled={saving}
                className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
              />
              <label htmlFor="promo_active" className="text-sm text-slate-700">Promocion activa</label>
            </div>
          </div>

          <div className="mt-6 flex gap-3">
            <Button type="button" label="Cancelar" onClick={() => setShowForm(false)} disabled={saving}
              className="flex-1 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
              pt={{ root: { className: 'border border-slate-200' } }}
            />
            <Button type="submit" label={saving ? 'Guardando...' : 'Guardar'} disabled={saving} loading={saving}
              className="flex-1 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
              pt={{ root: { className: 'border-0' } }}
            />
          </div>
        </form>
      </Dialog>

      <DeleteDialog
        visible={!!deleteTarget}
        onHide={() => setDeleteTarget(null)}
        onConfirm={handleDelete}
        loading={deleting}
        itemName={deleteTarget?.name || 'promocion'}
      />
    </AppLayout>
  );
}
