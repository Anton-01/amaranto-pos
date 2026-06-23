import { useState, useEffect, useCallback } from 'react';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { InputText } from 'primereact/inputtext';
import { Dropdown } from 'primereact/dropdown';
import { Tag } from 'primereact/tag';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { TabView, TabPanel } from 'primereact/tabview';
import { Tooltip } from 'primereact/tooltip';
import { toast } from 'sonner';
import api from '../../api/axios';
import AppLayout from '../../components/layout/AppLayout';
import { useAuth } from '../../context/AuthContext';

const statusOptions = [
  { label: 'Todos', value: '' },
  { label: 'Activo', value: 'active' },
  { label: 'Suspendido', value: 'suspended' },
];

const roleFilterOptions = [
  { label: 'Todos', value: '' },
  { label: 'Admin', value: 'admin' },
  { label: 'Manager', value: 'manager' },
  { label: 'Vendor', value: 'vendor' },
];

const sessionFilterOptions = [
  { label: 'Todos', value: '' },
  { label: 'Con sesion activa', value: 'true' },
  { label: 'Sin sesion activa', value: 'false' },
];

const roleOptions = [
  { label: 'Admin', value: 'admin' },
  { label: 'Manager', value: 'manager' },
  { label: 'Vendor', value: 'vendor' },
];

const emptyForm = { name: '', email: '', password: '', role: null };

function formatDate(d) {
  if (!d) return '—';
  return new Date(d).toLocaleString('es-MX', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
  });
}

function parseUA(ua) {
  if (!ua) return 'Desconocido';
  if (ua.includes('Chrome')) return 'Chrome';
  if (ua.includes('Firefox')) return 'Firefox';
  if (ua.includes('Safari')) return 'Safari';
  if (ua.includes('Edge')) return 'Edge';
  return ua.substring(0, 30) + '...';
}

