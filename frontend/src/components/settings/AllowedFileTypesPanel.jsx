import { useCallback, useEffect, useState } from 'react';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { InputText } from 'primereact/inputtext';
import { InputNumber } from 'primereact/inputnumber';
import { Dropdown } from 'primereact/dropdown';
import { InputSwitch } from 'primereact/inputswitch';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { Tag } from 'primereact/tag';
import { toast } from 'sonner';
import mediaApi from '../../api/media';
import { dialogClass, DIALOG_PT, HIDE_BELOW } from '../../lib/responsive';

const emptyForm = {
  extension: '',
  mime_type: '',
  label: '',
  max_size_kb: 2048,
  category: 'document',
  is_active: true,
};

/**
 * Administration of the dynamic upload whitelist.
 *
 * This table IS the upload policy of the system. There is no hard-coded list of
 * extensions anywhere in the backend: every upload resolves the row matching
 * its extension, and an absent or inactive row rejects the file before a single
 * byte reaches Google Drive.
 *
 * The consequence worth stating in the UI — and stated in the banner below —
 * is that a change here is live on the very next upload. No deploy, no cache to
 * clear. That is what makes the kill switch a usable incident-response control
 * rather than a setting somebody adjusts and hopes about.
 */
export default function AllowedFileTypesPanel() {
  const [types, setTypes] = useState([]);
  const [categories, setCategories] = useState([]);
  const [platformMaxKb, setPlatformMaxKb] = useState(25600);
  const [loading, setLoading] = useState(true);

  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [fieldErrors, setFieldErrors] = useState({});
  const [saving, setSaving] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState(null);

  const fetchTypes = useCallback(async () => {
    setLoading(true);
    try {
      const [list, catalogs] = await Promise.all([
        mediaApi.fileTypes(),
        mediaApi.fileTypeCatalogs(),
      ]);
      setTypes(list.data);
      setCategories(catalogs.categories);
      setPlatformMaxKb(catalogs.platform_max_kb);
    } catch {
      toast.error('Error al cargar el catálogo de tipos permitidos.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchTypes(); }, [fetchTypes]);

  const openForm = (row = null) => {
    setEditing(row);
    setForm(row
      ? {
          extension: row.extension,
          mime_type: row.mime_type,
          label: row.label,
          max_size_kb: row.max_size_kb,
          category: row.category,
          is_active: row.is_active,
        }
      : emptyForm);
    setFieldErrors({});
    setShowForm(true);
  };

  const handleSave = async (event) => {
    event.preventDefault();
    setSaving(true);
    setFieldErrors({});

    try {
      const result = editing
        ? await mediaApi.updateFileType(editing.id, form)
        : await mediaApi.createFileType(form);

      toast.success(result.metadata?.message ?? 'Tipo de archivo guardado.');
      setShowForm(false);
      fetchTypes();
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

  /**
   * Optimistic kill switch: the toggle flips at once and rolls back if the
   * server refuses, so the table never shows a policy the backend is not
   * actually applying.
   */
  const handleToggle = async (row) => {
    const previous = row.is_active;
    setTypes((current) =>
      current.map((t) => (t.id === row.id ? { ...t, is_active: !previous } : t)));

    try {
      const result = await mediaApi.toggleFileType(row.id);
      toast.success(result.metadata?.message);
      fetchTypes();
    } catch (err) {
      setTypes((current) =>
        current.map((t) => (t.id === row.id ? { ...t, is_active: previous } : t)));
      toast.error(err.response?.data?.message || 'No se pudo cambiar el estatus.');
    }
  };

  const handleDelete = async () => {
    try {
      const result = await mediaApi.removeFileType(deleteTarget.id);
      toast.success(result.metadata?.message);
      setDeleteTarget(null);
      fetchTypes();
    } catch (err) {
      // The backend refuses to delete a system row or one with files stored,
      // and explains why. That sentence is the useful part — show it whole.
      toast.error('No se puede eliminar', {
        description: err.response?.data?.message,
        duration: 7000,
      });
      setDeleteTarget(null);
    }
  };

  const extensionTemplate = (row) => (
    <div className="flex items-center gap-2">
      <span className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs font-semibold text-slate-700">
        .{row.extension}
      </span>
      {row.is_system && <Tag value="Sistema" severity="info" className="text-[10px]" />}
    </div>
  );

  const statusTemplate = (row) => (
    <div className="flex items-center gap-2">
      <InputSwitch checked={row.is_active} onChange={() => handleToggle(row)} />
      <span className={`text-xs ${row.is_active ? 'text-emerald-700' : 'text-slate-400'}`}>
        {row.is_active ? 'Permitido' : 'Bloqueado'}
      </span>
    </div>
  );

  const actionsTemplate = (row) => (
    <div className="flex justify-end gap-1">
      <Button icon="pi pi-pencil" size="small" text onClick={() => openForm(row)} tooltip="Editar" />
      <Button
        icon="pi pi-trash"
        size="small"
        text
        severity="danger"
        disabled={row.is_system}
        onClick={() => setDeleteTarget(row)}
        tooltip={row.is_system ? 'Los tipos del sistema solo pueden desactivarse' : 'Eliminar'}
      />
    </div>
  );

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h3 className="text-base font-semibold text-slate-900">Tipos de Archivo Permitidos</h3>
          <p className="text-sm text-slate-500">
            Esta tabla define qué puede subirse a la biblioteca de medios.
          </p>
        </div>
        <Button label="Nuevo tipo" icon="pi pi-plus" onClick={() => openForm()} className="w-full sm:w-auto" />
      </div>

      <div className="rounded-lg border border-amber-200 bg-amber-50 p-3">
        <p className="text-xs leading-relaxed text-amber-900">
          <strong>Los cambios son inmediatos.</strong> El validador consulta esta tabla en cada subida,
          sin caché intermedia: al bloquear una extensión, la siguiente subida de ese tipo se rechaza
          al instante. Toda alta, edición y cambio de estatus queda registrado en la auditoría de medios.
        </p>
      </div>

      <DataTable
        value={types}
        loading={loading}
        size="small"
        className="text-sm"
        emptyMessage="No hay tipos de archivo registrados."
      >
        <Column header="Extensión" body={extensionTemplate} />
        <Column field="label" header="Descripción" className={HIDE_BELOW.sm} />
        <Column field="mime_type" header="MIME type" className={HIDE_BELOW.md}
          body={(r) => <span className="font-mono text-[11px] text-slate-600">{r.mime_type}</span>} />
        <Column header="Categoría" className={HIDE_BELOW.sm}
          body={(r) => categories.find((c) => c.value === r.category)?.label ?? r.category} />
        <Column header="Límite"
          body={(r) => (
            <span className="text-xs">
              {r.effective_max_size_kb >= 1024
                ? `${(r.effective_max_size_kb / 1024).toFixed(1)} MB`
                : `${r.effective_max_size_kb} KB`}
            </span>
          )} />
        <Column header="Estatus" body={statusTemplate} />
        <Column header="" body={actionsTemplate} />
      </DataTable>

      <Dialog
        header={editing ? `Editar .${editing.extension}` : 'Nuevo tipo de archivo permitido'}
        visible={showForm}
        onHide={() => setShowForm(false)}
        className={dialogClass('md')}
        pt={DIALOG_PT}
        draggable={false}
      >
        <form onSubmit={handleSave} className="space-y-3">
          <div>
            <label className="mb-1 block text-xs font-semibold text-slate-600">Extensión</label>
            <InputText
              value={form.extension}
              onChange={(e) => setForm({ ...form, extension: e.target.value })}
              placeholder="pdf"
              className="w-full"
              disabled={editing?.is_system}
            />
            <p className="mt-1 text-[11px] text-slate-400">Sin punto, solo letras y números.</p>
            {fieldErrors.extension && <p className="mt-1 text-xs text-rose-600">{fieldErrors.extension}</p>}
          </div>

          <div>
            <label className="mb-1 block text-xs font-semibold text-slate-600">MIME type</label>
            <InputText
              value={form.mime_type}
              onChange={(e) => setForm({ ...form, mime_type: e.target.value })}
              placeholder="application/pdf"
              className="w-full font-mono text-sm"
            />
            <p className="mt-1 text-[11px] text-slate-400">
              El servidor compara este valor contra el contenido real del archivo, no contra su nombre:
              así detecta un ejecutable renombrado.
            </p>
            {fieldErrors.mime_type && <p className="mt-1 text-xs text-rose-600">{fieldErrors.mime_type}</p>}
          </div>

          <div>
            <label className="mb-1 block text-xs font-semibold text-slate-600">Descripción</label>
            <InputText
              value={form.label}
              onChange={(e) => setForm({ ...form, label: e.target.value })}
              placeholder="Documento PDF"
              className="w-full"
            />
            {fieldErrors.label && <p className="mt-1 text-xs text-rose-600">{fieldErrors.label}</p>}
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <label className="mb-1 block text-xs font-semibold text-slate-600">Tamaño máximo (KB)</label>
              <InputNumber
                value={form.max_size_kb}
                onValueChange={(e) => setForm({ ...form, max_size_kb: e.value })}
                min={1}
                max={platformMaxKb}
                className="w-full"
                inputClassName="w-full"
              />
              <p className="mt-1 text-[11px] text-slate-400">
                Techo de la plataforma: {platformMaxKb} KB.
              </p>
              {fieldErrors.max_size_kb && <p className="mt-1 text-xs text-rose-600">{fieldErrors.max_size_kb}</p>}
            </div>
            <div>
              <label className="mb-1 block text-xs font-semibold text-slate-600">Categoría</label>
              <Dropdown
                value={form.category}
                options={categories}
                optionLabel="label"
                optionValue="value"
                onChange={(e) => setForm({ ...form, category: e.value })}
                className="w-full"
              />
              <p className="mt-1 text-[11px] text-slate-400">Define el ícono y el filtro en la biblioteca.</p>
            </div>
          </div>

          <div className="flex items-center gap-3">
            <InputSwitch checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.value })} />
            <span className="text-sm text-slate-700">Permitir subidas de este tipo</span>
          </div>

          <div className="flex flex-col-reverse gap-2 border-t border-slate-100 pt-4 sm:flex-row sm:justify-end">
            <Button type="button" label="Cancelar" outlined onClick={() => setShowForm(false)} className="w-full sm:w-auto" />
            <Button type="submit" label="Guardar" icon="pi pi-check" loading={saving} className="w-full sm:w-auto" />
          </div>
        </form>
      </Dialog>

      <Dialog
        header="Eliminar tipo de archivo"
        visible={deleteTarget !== null}
        onHide={() => setDeleteTarget(null)}
        className={dialogClass('sm')}
        pt={DIALOG_PT}
        draggable={false}
      >
        <p className="text-sm text-slate-700">
          ¿Eliminar la política de <strong>.{deleteTarget?.extension}</strong> del catálogo?
        </p>
        <p className="mt-2 text-xs text-slate-500">
          Si solo quieres impedir nuevas subidas, desactívala: así conservas la trazabilidad de los
          archivos ya almacenados con esa extensión.
        </p>
        <div className="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button label="Cancelar" outlined onClick={() => setDeleteTarget(null)} className="w-full sm:w-auto" />
          <Button label="Eliminar" severity="danger" icon="pi pi-trash" onClick={handleDelete} className="w-full sm:w-auto" />
        </div>
      </Dialog>
    </div>
  );
}
