import { useState, useEffect, useCallback } from 'react';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { InputText } from 'primereact/inputtext';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { Tag } from 'primereact/tag';
import { Dropdown } from 'primereact/dropdown';
import { toast } from 'sonner';
import api from '../../api/axios';
import AppLayout from '../../components/layout/AppLayout';

const statusFilterOptions = [
  { label: 'Todos', value: null },
  { label: 'Activo', value: 'active' },
  { label: 'Inactivo', value: 'inactive' },
];

export default function PaymentMethodsPage() {
  const [methods, setMethods] = useState([]);
  const [loading, setLoading] = useState(true);
  const [globalFilter, setGlobalFilter] = useState('');

  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState(null);
  const [formData, setFormData] = useState({ name: '', slug: '', status: 'active' });
  const [saving, setSaving] = useState(false);
  const [fieldErrors, setFieldErrors] = useState({});

  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleting, setDeleting] = useState(false);

  const fetchMethods = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get('/payment-methods', { params: { status: 'all' } });
      setMethods(res.data.data);
    } catch {
      toast.error('Error al cargar métodos de pago.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchMethods(); }, [fetchMethods]);

  const openCreate = () => {
    setEditing(null);
    setFormData({ name: '', slug: '', status: 'active' });
    setFieldErrors({});
    setShowForm(true);
  };

  const openEdit = (pm) => {
    setEditing(pm);
    setFormData({ name: pm.name, slug: pm.slug, status: pm.status });
    setFieldErrors({});
    setShowForm(true);
  };

  const generateSlug = (name) => {
    return name
      .toLowerCase()
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '');
  };

  const handleNameChange = (val) => {
    const newData = { ...formData, name: val };
    if (!editing) {
      newData.slug = generateSlug(val);
    }
    setFormData(newData);
  };

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    setFieldErrors({});
    try {
      if (editing) {
        await api.put(`/payment-methods/${editing.id}`, formData);
        toast.success('Método de pago actualizado.');
      } else {
        await api.post('/payment-methods', formData);
        toast.success('Método de pago creado.');
      }
      setShowForm(false);
      fetchMethods();
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
      await api.delete(`/payment-methods/${deleteTarget.id}`);
      toast.success('Método de pago eliminado.');
      setDeleteTarget(null);
      fetchMethods();
    } catch (err) {
      const code = err.response?.data?.code;
      if (code === 'ERR_PAYMENT_METHOD_HAS_SALES') {
        toast.error('No se puede eliminar: tiene ventas asociadas. Cambia su estatus a Inactivo.');
      } else {
        toast.error(err.response?.data?.message || 'Error al eliminar.');
      }
    } finally {
      setDeleting(false);
    }
  };

  const statusTemplate = (row) => (
    <Tag
      value={row.status === 'active' ? 'Activo' : 'Inactivo'}
      severity={row.status === 'active' ? 'success' : 'danger'}
      className="text-xs"
    />
  );

  const systemTemplate = (row) => (
    row.is_system ? (
      <Tag value="Sistema" severity="info" className="text-xs" />
    ) : (
      <Tag value="Personalizado" severity="secondary" className="text-xs" />
    )
  );

  const actionsTemplate = (row) => (
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
      {!row.is_system && (
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
      )}
    </div>
  );

  return (
    <AppLayout>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Métodos de Pago</h1>
          <p className="text-sm text-slate-500">Administra los métodos de pago aceptados en el POS.</p>
        </div>
        <Button
          label="Nuevo Método"
          onClick={openCreate}
          className="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
          pt={{ root: { className: 'border-0' } }}
        />
      </div>

      <div className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <div className="border-b border-slate-200 p-4">
          <InputText
            value={globalFilter}
            onChange={(e) => setGlobalFilter(e.target.value)}
            placeholder="Buscar método de pago..."
            className="w-72 rounded-lg border-slate-200 px-3 py-2 text-sm"
            pt={{ root: { className: 'w-72' } }}
          />
        </div>

        <DataTable
          value={methods}
          loading={loading}
          globalFilter={globalFilter}
          emptyMessage="No se encontraron métodos de pago."
          sortField="name"
          sortOrder={1}
          stripedRows
          pt={{
            root: { className: 'text-sm' },
            thead: { className: 'bg-slate-50' },
          }}
        >
          <Column field="name" header="Nombre" sortable className="font-medium" />
          <Column field="slug" header="Slug" sortable className="font-mono text-xs" />
          <Column field="status" header="Estatus" body={statusTemplate} sortable />
          <Column field="is_system" header="Tipo" body={systemTemplate} sortable />
          <Column header="Acciones" body={actionsTemplate} className="w-32" />
        </DataTable>
      </div>

      {/* Create / Edit Modal */}
      <Dialog
        visible={showForm}
        onHide={() => setShowForm(false)}
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
        <form onSubmit={handleSave} className="p-6">
          <h3 className="mb-5 text-lg font-semibold text-slate-900">
            {editing ? 'Editar Método de Pago' : 'Nuevo Método de Pago'}
          </h3>

          <div className="space-y-4">
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Nombre *</label>
              <InputText
                value={formData.name}
                onChange={(e) => handleNameChange(e.target.value)}
                placeholder="Ej: Bitcoin"
                required
                disabled={saving}
                className="w-full rounded-lg border-slate-200 px-3 py-2 text-sm"
                pt={{ root: { className: 'w-full' } }}
              />
              {fieldErrors.name && <p className="mt-1 text-xs text-rose-500">{fieldErrors.name}</p>}
            </div>

            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Slug *</label>
              <InputText
                value={formData.slug}
                onChange={(e) => setFormData({ ...formData, slug: e.target.value })}
                placeholder="Ej: bitcoin"
                required
                disabled={saving}
                className="w-full rounded-lg border-slate-200 px-3 py-2 text-sm font-mono"
                pt={{ root: { className: 'w-full' } }}
              />
              {fieldErrors.slug && <p className="mt-1 text-xs text-rose-500">{fieldErrors.slug}</p>}
            </div>

            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Estatus</label>
              <Dropdown
                value={formData.status}
                options={[
                  { label: 'Activo', value: 'active' },
                  { label: 'Inactivo', value: 'inactive' },
                ]}
                onChange={(e) => setFormData({ ...formData, status: e.value })}
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
              disabled={!formData.name.trim() || !formData.slug.trim() || saving}
              loading={saving}
              className="flex-1 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
              pt={{ root: { className: 'border-0' } }}
            />
          </div>
        </form>
      </Dialog>

      {/* Delete Confirmation Modal */}
      <Dialog
        visible={!!deleteTarget}
        onHide={() => !deleting && setDeleteTarget(null)}
        closable={!deleting}
        modal
        header={null}
        className="w-full max-w-sm"
        pt={{
          mask: { className: 'backdrop-blur-sm bg-black/30' },
          root: { className: 'rounded-2xl border-0 shadow-2xl' },
          content: { className: 'p-0' },
        }}
      >
        <div className="p-6">
          <div className="mb-5 flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-50">
              <svg className="h-5 w-5 text-rose-600" fill="none" viewBox="0 0 24 24" strokeWidth={1.75} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
              </svg>
            </div>
            <div>
              <h3 className="text-lg font-semibold text-slate-900">Eliminar Método</h3>
              <p className="text-xs text-slate-500">Esta accion no se puede deshacer.</p>
            </div>
          </div>

          <p className="mb-4 text-sm text-slate-600">
            ¿Estás seguro de que deseas eliminar <strong>{deleteTarget?.name}</strong>?
            Si tiene ventas asociadas, no podrá ser eliminado.
          </p>

          <div className="flex gap-3">
            <Button
              label="Cancelar"
              onClick={() => setDeleteTarget(null)}
              disabled={deleting}
              className="flex-1 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
              pt={{ root: { className: 'border border-slate-200' } }}
            />
            <Button
              label={deleting ? 'Eliminando...' : 'Eliminar'}
              onClick={handleDelete}
              disabled={deleting}
              loading={deleting}
              className="flex-1 rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-rose-500 disabled:opacity-50"
              pt={{ root: { className: 'border-0' } }}
            />
          </div>
        </div>
      </Dialog>
    </AppLayout>
  );
}