export default function UsersPage() {
  const { user: authUser } = useAuth();

  const [users, setUsers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [globalFilter, setGlobalFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [roleFilter, setRoleFilter] = useState('');
  const [sessionFilter, setSessionFilter] = useState('');

  const [showCreate, setShowCreate] = useState(false);
  const [formData, setFormData] = useState({ ...emptyForm });
  const [saving, setSaving] = useState(false);

  const [showDelete, setShowDelete] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleteReason, setDeleteReason] = useState('');
  const [fieldErrors, setFieldErrors] = useState({});

  const [showSuspend, setShowSuspend] = useState(false);
  const [suspendTarget, setSuspendTarget] = useState(null);
  const [showReset, setShowReset] = useState(false);
  const [resetTarget, setResetTarget] = useState(null);

  const [detailUser, setDetailUser] = useState(null);
  const [detailData, setDetailData] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);

  const fetchUsers = useCallback(async () => {
    setLoading(true);
    try {
      const params = {};
      if (statusFilter) params.status = statusFilter;
      if (roleFilter) params.role = roleFilter;
      if (sessionFilter) params.has_active_session = sessionFilter;
      const res = await api.get('/admin/users', { params });
      setUsers(res.data.data);
    } catch {
      toast.error('Error al cargar usuarios.');
    } finally {
      setLoading(false);
    }
  }, [statusFilter, roleFilter, sessionFilter]);

  useEffect(() => { fetchUsers(); }, [fetchUsers]);

  const fetchDetail = async (userId) => {
    setDetailLoading(true);
    try {
      const res = await api.get(`/admin/users/${userId}`);
      setDetailData(res.data.data);
    } catch {
      toast.error('Error al cargar detalle del usuario.');
    } finally {
      setDetailLoading(false);
    }
  };

  const openDetail = (user) => {
    setDetailUser(user);
    fetchDetail(user.id);
  };

  // --- Actions ---
  const handleToggleStatus = async (user) => {
    try {
      const res = await api.post(`/admin/users/${user.id}/toggle-status`);
      toast.success(res.data.metadata.message);
      setShowSuspend(false);
      setSuspendTarget(null);
      fetchUsers();
      if (detailUser?.id === user.id) fetchDetail(user.id);
    } catch (err) {
      toast.error(err.response?.data?.message || 'Error al cambiar estatus.');
    }
  };

  const handleDelete = async () => {
    if (!deleteReason.trim()) { toast.error('El motivo es obligatorio.'); return; }
    try {
      await api.delete(`/admin/users/${deleteTarget.id}`, { data: { deletion_reason: deleteReason } });
      toast.success('Usuario eliminado logicamente.');
      setShowDelete(false);
      setDeleteTarget(null);
      setDeleteReason('');
      fetchUsers();
      if (detailUser?.id === deleteTarget.id) { setDetailUser(null); setDetailData(null); }
    } catch (err) {
      toast.error(err.response?.data?.message || 'Error al eliminar usuario.');
    }
  };

  const handleSendReset = async (user) => {
    try {
      const res = await api.post(`/admin/users/${user.id}/send-password-reset`);
      toast.success(res.data.metadata.message);
      setShowReset(false);
      setResetTarget(null);
    } catch (err) {
      toast.error(err.response?.data?.message || 'Error al enviar enlace de restauracion.');
    }
  };

  const handleRevokeSession = async (sessionId) => {
    if (!detailUser) return;
    try {
      const res = await api.post(`/admin/users/${detailUser.id}/sessions/${sessionId}/revoke`);
      toast.success(res.data.metadata.message);
      fetchDetail(detailUser.id);
      fetchUsers();
    } catch {
      toast.error('Error al revocar sesion.');
    }
  };

  const handleCreateUser = async (e) => {
    e.preventDefault();
    setSaving(true);
    setFieldErrors({});
    try {
      await api.post('/admin/users', formData);
      toast.success('Usuario creado exitosamente.');
      setShowCreate(false);
      setFormData({ ...emptyForm });
      fetchUsers();
    } catch (err) {
      const errors = err.response?.data?.errors;
      if (errors) {
        setFieldErrors(Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v[0]])));
        toast.warning('Verifica los campos marcados.');
      } else {
        toast.error(err.response?.data?.message || 'Error al crear usuario.');
      }
    } finally {
      setSaving(false);
    }
  };

  const set = (field, value) => setFormData(prev => ({ ...prev, [field]: value }));

  // --- Templates ---
  const nameTemplate = (row) => (
    <button onClick={() => openDetail(row)} className="text-left hover:text-indigo-600 transition-colors">
      <p className="text-sm font-medium text-slate-900">{row.name}</p>
      <p className="text-xs text-slate-500">{row.email}</p>
    </button>
  );

  const roleTemplate = (row) => {
    const roleName = row.roles?.[0]?.name;
    const colors = { admin: 'danger', manager: 'warning', vendor: 'info' };
    return roleName ? <Tag value={roleName} severity={colors[roleName] || 'info'} className="text-xs capitalize" /> : '—';
  };

  const statusTemplate = (row) => (
    <Tag
      value={row.status === 'active' ? 'Activo' : 'Suspendido'}
      severity={row.status === 'active' ? 'success' : 'danger'}
      className="text-xs"
    />
  );

  const sessionTemplate = (row) => {
    const count = row.active_tokens_count || 0;
    return count > 0
      ? <span className="flex items-center gap-1 text-xs text-emerald-600"><span className="h-2 w-2 rounded-full bg-emerald-500 animate-pulse" /> En linea</span>
      : <span className="text-xs text-slate-400">Offline</span>;
  };

  const isSelf = (row) => authUser?.id === row.id;

  const actionsTemplate = (row) => {
    if (isSelf(row)) {
      return (
        <div className="flex items-center gap-1">
          <Button
            icon="pi pi-user"
            severity="secondary"
            text
            rounded
            onClick={() => openDetail(row)}
            className="cursor-pointer !h-8 !w-8"
            tooltip="Ver detalle"
            tooltipOptions={{ position: 'top' }}
          />
          <span className="text-xs text-slate-400 italic ml-1" title="No puedes realizar acciones sobre tu propio usuario">
            — Acciones bloqueadas (usuario propio)
          </span>
        </div>
      );
    }

    return (
      <div className="flex items-center gap-0.5">
        <Button
          icon="pi pi-user"
          severity="secondary"
          text
          rounded
          onClick={() => openDetail(row)}
          className="cursor-pointer !h-8 !w-8"
          tooltip="Ver detalle"
          tooltipOptions={{ position: 'top' }}
        />
        <Button
          icon={row.status === 'active' ? 'pi pi-ban' : 'pi pi-check-circle'}
          severity={row.status === 'active' ? 'warning' : 'success'}
          text
          rounded
          onClick={() => { setSuspendTarget(row); setShowSuspend(true); }}
          className="cursor-pointer !h-8 !w-8"
          tooltip={row.status === 'active' ? 'Suspender' : 'Activar'}
          tooltipOptions={{ position: 'top' }}
        />
        <Button
          icon="pi pi-key"
          severity="info"
          text
          rounded
          onClick={() => { setResetTarget(row); setShowReset(true); }}
          className="cursor-pointer !h-8 !w-8"
          tooltip="Reset contraseña"
          tooltipOptions={{ position: 'top' }}
        />
        <Button
          icon="pi pi-trash"
          severity="danger"
          text
          rounded
          onClick={() => { setDeleteTarget(row); setShowDelete(true); }}
          className="cursor-pointer !h-8 !w-8"
          tooltip="Eliminar"
          tooltipOptions={{ position: 'top' }}
        />
      </div>
    );
  };

  const dateTemplate = (row) => formatDate(row.created_at);

  return (
    <AppLayout>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Gestion de Usuarios</h1>
          <p className="text-sm text-slate-500">Administra el ciclo de vida, accesos y auditoria de cada operador.</p>
        </div>
        <Button
          label="Nuevo Usuario"
          onClick={() => { setFormData({ ...emptyForm }); setShowCreate(true); }}
          className="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
          pt={{ root: { className: 'border-0' } }}
        />
      </div>

      {/* Filters */}
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <InputText
          value={globalFilter}
          onChange={(e) => setGlobalFilter(e.target.value)}
          placeholder="Buscar por nombre o email..."
          className="w-64 rounded-lg border-slate-200 px-3 py-2 text-sm"
          pt={{ root: { className: 'w-64' } }}
        />
        <Dropdown
          value={statusFilter}
          options={statusOptions}
          onChange={(e) => setStatusFilter(e.value)}
          placeholder="Estatus"
          className="text-sm"
          pt={{ root: { className: 'w-40' } }}
        />
        <Dropdown
          value={roleFilter}
          options={roleFilterOptions}
          onChange={(e) => setRoleFilter(e.value)}
          placeholder="Rol"
          className="text-sm"
          pt={{ root: { className: 'w-40' } }}
        />
        <Dropdown
          value={sessionFilter}
          options={sessionFilterOptions}
          onChange={(e) => setSessionFilter(e.value)}
          placeholder="Sesion"
          className="text-sm"
          pt={{ root: { className: 'w-48' } }}
        />
      </div>

      {/* DataTable */}
      <div className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <DataTable
          value={users}
          loading={loading}
          globalFilter={globalFilter}
          emptyMessage="No hay usuarios."
          sortField="name"
          sortOrder={1}
          stripedRows
          paginator
          rows={15}
          pt={{ root: { className: 'text-sm' }, thead: { className: 'bg-slate-50' } }}
        >
          <Column field="name" header="Usuario" body={nameTemplate} sortable style={{ width: '25%' }} />
          <Column field="roles" header="Rol" body={roleTemplate} style={{ width: '10%' }} />
          <Column field="status" header="Estatus" body={statusTemplate} sortable style={{ width: '10%' }} />
          <Column header="Sesion" body={sessionTemplate} style={{ width: '10%' }} />
          <Column field="created_at" header="Creado" body={dateTemplate} sortable style={{ width: '18%' }} />
          <Column header="Acciones" body={actionsTemplate} style={{ width: '27%' }} />
        </DataTable>
      </div>

      {/* Detail Panel */}
      <Dialog
        visible={!!detailUser}
        onHide={() => { setDetailUser(null); setDetailData(null); }}
        modal
        header={null}
        className="w-full max-w-2xl"
        pt={{
          mask: { className: 'backdrop-blur-sm bg-black/30' },
          root: { className: 'rounded-2xl border-0 shadow-2xl' },
          content: { className: 'p-0' },
        }}
      >
        {detailLoading ? (
          <div className="flex h-64 items-center justify-center">
            <div className="h-6 w-6 animate-spin rounded-full border-3 border-indigo-600 border-t-transparent" />
          </div>
        ) : detailData ? (
          <div className="p-6">
            {/* User header */}
            <div className="mb-5 flex items-center gap-4">
              <div className="flex h-12 w-12 items-center justify-center rounded-full bg-indigo-100 text-lg font-bold text-indigo-600">
                {detailData.user.name?.charAt(0).toUpperCase()}
              </div>
              <div>
                <h3 className="text-lg font-semibold text-slate-900">{detailData.user.name}</h3>
                <p className="text-sm text-slate-500">{detailData.user.email}</p>
              </div>
              <div className="ml-auto flex items-center gap-2">
                <Tag
                  value={detailData.user.status === 'active' ? 'Activo' : 'Suspendido'}
                  severity={detailData.user.status === 'active' ? 'success' : 'danger'}
                  className="text-xs"
                />
                <Tag
                  value={detailData.user.roles?.[0]?.name || '—'}
                  severity="info"
                  className="text-xs capitalize"
                />
              </div>
            </div>

            <TabView pt={{ nav: { className: 'border-b border-slate-200' } }}>
              {/* Tab 1: Security */}
              <TabPanel header="Seguridad" pt={{ headerAction: { className: 'text-sm' } }}>
                <div className="mt-4 space-y-4">
                  <div className="rounded-lg bg-slate-50 p-4">
                    <h4 className="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Autenticacion de Dos Factores</h4>
                    <div className="flex items-center justify-between">
                      <span className="text-sm text-slate-700">Estado 2FA</span>
                      {detailData.security.two_factor_enabled ? (
                        <Tag value="HABILITADO" severity="success" className="text-xs" />
                      ) : (
                        <Tag value="DESHABILITADO" severity="warning" className="text-xs" />
                      )}
                    </div>
                    {detailData.security.two_factor_confirmed_at && (
                      <div className="mt-2 flex items-center justify-between">
                        <span className="text-sm text-slate-700">Confirmado en</span>
                        <span className="text-sm text-slate-500">{formatDate(detailData.security.two_factor_confirmed_at)}</span>
                      </div>
                    )}
                  </div>
                  <div className="rounded-lg bg-slate-50 p-4">
                    <h4 className="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Contraseña</h4>
                    <div className="flex items-center justify-between">
                      <span className="text-sm text-slate-700">Ultima restauracion</span>
                      <span className="text-sm text-slate-500">
                        {detailData.security.password_restored_at ? formatDate(detailData.security.password_restored_at) : 'Nunca restaurada'}
                      </span>
                    </div>
                    <div className="mt-2 flex items-center justify-between">
                      <span className="text-sm text-slate-700">Tokens activos</span>
                      <span className="text-sm font-semibold text-slate-900">{detailData.security.active_tokens}</span>
                    </div>
                  </div>
                </div>
              </TabPanel>

              {/* Tab 2: Sessions */}
              <TabPanel header="Sesiones" pt={{ headerAction: { className: 'text-sm' } }}>
                <div className="mt-4">
                  <h4 className="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Ultimas 3 Conexiones</h4>
                  {detailData.sessions.length === 0 ? (
                    <p className="text-sm text-slate-400">Sin registros de sesion.</p>
                  ) : (
                    <div className="space-y-3">
                      {detailData.sessions.map((session) => (
                        <div key={session.id} className="rounded-lg border border-slate-200 p-3">
                          <div className="flex items-start justify-between">
                            <div>
                              <div className="flex items-center gap-2">
                                <span className="text-sm font-medium text-slate-900">{session.ip_address || '—'}</span>
                                {!session.logout_at && (
                                  <span className="flex items-center gap-1 text-xs text-emerald-600">
                                    <span className="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse" />
                                    Activa
                                  </span>
                                )}
                              </div>
                              <p className="mt-0.5 text-xs text-slate-500">{parseUA(session.user_agent)}</p>
                              <p className="text-xs text-slate-400">
                                Login: {formatDate(session.login_at)}
                                {session.logout_at && <> · Logout: {formatDate(session.logout_at)}</>}
                              </p>
                            </div>
                            {!session.logout_at && (
                              <button
                                onClick={() => handleRevokeSession(session.id)}
                                className="shrink-0 rounded-md bg-rose-50 px-2 py-1 text-xs font-medium text-rose-700 hover:bg-rose-100 transition-colors"
                              >
                                Forzar Cierre
                              </button>
                            )}
                          </div>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              </TabPanel>

              {/* Tab 3: Cash Registers */}
              <TabPanel header="Auditoria de Cajas" pt={{ headerAction: { className: 'text-sm' } }}>
                <div className="mt-4">
                  <h4 className="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Historial de Turnos</h4>
                  {detailData.cash_registers.length === 0 ? (
                    <p className="text-sm text-slate-400">Sin registros de caja.</p>
                  ) : (
                    <div className="space-y-3">
                      {detailData.cash_registers.map((cr) => {
                        const diff = cr.actual_closing_balance !== null
                          ? (parseFloat(cr.actual_closing_balance) - parseFloat(cr.expected_closing_balance)).toFixed(2)
                          : null;
                        const isOpen = !cr.closed_at;

                        return (
                          <div key={cr.id} className="rounded-lg border border-slate-200 p-3">
                            <div className="flex items-center justify-between mb-2">
                              <span className="text-xs text-slate-500">
                                {formatDate(cr.opened_at)}
                                {cr.closed_at && <> → {formatDate(cr.closed_at)}</>}
                              </span>
                              <Tag
                                value={isOpen ? 'ABIERTA' : 'CERRADA'}
                                severity={isOpen ? 'warning' : 'success'}
                                className="text-xs"
                              />
                            </div>
                            <div className="grid grid-cols-3 gap-2 text-xs">
                              <div>
                                <span className="text-slate-500 block">Apertura</span>
                                <span className="font-semibold text-slate-900">${parseFloat(cr.opening_balance).toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
                              </div>
                              <div>
                                <span className="text-slate-500 block">Esperado</span>
                                <span className="font-semibold text-slate-900">${parseFloat(cr.expected_closing_balance).toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
                              </div>
                              <div>
                                <span className="text-slate-500 block">Real</span>
                                {cr.actual_closing_balance !== null ? (
                                  <span className="font-semibold text-slate-900">${parseFloat(cr.actual_closing_balance).toLocaleString('es-MX', { minimumFractionDigits: 2 })}</span>
                                ) : (
                                  <span className="text-slate-400">—</span>
                                )}
                              </div>
                            </div>
                            {diff !== null && (
                              <div className="mt-2 text-xs">
                                <span className="text-slate-500">Diferencia: </span>
                                <span className={`font-bold ${parseFloat(diff) >= 0 ? 'text-emerald-600' : 'text-rose-600'}`}>
                                  {parseFloat(diff) >= 0 ? '+' : ''}{parseFloat(diff).toLocaleString('es-MX', { minimumFractionDigits: 2 })}
                                </span>
                              </div>
                            )}
                          </div>
                        );
                      })}
                    </div>
                  )}
                </div>
              </TabPanel>
            </TabView>

            <button
              onClick={() => { setDetailUser(null); setDetailData(null); }}
              className="mt-4 w-full rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 transition-colors"
            >
              Cerrar
            </button>
          </div>
        ) : null}
      </Dialog>

      {/* Create User Dialog */}
      <Dialog
        visible={showCreate}
        onHide={() => !saving && setShowCreate(false)}
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
        <form onSubmit={handleCreateUser} className="p-6">
          <div className="mb-5">
            <h3 className="text-lg font-semibold text-slate-900">Nuevo Usuario</h3>
            <p className="text-xs text-slate-500">El usuario podra iniciar sesion inmediatamente.</p>
          </div>
          <div className="space-y-4">
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Nombre *</label>
              <InputText value={formData.name} onChange={(e) => set('name', e.target.value)} disabled={saving}
                className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm" pt={{ root: { className: 'w-full' } }} />
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Email *</label>
              <InputText value={formData.email} onChange={(e) => set('email', e.target.value)} disabled={saving}
                className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm" pt={{ root: { className: 'w-full' } }} />
              {fieldErrors.email && <p className="mt-1 text-xs text-rose-500">{fieldErrors.email}</p>}
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Contraseña *</label>
              <InputText type="password" value={formData.password} onChange={(e) => set('password', e.target.value)} disabled={saving}
                className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm" pt={{ root: { className: 'w-full' } }} />
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Rol *</label>
              <Dropdown value={formData.role} options={roleOptions} onChange={(e) => set('role', e.value)} placeholder="Seleccionar rol" disabled={saving}
                className="w-full text-sm" pt={{ root: { className: 'w-full' } }} />
            </div>
          </div>
          <div className="mt-5 flex gap-3">
            <Button type="button" label="Cancelar" onClick={() => setShowCreate(false)} disabled={saving}
              className="flex-1 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
              pt={{ root: { className: 'border border-slate-200' } }} />
            <Button type="submit" label={saving ? 'Creando...' : 'Crear Usuario'} disabled={saving} loading={saving}
              className="flex-1 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
              pt={{ root: { className: 'border-0' } }} />
          </div>
        </form>
      </Dialog>

      {/* Suspend / Activate Confirmation Dialog */}
      <Dialog
        visible={showSuspend}
        onHide={() => { setShowSuspend(false); setSuspendTarget(null); }}
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
          <div className="mb-4 flex items-center gap-3">
            <div className={`flex h-10 w-10 items-center justify-center rounded-full ${suspendTarget?.status === 'active' ? 'bg-amber-50' : 'bg-emerald-50'}`}>
              <i className={`pi ${suspendTarget?.status === 'active' ? 'pi-ban text-amber-600' : 'pi-check-circle text-emerald-600'} text-lg`} />
            </div>
            <div>
              <h3 className="text-lg font-semibold text-slate-900">
                {suspendTarget?.status === 'active' ? 'Suspender Usuario' : 'Reactivar Usuario'}
              </h3>
              <p className="text-xs text-slate-500">{suspendTarget?.name} ({suspendTarget?.email})</p>
            </div>
          </div>
          <div className="mb-5 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
            {suspendTarget?.status === 'active'
              ? 'Al suspender a este usuario se revocarán inmediatamente todas sus sesiones activas y no podrá ingresar al sistema hasta que sea reactivado. ¿Confirmar acción?'
              : 'Al reactivar a este usuario podrá iniciar sesión nuevamente en el sistema. ¿Confirmar acción?'}
          </div>
          <div className="flex gap-3">
            <Button type="button" label="Cancelar" onClick={() => { setShowSuspend(false); setSuspendTarget(null); }}
              className="flex-1 cursor-pointer rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
              pt={{ root: { className: 'border border-slate-200' } }} />
            <Button type="button"
              label={suspendTarget?.status === 'active' ? 'Sí, Suspender' : 'Sí, Reactivar'}
              onClick={() => handleToggleStatus(suspendTarget)}
              className={`flex-1 cursor-pointer rounded-lg px-4 py-2.5 text-sm font-semibold text-white ${
                suspendTarget?.status === 'active' ? 'bg-amber-600 hover:bg-amber-500' : 'bg-emerald-600 hover:bg-emerald-500'
              }`}
              pt={{ root: { className: 'border-0' } }} />
          </div>
        </div>
      </Dialog>

      {/* Reset Password Confirmation Dialog */}
      <Dialog
        visible={showReset}
        onHide={() => { setShowReset(false); setResetTarget(null); }}
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
          <div className="mb-4 flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-blue-50">
              <i className="pi pi-key text-blue-600 text-lg" />
            </div>
            <div>
              <h3 className="text-lg font-semibold text-slate-900">Resetear Contraseña</h3>
              <p className="text-xs text-slate-500">{resetTarget?.name} ({resetTarget?.email})</p>
            </div>
          </div>
          <div className="mb-5 rounded-lg bg-blue-50 border border-blue-200 px-4 py-3 text-sm text-blue-800">
            Se enviará un enlace de restauración de contraseña con vigencia estricta de 4 horas al correo institucional del usuario. ¿Confirmar envío?
          </div>
          <div className="flex gap-3">
            <Button type="button" label="Cancelar" onClick={() => { setShowReset(false); setResetTarget(null); }}
              className="flex-1 cursor-pointer rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
              pt={{ root: { className: 'border border-slate-200' } }} />
            <Button type="button" label="Sí, Enviar Enlace"
              onClick={() => handleSendReset(resetTarget)}
              className="flex-1 cursor-pointer rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500"
              pt={{ root: { className: 'border-0' } }} />
          </div>
        </div>
      </Dialog>

      {/* Delete Confirmation Dialog (Double Confirmation) */}
      <Dialog
        visible={showDelete}
        onHide={() => { setShowDelete(false); setDeleteTarget(null); setDeleteReason(''); }}
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
          <div className="mb-4 flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-rose-50">
              <svg className="h-5 w-5 text-rose-600" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
              </svg>
            </div>
            <div>
              <h3 className="text-lg font-semibold text-slate-900">Dar de Baja a Usuario</h3>
              <p className="text-xs text-slate-500">{deleteTarget?.name} ({deleteTarget?.email})</p>
            </div>
          </div>
          <div className="mb-4 rounded-lg bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
            Al dar de baja a este usuario, se revocarán todos sus tokens, se registrará la eliminación lógica en el sistema con fines de auditoría, y no podrá acceder nuevamente hasta ser restaurado desde la Papelera. Esta acción queda registrada con tu usuario como ejecutor.
          </div>
          <div className="mb-4">
            <label className="mb-1.5 block text-sm font-medium text-slate-700">Motivo de baja *</label>
            <textarea
              value={deleteReason}
              onChange={(e) => setDeleteReason(e.target.value)}
              placeholder="Describe el motivo de la baja logica..."
              rows={3}
              className="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-slate-900 placeholder-slate-400 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 resize-none"
            />
          </div>
          <div className="flex gap-3">
            <Button type="button" label="Cancelar" onClick={() => { setShowDelete(false); setDeleteTarget(null); setDeleteReason(''); }}
              className="flex-1 cursor-pointer rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
              pt={{ root: { className: 'border border-slate-200' } }} />
            <Button type="button" label="Confirmar Baja" onClick={handleDelete}
              disabled={!deleteReason.trim()}
              className="flex-1 cursor-pointer rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-rose-500 disabled:opacity-50"
              pt={{ root: { className: 'border-0' } }} />
          </div>
        </div>
      </Dialog>
    </AppLayout>
  );
}
