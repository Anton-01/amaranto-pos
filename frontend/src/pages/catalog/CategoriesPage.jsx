import { useState, useEffect, useCallback } from 'react';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { InputText } from 'primereact/inputtext';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { InputSwitch } from 'primereact/inputswitch';
import { toast } from 'sonner';
import api from '../../api/axios';
import AppLayout from '../../components/layout/AppLayout';
import DeleteDialog from '../../components/catalog/DeleteDialog';

export default function CategoriesPage() {
  const [categories, setCategories] = useState([]);
  const [loading, setLoading] = useState(true);
  const [globalFilter, setGlobalFilter] = useState('');

  const [showForm, setShowForm] = useState(false);
  const [editingCategory, setEditingCategory] = useState(null);
  const [formData, setFormData] = useState({ name: '', is_active: true });
  const [saving, setSaving] = useState(false);

  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleting, setDeleting] = useState(false);
  const [fieldErrors, setFieldErrors] = useState({});

  const fetchCategories = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get('/categories');
      setCategories(res.data.data);
    } catch {
      toast.error('Error al cargar categorias.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchCategories(); }, [fetchCategories]);

  const openCreate = () => {
    setEditingCategory(null);
    setFormData({ name: '', is_active: true });
    setFieldErrors({});
    setShowForm(true);
  };

  const openEdit = (cat) => {
    setEditingCategory(cat);
    setFormData({ name: cat.name, is_active: cat.is_active });
    setFieldErrors({});
    setShowForm(true);
  };

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    setFieldErrors({});
    try {
      if (editingCategory) {
        await api.put(`/categories/${editingCategory.id}`, formData);
        toast.success('Categoria actualizada.');
      } else {
        await api.post('/categories', formData);
        toast.success('Categoria creada.');
      }
      setShowForm(false);
      fetchCategories();
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
      await api.delete(`/categories/${deleteTarget.id}`, { data: { deletion_reason: reason } });
      toast.success('Categoria eliminada.');
      setDeleteTarget(null);
      fetchCategories();
    } catch (err) {
      toast.error(err.response?.data?.message || 'Error al eliminar.');
    } finally {
      setDeleting(false);
    }
  };

  const handleToggleStatus = async (row) => {
    const prev = row.is_active;
    setCategories(cs => cs.map(c => c.id === row.id ? { ...c, is_active: !prev } : c));
    try {
      await api.patch(`/categories/${row.id}/toggle-status`);
    } catch {
      setCategories(cs => cs.map(c => c.id === row.id ? { ...c, is_active: prev } : c));
      toast.error('Error: No se pudo actualizar el estatus del registro.');
    }
  };

  const statusTemplate = (row) => (
    <InputSwitch
      checked={row.is_active}
      onChange={() => handleToggleStatus(row)}
      style={{ cursor: 'pointer' }}
    />
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

  return (
    <AppLayout>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Categorias</h1>
          <p className="text-sm text-slate-500">Administra las categorias del catalogo.</p>
        </div>
        <Button
          label="Nueva Categoria"
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
            placeholder="Buscar categoria..."
            className="w-72 rounded-lg border-slate-200 px-3 py-2 text-sm"
            pt={{ root: { className: 'w-72' } }}
          />
        </div>

        <DataTable
          value={categories}
          loading={loading}
          globalFilter={globalFilter}
          emptyMessage="No se encontraron categorias."
          sortField="name"
          sortOrder={1}
          stripedRows
          pt={{
            root: { className: 'text-sm' },
            thead: { className: 'bg-slate-50' },
          }}
        >
          <Column field="name" header="Nombre" sortable className="font-medium" />
          <Column field="products_count" header="Productos" sortable className="text-center" />
          <Column field="is_active" header="Estatus" body={statusTemplate} sortable />
          <Column header="Acciones" body={actionsTemplate} className="w-32" />
        </DataTable>
      </div>

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
            {editingCategory ? 'Editar Categoria' : 'Nueva Categoria'}
          </h3>

          <div className="mb-4">
            <label className="mb-1.5 block text-sm font-medium text-slate-700">Nombre *</label>
            <InputText
              value={formData.name}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              placeholder="Ej: Bebidas"
              required
              disabled={saving}
              className="w-full rounded-lg border-slate-200 px-3 py-2 text-sm"
              pt={{ root: { className: 'w-full' } }}
            />
            {fieldErrors.name && <p className="mt-1 text-xs text-rose-500">{fieldErrors.name}</p>}
          </div>

          <div className="mb-6 flex items-center gap-2">
            <input
              type="checkbox"
              id="cat_active"
              checked={formData.is_active}
              onChange={(e) => setFormData({ ...formData, is_active: e.target.checked })}
              disabled={saving}
              className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
            />
            <label htmlFor="cat_active" className="text-sm text-slate-700">Categoria activa</label>
          </div>

          <div className="flex gap-3">
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
              disabled={!formData.name.trim() || saving}
              loading={saving}
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
        itemName={deleteTarget?.name || 'categoria'}
      />
    </AppLayout>
  );
}
