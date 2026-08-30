import { useState, useEffect, useCallback } from 'react';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { InputText } from 'primereact/inputtext';
import { InputNumber } from 'primereact/inputnumber';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { Tag } from 'primereact/tag';
import { Dropdown } from 'primereact/dropdown';
import { toast } from 'sonner';
import api from '../../api/axios';
import AppLayout from '../../components/layout/AppLayout';
import { tableStatusMeta, fmtCurrency } from '../../components/dining/tableStatus';
import { STACK_TABLE, STACK_CLASS, HIDE_BELOW } from '../../lib/responsive';

const statusOptions = [
  { label: 'Disponible', value: 'available' },
  { label: 'Reservada', value: 'reserved' },
];

const emptyForm = { name: '', capacity: 4, zone: '', status: 'available', is_active: true };

export default function TablesPage() {
  const [tables, setTables] = useState([]);
  const [loading, setLoading] = useState(true);
  const [globalFilter, setGlobalFilter] = useState('');

  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState(null);
  const [formData, setFormData] = useState(emptyForm);
  const [saving, setSaving] = useState(false);
  const [fieldErrors, setFieldErrors] = useState({});

  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleting, setDeleting] = useState(false);

  const fetchTables = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get('/tables', { params: { include_inactive: 1 } });
      setTables(res.data.data);
    } catch {
      toast.error('Error al cargar el catálogo de mesas.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchTables(); }, [fetchTables]);

  const openCreate = () => {
    setEditing(null);
    setFormData(emptyForm);
    setFieldErrors({});
    setShowForm(true);
  };

  const openEdit = (table) => {
    setEditing(table);
    setFormData({
      name: table.name,
      capacity: table.capacity,
      zone: table.zone ?? '',
      status: table.status === 'occupied' ? 'available' : table.status,
      is_active: table.is_active,
    });
    setFieldErrors({});
    setShowForm(true);
  };

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    setFieldErrors({});

    const payload = { ...formData, zone: formData.zone || null };
    // El estatus de una mesa ocupada lo gobierna su sesion, no el catalogo.
    if (editing?.status === 'occupied') delete payload.status;

    try {
      if (editing) {
        await api.put(`/tables/${editing.id}`, payload);
        toast.success('Mesa actualizada.');
      } else {
        await api.post('/tables', payload);
        toast.success('Mesa creada.');
      }
      setShowForm(false);
      fetchTables();
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

  const handleDelete = async () => {
    setDeleting(true);
    try {
      await api.delete(`/tables/${deleteTarget.id}`);
      toast.success('Mesa eliminada.');
      setDeleteTarget(null);
      fetchTables();
    } catch (err) {
      const data = err.response?.data;
      if (data?.code === 'ERR_TABLE_HAS_SESSIONS') {
        toast.error('No se puede eliminar: tiene consumos históricos.', {
          description: 'Desactívala para conservar su historial.',
        });
      } else {
        toast.error(data?.message || 'Error al eliminar.');
      }
    } finally {
      setDeleting(false);
    }
  };

  const statusTemplate = (row) => {
    const meta = tableStatusMeta(row.status);
    return <Tag value={meta.label} severity={meta.severity} className="text-xs" />;
  };

  const activeTemplate = (row) => (
    <Tag
      value={row.is_active ? 'Activa' : 'Inactiva'}
      severity={row.is_active ? 'success' : 'danger'}
      className="text-xs"
    />
  );

  const sessionTemplate = (row) => {
    if (!row.active_session) return <span className="text-xs text-slate-400">—</span>;
    return (
      <div className="text-xs">
        <p className="font-medium text-slate-700">{row.active_session.waiter?.name ?? 'Sin mesero'}</p>
        <p className="text-slate-500">
          {row.active_session.items_count} prod. · {fmtCurrency(row.active_session.total)}
        </p>
      </div>
    );
  };

  const actionsTemplate = (row) => (
    <div className="flex gap-1">
      <Button
        icon="pi pi-pencil"
        rounded
        text
        severity="secondary"
        tooltip="Editar"
        tooltipOptions={{ position: 'top' }}
        onClick={() => openEdit(row)}
        className="cursor-pointer h-8 w-8"
      />
      <Button
        icon="pi pi-trash"
        rounded
        text
        severity="danger"
        tooltip={row.status === 'occupied' ? 'Mesa ocupada' : 'Eliminar'}
        tooltipOptions={{ position: 'top' }}
        disabled={row.status === 'occupied'}
        onClick={() => setDeleteTarget(row)}
        className="cursor-pointer h-8 w-8"
      />
    </div>
  );

  return (
    <AppLayout>
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">Catálogo de Mesas</h1>
          <p className="text-sm text-slate-500">Alta, capacidad y zona de las mesas del comedor.</p>
        </div>
        <div className="flex items-center gap-2">
          <InputText
            value={globalFilter}
            onChange={(e) => setGlobalFilter(e.target.value)}
            placeholder="Buscar mesa o zona..."
            className="w-56 rounded-lg border-slate-200 px-3 py-2 text-sm"
          />
          <Button
            label="Nueva Mesa"
            icon="pi pi-plus"
            onClick={openCreate}
            className="cursor-pointer rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500"
            pt={{ root: { className: 'border-0' } }}
          />
        </div>
      </div>

      <div className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <DataTable
          value={tables}
          loading={loading}
          globalFilter={globalFilter}
          globalFilterFields={['name', 'zone']}
          paginator
          rows={15}
          emptyMessage="No hay mesas registradas."
          className={`text-sm ${STACK_CLASS}`}
          {...STACK_TABLE}
        >
          <Column field="name" header="Mesa" sortable />
          <Column field="capacity" header="Capacidad" sortable body={(r) => `${r.capacity} lugares`} />
          <Column field="zone" header="Zona" sortable body={(r) => r.zone || <span className="text-slate-400">—</span>} />
          <Column field="status" header="Estatus" body={statusTemplate} sortable />
          <Column header="Cuenta abierta" body={sessionTemplate} />
          {/* Soft-delete state is an admin detail; status and open tab are what
              a phone needs to see. */}
          <Column field="is_active" header="Alta" body={activeTemplate} sortable className={HIDE_BELOW.md} />
          <Column header="Acciones" body={actionsTemplate} style={{ width: '110px' }} />
        </DataTable>
      </div>

      {/* Alta / edicion */}
      <Dialog
        visible={showForm}
        onHide={() => !saving && setShowForm(false)}
        modal
        header={null}
        className="w-full max-w-md"
        pt={{
          mask: { className: 'backdrop-blur-sm bg-black/30' },
          root: { className: 'rounded-2xl border-0 shadow-2xl' },
          content: { className: 'p-0' },
        }}
      >
        <form onSubmit={handleSave} className="p-6">
          <h3 className="text-lg font-semibold text-slate-900">
            {editing ? `Editar ${editing.name}` : 'Nueva Mesa'}
          </h3>

          <div className="mt-5 space-y-4">
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Nombre o número *</label>
              <InputText
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                placeholder="Mesa 12, Barra 3, Terraza A..."
                maxLength={60}
                disabled={saving}
                className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                pt={{ root: { className: 'w-full' } }}
              />
              {fieldErrors.name && <p className="mt-1 text-xs text-rose-600">{fieldErrors.name}</p>}
            </div>

            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Capacidad (comensales) *</label>
              <InputNumber
                value={formData.capacity}
                onValueChange={(e) => setFormData({ ...formData, capacity: e.value })}
                min={1}
                max={100}
                showButtons
                disabled={saving}
                className="w-full"
                inputClassName="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                pt={{ root: { className: 'w-full' } }}
              />
              {fieldErrors.capacity && <p className="mt-1 text-xs text-rose-600">{fieldErrors.capacity}</p>}
            </div>

            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">
                Zona <span className="text-xs text-slate-400">(opcional)</span>
              </label>
              <InputText
                value={formData.zone}
                onChange={(e) => setFormData({ ...formData, zone: e.target.value })}
                placeholder="Salón, Terraza, Barra..."
                maxLength={60}
                disabled={saving}
                className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                pt={{ root: { className: 'w-full' } }}
              />
            </div>

            {editing?.status === 'occupied' ? (
              <div className="rounded-lg bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700">
                La mesa tiene una cuenta abierta: su estatus se libera al cobrarla.
              </div>
            ) : (
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Estatus</label>
                <Dropdown
                  value={formData.status}
                  options={statusOptions}
                  onChange={(e) => setFormData({ ...formData, status: e.value })}
                  disabled={saving}
                  className="w-full text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>
            )}

            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Alta en el plano</label>
              <Dropdown
                value={formData.is_active}
                options={[{ label: 'Activa', value: true }, { label: 'Inactiva', value: false }]}
                onChange={(e) => setFormData({ ...formData, is_active: e.value })}
                disabled={saving}
                className="w-full text-sm"
                pt={{ root: { className: 'w-full' } }}
              />
            </div>
          </div>

          <div className="mt-6 flex gap-3">
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
              label={saving ? 'Guardando...' : 'Guardar'}
              loading={saving}
              disabled={saving || !formData.name.trim()}
              className="flex-1 cursor-pointer rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
              pt={{ root: { className: 'border-0' } }}
            />
          </div>
        </form>
      </Dialog>

      {/* Baja */}
      <Dialog
        visible={deleteTarget !== null}
        onHide={() => !deleting && setDeleteTarget(null)}
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
          <h3 className="text-lg font-semibold text-slate-900">Eliminar {deleteTarget?.name}</h3>
          <p className="mt-2 text-sm text-slate-600">
            Solo es posible eliminar mesas que nunca hayan tenido consumos. Si la mesa ya operó,
            desactívala para conservar su historial.
          </p>
          <div className="mt-6 flex gap-3">
            <Button
              type="button"
              label="Cancelar"
              onClick={() => setDeleteTarget(null)}
              disabled={deleting}
              className="flex-1 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
              pt={{ root: { className: 'border border-slate-200' } }}
            />
            <Button
              type="button"
              label={deleting ? 'Eliminando...' : 'Eliminar'}
              onClick={handleDelete}
              loading={deleting}
              disabled={deleting}
              className="flex-1 cursor-pointer rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-rose-500 disabled:opacity-50"
              pt={{ root: { className: 'border-0' } }}
            />
          </div>
        </div>
      </Dialog>
    </AppLayout>
  );
}
