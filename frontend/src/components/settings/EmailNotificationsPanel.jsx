import { useState, useEffect, useCallback } from 'react';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { InputText } from 'primereact/inputtext';
import { Password } from 'primereact/password';
import { Dropdown } from 'primereact/dropdown';
import { Chips } from 'primereact/chips';
import { InputSwitch } from 'primereact/inputswitch';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { Tag } from 'primereact/tag';
import { toast } from 'sonner';
import api from '../../api/axios';

const emptyForm = {
  process_type: 'jobs',
  provider: 'sendgrid',
  api_key: '',
  from_email: '',
  from_name: '',
  target_emails: [],
  subject: '',
  is_active: true,
};

/**
 * Per-process outbound email configuration.
 *
 * Each row binds a business process ("jobs", "users", ...) to a provider
 * credential, a sender identity, a subject and a list of recipients. The
 * backend rebuilds the transport from the saved row every time it sends, so
 * changes here take effect on the next message without a redeploy.
 *
 * The stored API key is never returned by the API: the form shows only its
 * last four characters and submits a new value exclusively when the user
 * actually types one, which is why the key field is left blank while editing.
 */
export default function EmailNotificationsPanel() {
  const [configurations, setConfigurations] = useState([]);
  const [processTypes, setProcessTypes] = useState([]);
  const [providers, setProviders] = useState([]);
  const [loading, setLoading] = useState(true);

  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState(null);
  const [formData, setFormData] = useState(emptyForm);
  const [fieldErrors, setFieldErrors] = useState({});
  const [saving, setSaving] = useState(false);

  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleting, setDeleting] = useState(false);

  const fetchConfigurations = useCallback(async () => {
    setLoading(true);
    try {
      const [list, catalogs] = await Promise.all([
        api.get('/admin/email-configurations'),
        api.get('/admin/email-configurations/catalogs'),
      ]);
      setConfigurations(list.data.data);
      setProcessTypes(catalogs.data.data.process_types);
      setProviders(catalogs.data.data.providers);
    } catch {
      toast.error('Error al cargar las configuraciones de correo.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchConfigurations(); }, [fetchConfigurations]);

  const openCreate = () => {
    setEditing(null);
    setFormData(emptyForm);
    setFieldErrors({});
    setShowForm(true);
  };

  const openEdit = (row) => {
    setEditing(row);
    setFormData({
      process_type: row.process_type,
      provider: row.provider,
      api_key: '',
      from_email: row.from_email,
      from_name: row.from_name,
      target_emails: row.target_emails || [],
      subject: row.subject,
      is_active: row.is_active,
    });
    setFieldErrors({});
    setShowForm(true);
  };

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    setFieldErrors({});

    try {
      if (editing) {
        // api_key travels only when the administrator typed a new one; an
        // empty string tells the backend to keep the stored credential.
        await api.put(`/admin/email-configurations/${editing.id}`, formData);
        toast.success('Configuración de correo actualizada.');
      } else {
        await api.post('/admin/email-configurations', formData);
        toast.success('Configuración de correo creada.');
      }
      setShowForm(false);
      fetchConfigurations();
    } catch (err) {
      const errors = err.response?.data?.errors;
      if (errors) {
        setFieldErrors(Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v[0]])));
        toast.warning('Verifica los campos marcados.');
      } else {
        toast.error(err.response?.data?.message || 'Error al guardar la configuración.');
      }
    } finally {
      setSaving(false);
    }
  };

  /**
   * Optimistic status toggle: the switch flips immediately and rolls back to
   * its previous value if the request fails, so the table never shows a state
   * the server did not accept.
   */
  const handleToggleStatus = async (row) => {
    const previous = row.is_active;
    setConfigurations(rows => rows.map(r => (r.id === row.id ? { ...r, is_active: !previous } : r)));

    try {
      await api.patch(`/admin/email-configurations/${row.id}/toggle-status`);
      toast.success(previous ? 'Notificaciones desactivadas.' : 'Notificaciones activadas.');
    } catch {
      setConfigurations(rows => rows.map(r => (r.id === row.id ? { ...r, is_active: previous } : r)));
      toast.error('No se pudo cambiar el estado de la configuración.');
    }
  };

  const handleDelete = async () => {
    setDeleting(true);
    try {
      await api.delete(`/admin/email-configurations/${deleteTarget.id}`);
      toast.success('Configuración eliminada.');
      setDeleteTarget(null);
      fetchConfigurations();
    } catch (err) {
      toast.error(err.response?.data?.message || 'Error al eliminar la configuración.');
    } finally {
      setDeleting(false);
    }
  };

  const labelFor = (options, value) => options.find(o => o.value === value)?.label || value;

  const processTemplate = (row) => (
    <div className="flex flex-col">
      <span className="text-sm font-semibold text-slate-900">{labelFor(processTypes, row.process_type)}</span>
      <span className="font-mono text-[11px] text-slate-400">{row.process_type}</span>
    </div>
  );

  const providerTemplate = (row) => (
    <div className="flex flex-col gap-1">
      <Tag value={labelFor(providers, row.provider)} severity="info" className="w-fit text-xs" />
      <span className="font-mono text-[11px] text-slate-400">
        {row.has_api_key ? `API Key ${row.api_key_preview}` : 'Sin credencial'}
      </span>
    </div>
  );

  const senderTemplate = (row) => (
    <div className="flex flex-col">
      <span className="text-sm text-slate-700">{row.from_name}</span>
      <span className="text-xs text-slate-400">{row.from_email}</span>
    </div>
  );

  const recipientsTemplate = (row) => (
    <div className="flex flex-wrap gap-1">
      {(row.target_emails || []).map(email => (
        <span key={email} className="rounded bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">{email}</span>
      ))}
      {(row.target_emails || []).length === 0 && (
        <span className="text-xs text-amber-600">Sin destinatarios</span>
      )}
    </div>
  );

  const statusTemplate = (row) => (
    <div className="flex flex-col items-start gap-0.5">
      <InputSwitch
        checked={row.is_active}
        onChange={() => handleToggleStatus(row)}
        style={{ cursor: 'pointer' }}
      />
      <span className={`text-xs font-medium transition-colors duration-200 ${row.is_active ? 'text-emerald-600' : 'text-slate-500'}`}>
        {row.is_active ? 'Activo' : 'Inactivo'}
      </span>
    </div>
  );

  const actionsTemplate = (row) => (
    <div className="flex gap-1">
      <Button
        icon="pi pi-pencil"
        severity="info"
        text
        rounded
        tooltip="Editar configuración"
        tooltipOptions={{ position: 'top' }}
        onClick={() => openEdit(row)}
        className="cursor-pointer"
      />
      <Button
        icon="pi pi-trash"
        severity="danger"
        text
        rounded
        tooltip="Eliminar configuración"
        tooltipOptions={{ position: 'top' }}
        onClick={() => setDeleteTarget(row)}
        className="cursor-pointer"
      />
    </div>
  );

  const fieldError = (name) => fieldErrors[name] && (
    <p className="mt-1 text-xs text-red-500">{fieldErrors[name]}</p>
  );

  return (
    <div className="p-6">
      <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold text-slate-900">Notificaciones por Correo</h2>
          <p className="mt-1 max-w-2xl text-sm text-slate-500">
            Da de alta las credenciales del proveedor (SendGrid) y define el asunto y los correos
            destino de cada tipo de proceso. El sistema construye el envío con la configuración
            activa al momento de enviar; si un proceso no tiene configuración activa, simplemente
            no notifica.
          </p>
        </div>
        <Button
          label="Nueva Configuración"
          icon="pi pi-plus"
          onClick={openCreate}
          className="cursor-pointer rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
          pt={{ root: { className: 'border-0' } }}
        />
      </div>

      <DataTable
        value={configurations}
        loading={loading}
        dataKey="id"
        emptyMessage="Aún no hay configuraciones de correo. Crea la del proceso 'jobs' para recibir los cierres automáticos de caja."
        className="text-sm"
        stripedRows
      >
        <Column header="Tipo de Proceso" body={processTemplate} style={{ minWidth: '200px' }} />
        <Column header="Proveedor" body={providerTemplate} style={{ minWidth: '160px' }} />
        <Column header="Remitente" body={senderTemplate} style={{ minWidth: '200px' }} />
        <Column header="Asunto" field="subject" style={{ minWidth: '220px' }} />
        <Column header="Correos Destino" body={recipientsTemplate} style={{ minWidth: '240px' }} />
        <Column header="Estatus" body={statusTemplate} style={{ width: '110px' }} />
        <Column header="Acciones" body={actionsTemplate} style={{ width: '120px' }} />
      </DataTable>

      <Dialog
        visible={showForm}
        onHide={() => !saving && setShowForm(false)}
        header={editing ? 'Editar Configuración de Correo' : 'Nueva Configuración de Correo'}
        style={{ width: '560px' }}
        modal
        draggable={false}
        closable={!saving}
      >
        <form onSubmit={handleSave} className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Tipo de Proceso *</label>
              <Dropdown
                value={formData.process_type}
                options={processTypes}
                onChange={(e) => setFormData({ ...formData, process_type: e.value })}
                className="w-full text-sm"
                pt={{ root: { className: 'w-full' } }}
              />
              {fieldError('process_type')}
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Proveedor *</label>
              <Dropdown
                value={formData.provider}
                options={providers}
                onChange={(e) => setFormData({ ...formData, provider: e.value })}
                className="w-full text-sm"
                pt={{ root: { className: 'w-full' } }}
              />
              {fieldError('provider')}
            </div>
          </div>

          <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">
              API Key {editing ? '(opcional)' : '*'}
            </label>
            <Password
              value={formData.api_key}
              onChange={(e) => setFormData({ ...formData, api_key: e.target.value })}
              feedback={false}
              toggleMask
              placeholder={editing ? `Sin cambios (${editing.api_key_preview || 'sin credencial'})` : 'SG.xxxxxxxxxxxxxxxx'}
              className="w-full"
              inputClassName="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm font-mono"
              pt={{ root: { className: 'w-full' } }}
            />
            <p className="mt-1 text-xs text-slate-400">
              {editing
                ? 'Déjalo vacío para conservar la credencial guardada. Escribe una nueva solo si vas a rotarla.'
                : 'Se guarda cifrada y nunca vuelve a mostrarse completa.'}
            </p>
            {fieldError('api_key')}
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Correo Remitente *</label>
              <InputText
                value={formData.from_email}
                onChange={(e) => setFormData({ ...formData, from_email: e.target.value })}
                placeholder="no-reply@tunegocio.com"
                className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                pt={{ root: { className: 'w-full' } }}
              />
              <p className="mt-1 text-xs text-slate-400">Debe estar verificado en el proveedor.</p>
              {fieldError('from_email')}
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Nombre Remitente *</label>
              <InputText
                value={formData.from_name}
                onChange={(e) => setFormData({ ...formData, from_name: e.target.value })}
                placeholder="Cronos POS"
                className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                pt={{ root: { className: 'w-full' } }}
              />
              {fieldError('from_name')}
            </div>
          </div>

          <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">Asunto *</label>
            <InputText
              value={formData.subject}
              onChange={(e) => setFormData({ ...formData, subject: e.target.value })}
              placeholder="Cierre de Caja Automático — Cronos POS"
              className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
              pt={{ root: { className: 'w-full' } }}
            />
            {fieldError('subject')}
          </div>

          <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">Correos Destino *</label>
            <Chips
              value={formData.target_emails}
              onChange={(e) => setFormData({ ...formData, target_emails: e.value || [] })}
              separator=","
              placeholder="Escribe un email y presiona Enter"
              className="w-full"
              pt={{ container: { className: 'w-full rounded-lg border-slate-200 p-2 min-h-[80px]' } }}
            />
            <p className="mt-1 text-xs text-slate-400">
              Presiona <kbd className="rounded bg-slate-100 px-1 py-0.5 text-[10px]">Enter</kbd> o{' '}
              <kbd className="rounded bg-slate-100 px-1 py-0.5 text-[10px]">,</kbd> para agregar cada correo. Máx 20 destinatarios.
            </p>
            {fieldError('target_emails')}
          </div>

          <div className="flex items-center gap-3 rounded-lg bg-slate-50 px-4 py-3">
            <InputSwitch
              checked={formData.is_active}
              onChange={(e) => setFormData({ ...formData, is_active: e.value })}
              style={{ cursor: 'pointer' }}
            />
            <div>
              <p className="text-sm font-medium text-slate-700">
                {formData.is_active ? 'Configuración activa' : 'Configuración inactiva'}
              </p>
              <p className="text-xs text-slate-400">
                Inactiva, el proceso deja de notificar sin perder las credenciales guardadas.
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
              disabled={saving}
              className="cursor-pointer rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500"
              pt={{ root: { className: 'border-0' } }}
            />
          </div>
        </form>
      </Dialog>

      <Dialog
        visible={deleteTarget !== null}
        onHide={() => !deleting && setDeleteTarget(null)}
        header="Eliminar Configuración"
        style={{ width: '420px' }}
        modal
        draggable={false}
        closable={!deleting}
      >
        <p className="text-sm text-slate-600">
          El proceso <strong>{deleteTarget ? labelFor(processTypes, deleteTarget.process_type) : ''}</strong> dejará
          de enviar correos y la credencial guardada se eliminará de forma permanente.
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
