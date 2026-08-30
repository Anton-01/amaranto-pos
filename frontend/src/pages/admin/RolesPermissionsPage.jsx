import { useState, useEffect, useCallback, useMemo } from 'react';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { InputText } from 'primereact/inputtext';
import { Checkbox } from 'primereact/checkbox';
import { Tag } from 'primereact/tag';
import { toast } from 'sonner';
import api from '../../api/axios';
import AppLayout from '../../components/layout/AppLayout';
import { STACK_TABLE, STACK_CLASS } from '../../lib/responsive';

export default function RolesPermissionsPage() {
  const [roles, setRoles] = useState([]);
  const [permissions, setPermissions] = useState([]);
  const [loading, setLoading] = useState(true);

  const [showForm, setShowForm] = useState(false);
  const [editingRole, setEditingRole] = useState(null);
  const [roleName, setRoleName] = useState('');
  const [saving, setSaving] = useState(false);
  const [fieldErrors, setFieldErrors] = useState({});

  const [showDelete, setShowDelete] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState(null);

  const [selectedRole, setSelectedRole] = useState(null);

  const fetchData = useCallback(async () => {
    setLoading(true);
    try {
      const [rolesRes, permsRes] = await Promise.all([
        api.get('/admin/roles'),
        api.get('/admin/roles/permissions'),
      ]);
      setRoles(rolesRes.data.data);
      setPermissions(permsRes.data.data);
      if (!selectedRole && rolesRes.data.data.length > 0) {
        setSelectedRole(rolesRes.data.data[0]);
      }
    } catch {
      toast.error('Error al cargar roles y permisos.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchData(); }, [fetchData]);

  const permissionGroups = useMemo(() => {
    const groups = {};
    permissions.forEach(p => {
      if (!groups[p.group]) groups[p.group] = [];
      groups[p.group].push(p);
    });
    return groups;
  }, [permissions]);

  const openCreate = () => {
    setEditingRole(null);
    setRoleName('');
    setFieldErrors({});
    setShowForm(true);
  };

  const openEdit = (role) => {
    setEditingRole(role);
    setRoleName(role.name);
    setFieldErrors({});
    setShowForm(true);
  };

  const handleSave = async (e) => {
    e.preventDefault();
    setSaving(true);
    setFieldErrors({});
    try {
      if (editingRole) {
        await api.put(`/admin/roles/${editingRole.id}`, { name: roleName });
        toast.success('Rol actualizado exitosamente.');
      } else {
        await api.post('/admin/roles', { name: roleName });
        toast.success('Rol creado exitosamente.');
      }
      setShowForm(false);
      fetchData();
    } catch (err) {
      const errors = err.response?.data?.errors;
      if (errors) {
        setFieldErrors(Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v[0]])));
      } else {
        toast.error(err.response?.data?.message || 'Error al guardar rol.');
      }
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async () => {
    try {
      await api.delete(`/admin/roles/${deleteTarget.id}`);
      toast.success('Rol eliminado exitosamente.');
      setShowDelete(false);
      setDeleteTarget(null);
      if (selectedRole?.id === deleteTarget.id) setSelectedRole(null);
      fetchData();
    } catch (err) {
      toast.error(err.response?.data?.message || 'Error al eliminar rol.');
    }
  };

  const nameTemplate = (row) => (
    <button
      onClick={() => setSelectedRole(row)}
      className={`text-left transition-colors text-sm font-medium ${
        selectedRole?.id === row.id ? 'text-indigo-600' : 'text-slate-900 hover:text-indigo-600'
      }`}
    >
      {row.name}
    </button>
  );

  const usersCountTemplate = (row) => (
    <Tag
      value={`${row.users_count} usuario${row.users_count !== 1 ? 's' : ''}`}
      severity={row.users_count > 0 ? 'info' : 'warning'}
      className="text-xs"
    />
  );

  const actionsTemplate = (row) => (
    <div className="flex items-center gap-0.5">
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
        onClick={() => { setDeleteTarget(row); setShowDelete(true); }}
        className="cursor-pointer !h-8 !w-8"
        tooltip="Eliminar"
        tooltipOptions={{ position: 'top' }}
        disabled={row.users_count > 0}
      />
    </div>
  );

  return (
    <AppLayout>
      <div className="mb-5 flex flex-col gap-3 sm:mb-6 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Roles y Permisos</h1>
          <p className="text-sm text-slate-500">Administra los roles del sistema y su matriz de alcances.</p>
        </div>
        <Button
          label="Nuevo Rol"
          onClick={openCreate}
          className="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
          pt={{ root: { className: 'border-0' } }}
        />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {/* Left: Roles DataTable */}
        <div className="lg:col-span-1">
          <div className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div className="border-b border-slate-200 p-4">
              <h2 className="text-sm font-semibold text-slate-700">Roles del Sistema</h2>
            </div>
            <DataTable
              value={roles}
              loading={loading}
              emptyMessage="No hay roles."
              stripedRows
              {...STACK_TABLE}
              pt={{ root: { className: `text-sm ${STACK_CLASS}` }, thead: { className: 'bg-slate-50' } }}
            >
              <Column field="name" header="Rol" body={nameTemplate} sortable style={{ width: '40%' }} />
              <Column header="Asignados" body={usersCountTemplate} style={{ width: '30%' }} />
              <Column header="Acciones" body={actionsTemplate} style={{ width: '30%' }} />
            </DataTable>
          </div>
        </div>

        {/* Right: Permissions Mapping */}
        <div className="lg:col-span-2">
          <div className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <div className="border-b border-slate-200 p-4">
              <h2 className="text-sm font-semibold text-slate-700">
                {selectedRole
                  ? <>Matriz de Permisos — <Tag value={selectedRole.name} severity="info" className="text-xs capitalize ml-1" /></>
                  : 'Selecciona un rol para ver sus permisos'}
              </h2>
            </div>
            {selectedRole ? (
              <div className="p-4 space-y-6">
                {Object.entries(permissionGroups).map(([group, perms]) => (
                  <div key={group}>
                    <h3 className="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-400">{group}</h3>
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                      {perms.map((perm) => (
                        <label
                          key={perm.key}
                          className="flex items-center gap-3 rounded-lg border border-slate-200 p-3 transition-colors hover:bg-slate-50 cursor-pointer"
                        >
                          <Checkbox
                            checked={true}
                            disabled
                            pt={{ root: { className: 'shrink-0' } }}
                          />
                          <div>
                            <p className="text-sm font-medium text-slate-700">{perm.label}</p>
                            <p className="text-xs text-slate-400 font-mono">{perm.key}</p>
                          </div>
                        </label>
                      ))}
                    </div>
                  </div>
                ))}
                <div className="rounded-lg bg-slate-50 border border-slate-200 px-4 py-3 text-xs text-slate-500">
                  Los permisos se gestionan actualmente a nivel de middleware por rol. Esta matriz es informativa y refleja los alcances configurados en el backend.
                </div>
              </div>
            ) : (
              <div className="flex h-48 items-center justify-center">
                <p className="text-sm text-slate-400">Haz clic en un rol de la tabla para ver su configuración.</p>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Create/Edit Dialog */}
      <Dialog
        visible={showForm}
        onHide={() => !saving && setShowForm(false)}
        closable={!saving}
        modal
        header={null}
        className="w-full max-w-sm"
        pt={{
          mask: { className: 'backdrop-blur-sm bg-black/30' },
          root: { className: 'rounded-2xl border-0 shadow-2xl' },
          content: { className: 'p-0' },
        }}
      >
        <form onSubmit={handleSave} className="p-6">
          <div className="mb-5">
            <h3 className="text-lg font-semibold text-slate-900">
              {editingRole ? 'Editar Rol' : 'Nuevo Rol'}
            </h3>
            <p className="text-xs text-slate-500">
              {editingRole ? 'Actualiza el nombre del rol.' : 'Ingresa el nombre del nuevo rol (ej. Supervisor Nocturno).'}
            </p>
          </div>
          <div className="mb-4">
            <label className="mb-1.5 block text-sm font-medium text-slate-700">Nombre del Rol *</label>
            <InputText
              value={roleName}
              onChange={(e) => setRoleName(e.target.value)}
              disabled={saving}
              className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
              pt={{ root: { className: 'w-full' } }}
              placeholder="ej. supervisor_nocturno"
            />
            {fieldErrors.name && <p className="mt-1 text-xs text-rose-500">{fieldErrors.name}</p>}
          </div>
          <div className="flex gap-3">
            <Button type="button" label="Cancelar" onClick={() => setShowForm(false)} disabled={saving}
              className="flex-1 cursor-pointer rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
              pt={{ root: { className: 'border border-slate-200' } }} />
            <Button type="submit" label={saving ? 'Guardando...' : (editingRole ? 'Actualizar' : 'Crear Rol')} disabled={saving || !roleName.trim()} loading={saving}
              className="flex-1 cursor-pointer rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
              pt={{ root: { className: 'border-0' } }} />
          </div>
        </form>
      </Dialog>

      {/* Delete Confirmation */}
      <Dialog
        visible={showDelete}
        onHide={() => { setShowDelete(false); setDeleteTarget(null); }}
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
          <div className="mb-4 flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-rose-50">
              <i className="pi pi-trash text-rose-600 text-lg" />
            </div>
            <div>
              <h3 className="text-lg font-semibold text-slate-900">Eliminar Rol</h3>
              <p className="text-xs text-slate-500">{deleteTarget?.name}</p>
            </div>
          </div>
          {deleteTarget?.users_count > 0 ? (
            <div className="mb-4 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
              No se puede eliminar este rol porque tiene {deleteTarget.users_count} usuario(s) asignado(s). Reasigne al personal primero.
            </div>
          ) : (
            <div className="mb-4 rounded-lg bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
              Esta acción eliminará permanentemente el rol "{deleteTarget?.name}" del sistema.
            </div>
          )}
          <div className="flex gap-3">
            <Button type="button" label="Cancelar" onClick={() => { setShowDelete(false); setDeleteTarget(null); }}
              className="flex-1 cursor-pointer rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
              pt={{ root: { className: 'border border-slate-200' } }} />
            <Button type="button" label="Eliminar" onClick={handleDelete}
              disabled={deleteTarget?.users_count > 0}
              className="flex-1 cursor-pointer rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-rose-500 disabled:opacity-50"
              pt={{ root: { className: 'border-0' } }} />
          </div>
        </div>
      </Dialog>
    </AppLayout>
  );
}
