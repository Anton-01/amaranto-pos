import { useState, useEffect, useCallback } from 'react';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { Dropdown } from 'primereact/dropdown';
import { InputSwitch } from 'primereact/inputswitch';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { Tag } from 'primereact/tag';
import { toast } from 'sonner';
import api from '../../api/axios';

const emptyForm = {
  module_name: '',
  duration_minutes: 15,
  is_active: true,
};

/**
 * Per-module cache policy administration.
 *
 * Each row binds a heavy read path (dashboard metrics, hourly trend, top
 * products, financial analytics, POS catalog) to a refresh window. The backend
 * reads that window right before answering, so a change here takes effect on
 * the very next request — no redeploy, no cache warm-up.
 *
 * The table always lists the whole catalog of cacheable modules: the ones with
 * no policy yet show up as "Sin configurar" and run on the conservative
 * default until an administrator sets a window for them.
 */
export default function CacheSettingsPanel() {
  const [configurations, setConfigurations] = useState([]);
  const [modules, setModules] = useState([]);
  const [durations, setDurations] = useState([]);
  const [loading, setLoading] = useState(true);

  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState(null);
  const [formData, setFormData] = useState(emptyForm);
  const [fieldErrors, setFieldErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const [flushingId, setFlushingId] = useState(null);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleting, setDeleting] = useState(false);

  const fetchConfigurations = useCallback(async () => {
    setLoading(true);
    try {
      const [list, catalogs] = await Promise.all([
        api.get('/admin/cache-configurations'),
        api.get('/admin/cache-configurations/catalogs'),
      ]);
      setConfigurations(list.data.data);
      setModules(catalogs.data.data.modules);
      setDurations(catalogs.data.data.durations);
    } catch {
      toast.error('Error al cargar las políticas de caché.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchConfigurations(); }, [fetchConfigurations]);

  /**
   * Opens the form for a row.
   *
   * A row without policy (`is_configured === false`) has no id, so saving it
   * creates the policy instead of updating it. Its module comes pre-selected
   * because the administrator already picked it by clicking that row.
   */
  const openForm = (row = null) => {
    setEditing(row?.is_configured ? row : null);
    setFormData(row
      ? { module_name: row.module_name, duration_minutes: row.duration_minutes, is_active: row.is_active }
      : emptyForm);
    setFieldErrors({});
    setShowForm(true);
  };

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    setFieldErrors({});

    try {
      if (editing) {
        // A policy stays bound to its module for life: only the window and
        // the kill switch travel on an update.
        await api.put(`/admin/cache-configurations/${editing.id}`, {
          duration_minutes: formData.duration_minutes,
          is_active: formData.is_active,
        });
        toast.success('Política de caché actualizada.');
      } else {
        await api.post('/admin/cache-configurations', formData);
        toast.success('Política de caché creada.');
      }
      setShowForm(false);
      fetchConfigurations();
    } catch (err) {
      const errors = err.response?.data?.errors;
      if (errors) {
        setFieldErrors(Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v[0]])));
        toast.warning('Verifica los campos marcados.');
      } else {
        toast.error(err.response?.data?.message || 'Error al guardar la política de caché.');
      }
    } finally {
      setSaving(false);
    }
  };

  /**
   * Optimistic kill switch: the toggle flips immediately and rolls back to its
   * previous value if the request fails, so the table never shows a state the
   * server did not accept.
   */
  const handleToggleStatus = async (row) => {
    const previous = row.is_active;
    setConfigurations(rows => rows.map(r => (r.id === row.id ? { ...r, is_active: !previous } : r)));

    try {
      await api.patch(`/admin/cache-configurations/${row.id}/toggle-status`);
      toast.success(previous ? 'Caché desactivado: el módulo vuelve a leer en vivo.' : 'Caché activado para el módulo.');
    } catch {
      setConfigurations(rows => rows.map(r => (r.id === row.id ? { ...r, is_active: previous } : r)));
      toast.error('No se pudo cambiar el estado de la política.');
    }
  };

  const handleFlush = async (row) => {
    setFlushingId(row.id);
    try {
      await api.post(`/admin/cache-configurations/${row.id}/flush`);
      toast.success('Caché purgado. La próxima consulta se reconstruye en vivo.');
    } catch {
      toast.error('No se pudo purgar el caché del módulo.');
    } finally {
      setFlushingId(null);
    }
  };

  const handleDelete = async () => {
    setDeleting(true);
    try {
      await api.delete(`/admin/cache-configurations/${deleteTarget.id}`);
      toast.success('Política eliminada. El módulo vuelve al tiempo por defecto.');
      setDeleteTarget(null);
      fetchConfigurations();
    } catch (err) {
      toast.error(err.response?.data?.message || 'Error al eliminar la política.');
    } finally {
      setDeleting(false);
    }
  };

  const labelFor = (options, value) => options.find(o => o.value === value)?.label || value;

  // Modules already holding a policy cannot be created twice: the backend
  // enforces it with a unique index and the dropdown mirrors that here.
  const availableModules = editing
    ? modules
    : modules.filter(m => !configurations.some(c => c.is_configured && c.module_name === m.value));

  const moduleTemplate = (row) => (
    <div className="flex flex-col">
      <span className="text-sm font-semibold text-slate-900">{labelFor(modules, row.module_name)}</span>
      <span className="font-mono text-[11px] text-slate-400">{row.module_name}</span>
    </div>
  );

  const durationTemplate = (row) => (
    <div className="flex flex-col gap-1">
      <Tag
        value={labelFor(durations, row.duration_minutes)}
        severity={row.is_configured ? 'info' : 'warning'}
        className="w-fit text-xs"
      />
      {!row.is_configured && (
        <span className="text-[11px] text-amber-600">Sin configurar (valor por defecto)</span>
      )}
    </div>
  );

  const statusTemplate = (row) => {
    if (!row.is_configured) {
      return <span className="text-xs text-slate-400">—</span>;
    }

    return (
      <div className="flex flex-col items-start gap-0.5">
        <InputSwitch
          checked={row.is_active}
          onChange={() => handleToggleStatus(row)}
          style={{ cursor: 'pointer' }}
        />
        <span className={`text-xs font-medium transition-colors duration-200 ${row.is_active ? 'text-emerald-600' : 'text-slate-500'}`}>
          {row.is_active ? 'En caché' : 'En vivo'}
        </span>
      </div>
    );
  };

  const updatedTemplate = (row) => (
    row.updated_at ? (
      <div className="flex flex-col">
        <span className="text-xs text-slate-600">
          {new Date(row.updated_at).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' })}
        </span>
        <span className="text-[11px] text-slate-400">{row.updated_by_user?.name || '—'}</span>
      </div>
    ) : <span className="text-xs text-slate-400">—</span>
  );

  const actionsTemplate = (row) => (
    <div className="flex gap-1">
      <Button
        icon="pi pi-pencil"
        severity="info"
        text
        rounded
        tooltip={row.is_configured ? 'Editar tiempo de refresco' : 'Configurar este módulo'}
        tooltipOptions={{ position: 'top' }}
        onClick={() => openForm(row)}
        className="cursor-pointer"
      />
      {row.is_configured && (
        <>
          <Button
            icon="pi pi-refresh"
            severity="warning"
            text
            rounded
            loading={flushingId === row.id}
            tooltip="Purgar ahora"
            tooltipOptions={{ position: 'top' }}
            onClick={() => handleFlush(row)}
            className="cursor-pointer"
          />
          <Button
            icon="pi pi-trash"
            severity="danger"
            text
            rounded
            tooltip="Eliminar política"
            tooltipOptions={{ position: 'top' }}
            onClick={() => setDeleteTarget(row)}
            className="cursor-pointer"
          />
        </>
      )}
    </div>
  );

  const fieldError = (name) => fieldErrors[name] && (
    <p className="mt-1 text-xs text-red-500">{fieldErrors[name]}</p>
  );

  return (
    <div className="p-6">
      <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold text-slate-900">Caché de Módulos (Redis)</h2>
          <p className="mt-1 max-w-2xl text-sm text-slate-500">
            Define cuánto tiempo puede servirse cada consulta pesada desde Redis antes de volver a
            calcularse contra PostgreSQL. Una ventana más larga alivia la base de datos; una más
            corta acerca las cifras al momento. Los datos críticos se invalidan solos: registrar una
            venta o mover inventario purga de inmediato el caché del Dashboard.
          </p>
        </div>
        <Button
          label="Nueva Política"
          icon="pi pi-plus"
          onClick={() => openForm()}
          disabled={availableModules.length === 0}
          tooltip={availableModules.length === 0 ? 'Todos los módulos ya tienen política' : undefined}
          tooltipOptions={{ position: 'left' }}
          className="cursor-pointer rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
          pt={{ root: { className: 'border-0' } }}
        />
      </div>

      <DataTable
        value={configurations}
        loading={loading}
        dataKey="module_name"
        emptyMessage="No hay módulos cacheables registrados."
        className="text-sm"
        stripedRows
      >
        <Column header="Módulo" body={moduleTemplate} style={{ minWidth: '260px' }} />
        <Column header="Tiempo de Refresco" body={durationTemplate} style={{ minWidth: '200px' }} />
        <Column header="Estatus" body={statusTemplate} style={{ width: '120px' }} />
        <Column header="Última Modificación" body={updatedTemplate} style={{ minWidth: '180px' }} />
        <Column header="Acciones" body={actionsTemplate} style={{ width: '150px' }} />
      </DataTable>

      <Dialog
        visible={showForm}
        onHide={() => !saving && setShowForm(false)}
        header={editing ? 'Editar Política de Caché' : 'Nueva Política de Caché'}
        style={{ width: '520px' }}
        modal
        draggable={false}
        closable={!saving}
      >
        <form onSubmit={handleSave} className="space-y-4">
          <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">Módulo *</label>
            <Dropdown
              value={formData.module_name}
              options={availableModules}
              // The module is the row's identity: changing it on an existing
              // policy would orphan the payloads already stored under it.
              disabled={Boolean(editing)}
              onChange={(e) => setFormData({ ...formData, module_name: e.value })}
              placeholder="Selecciona el módulo a cachear"
              className="w-full text-sm"
              pt={{ root: { className: 'w-full' } }}
            />
            {editing && (
              <p className="mt-1 text-xs text-slate-400">
                El módulo no se puede cambiar. Elimina la política y crea otra si necesitas moverla.
              </p>
            )}
            {fieldError('module_name')}
          </div>

          <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">Tiempo de Refresco *</label>
            <Dropdown
              value={formData.duration_minutes}
              options={durations}
              onChange={(e) => setFormData({ ...formData, duration_minutes: e.value })}
              className="w-full text-sm"
              pt={{ root: { className: 'w-full' } }}
            />
            <p className="mt-1 text-xs text-slate-400">
              Máximo que puede pasar sin recalcular. Los cambios críticos igual purgan antes.
            </p>
            {fieldError('duration_minutes')}
          </div>

          <div className="flex items-center gap-3 rounded-lg bg-slate-50 px-4 py-3">
            <InputSwitch
              checked={formData.is_active}
              onChange={(e) => setFormData({ ...formData, is_active: e.value })}
              style={{ cursor: 'pointer' }}
            />
            <div>
              <p className="text-sm font-medium text-slate-700">
                {formData.is_active ? 'Caché activo' : 'Caché desactivado'}
              </p>
              <p className="text-xs text-slate-400">
                Desactivado, el módulo consulta PostgreSQL en cada petición sin perder la política guardada.
              </p>
            </div>
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <Button
              type="button"
              label="Cancelar"
              severity="secondary"
              text
              onClick={() => setShowForm(false)}
              disabled={saving}
              className="cursor-pointer"
            />
            <Button
              type="submit"
              label={saving ? 'Guardando...' : 'Guardar'}
              loading={saving}
              disabled={saving || !formData.module_name}
              className="cursor-pointer rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500"
              pt={{ root: { className: 'border-0' } }}
            />
          </div>
        </form>
      </Dialog>

      <Dialog
        visible={deleteTarget !== null}
        onHide={() => !deleting && setDeleteTarget(null)}
        header="Eliminar Política de Caché"
        style={{ width: '420px' }}
        modal
        draggable={false}
        closable={!deleting}
      >
        <p className="text-sm text-slate-600">
          El módulo <strong>{deleteTarget ? labelFor(modules, deleteTarget.module_name) : ''}</strong> volverá
          al tiempo de refresco por defecto y su caché actual se purgará.
        </p>
        <div className="mt-5 flex justify-end gap-2">
          <Button
            label="Cancelar"
            severity="secondary"
            text
            onClick={() => setDeleteTarget(null)}
            disabled={deleting}
            className="cursor-pointer"
          />
          <Button
            label={deleting ? 'Eliminando...' : 'Eliminar'}
            severity="danger"
            loading={deleting}
            disabled={deleting}
            onClick={handleDelete}
            className="cursor-pointer"
          />
        </div>
      </Dialog>
    </div>
  );
}
