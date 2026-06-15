import { useEffect, useState, useCallback } from 'react';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { Dropdown } from 'primereact/dropdown';
import { InputText } from 'primereact/inputtext';
import { Button } from 'primereact/button';
import { Tag } from 'primereact/tag';
import { Dialog } from 'primereact/dialog';
import { toast } from 'sonner';
import { useAuth } from '../../context/AuthContext';
import api from '../../api/axios';
import AppLayout from '../../components/layout/AppLayout';

const TRASH_TYPES = [
  { label: 'Productos Eliminados', value: 'products' },
  { label: 'Categorías Eliminadas', value: 'categories' },
  { label: 'Usuarios Suspendidos / Bajas', value: 'users' },
  { label: 'Promociones Vencidas / Eliminadas', value: 'promotions' },
];

export default function TrashPage() {
  const { user } = useAuth();
  const isAdmin = user?.roles?.includes('admin');

  const [type, setType] = useState('products');
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(false);
  const [search, setSearch] = useState('');
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [page, setPage] = useState(1);

  const [reasonModal, setReasonModal] = useState({ visible: false, text: '' });
  const [purgeModal, setPurgeModal] = useState({ visible: false, item: null });
  const [purgeConfirmation, setPurgeConfirmation] = useState('');
  const [actionLoading, setActionLoading] = useState(null);

  const fetchItems = useCallback(async () => {
    setLoading(true);
    try {
      const params = { page };
      if (search.trim()) params.search = search.trim();
      const { data } = await api.get(`/admin/trash/${type}`, { params });
      setItems(data.data.data);
      setPagination({
        current_page: data.data.current_page,
        last_page: data.data.last_page,
        total: data.data.total,
      });
    } catch {
      toast.error('Error al cargar la papelera.');
    } finally {
      setLoading(false);
    }
  }, [type, page, search]);

  useEffect(() => {
    setPage(1);
    setSearch('');
  }, [type]);

  useEffect(() => {
    fetchItems();
  }, [fetchItems]);

  const handleRestore = async (item) => {
    setActionLoading(item.id);
    try {
      await api.post(`/admin/trash/${type}/${item.id}/restore`);
      toast.success('Registro restaurado exitosamente.');
      fetchItems();
    } catch {
      toast.error('Error al restaurar el registro.');
    } finally {
      setActionLoading(null);
    }
  };

  const handleForceDelete = async () => {
    if (!purgeModal.item) return;
    setActionLoading(purgeModal.item.id);
    try {
      await api.delete(`/admin/trash/${type}/${purgeModal.item.id}`, {
        data: { confirmation: purgeConfirmation },
      });
      toast.success('Registro eliminado permanentemente.');
      setPurgeModal({ visible: false, item: null });
      setPurgeConfirmation('');
      fetchItems();
    } catch (err) {
      const code = err.response?.data?.code;
      if (code === 'ERR_TRASH_ADMIN_ONLY') {
        toast.error('Solo el administrador puede eliminar permanentemente.');
      } else if (err.response?.status === 422) {
        toast.error('Debes escribir ELIMINAR para confirmar.');
      } else {
        toast.error('Error al eliminar permanentemente.');
      }
    } finally {
      setActionLoading(null);
    }
  };

  const nameColumn = (rowData) => {
    if (type === 'users') {
      return (
        <div>
          <p className="text-sm font-medium text-slate-900">{rowData.name}</p>
          <p className="text-xs text-slate-500">{rowData.email}</p>
        </div>
      );
    }
    if (type === 'products') {
      return (
        <div>
          <p className="text-sm font-medium text-slate-900">{rowData.name}</p>
          <p className="text-xs text-slate-500">SKU: {rowData.sku}</p>
        </div>
      );
    }
    return <span className="text-sm font-medium text-slate-900">{rowData.name}</span>;
  };

  const deletedByColumn = (rowData) => {
    const deleter = rowData.deleted_by_user;
    if (!deleter) return <span className="text-xs text-slate-400">Sistema</span>;
    return (
      <div>
        <p className="text-sm text-slate-900">{deleter.name}</p>
        <p className="text-xs text-slate-500">{deleter.email}</p>
      </div>
    );
  };

  const deletedAtColumn = (rowData) => {
    if (!rowData.deleted_at) return '—';
    return (
      <span className="text-sm text-slate-700">
        {new Date(rowData.deleted_at).toLocaleString('es-MX', {
          year: 'numeric',
          month: 'short',
          day: 'numeric',
          hour: '2-digit',
          minute: '2-digit',
        })}
      </span>
    );
  };

  const reasonColumn = (rowData) => {
    const reason = rowData.deletion_reason;
    if (!reason) return <span className="text-xs text-slate-400">Sin motivo</span>;
    const truncated = reason.length > 50;
    return (
      <div className="flex items-center gap-1">
        <span className="text-sm text-slate-700">
          {truncated ? reason.substring(0, 50) + '...' : reason}
        </span>
        {truncated && (
          <button
            onClick={() => setReasonModal({ visible: true, text: reason })}
            className="shrink-0 text-xs text-indigo-600 hover:text-indigo-800 font-medium"
          >
            Ver más
          </button>
        )}
      </div>
    );
  };

  const extraColumn = (rowData) => {
    if (type === 'products' && rowData.category) {
      return <Tag value={rowData.category.name} severity="info" />;
    }
    if (type === 'users' && rowData.roles?.length) {
      const role = rowData.roles[0].name;
      const severity = role === 'admin' ? 'danger' : role === 'manager' ? 'warn' : 'info';
      return <Tag value={role} severity={severity} />;
    }
    if (type === 'promotions') {
      const typeLabel = { percentage: '%', fixed_amount: '$', freebie_100: '2x1' };
      return <Tag value={typeLabel[rowData.type] || rowData.type} severity="warn" />;
    }
    return null;
  };

  const actionsColumn = (rowData) => (
    <div className="flex items-center gap-2">
      <Button
        label="Restaurar"
        size="small"
        severity="success"
        outlined
        loading={actionLoading === rowData.id}
        onClick={() => handleRestore(rowData)}
        className="text-xs"
      />
      <Button
        label="Purgar"
        size="small"
        severity="danger"
        outlined
        disabled={!isAdmin}
        onClick={() => {
          setPurgeModal({ visible: true, item: rowData });
          setPurgeConfirmation('');
        }}
        className="text-xs"
        tooltip={!isAdmin ? 'Solo administradores' : undefined}
        tooltipOptions={{ position: 'top' }}
      />
    </div>
  );

  const extraHeader = {
    products: 'Categoría',
    users: 'Rol',
    promotions: 'Tipo',
    categories: null,
  };

  return (
    <AppLayout>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Papelera Global</h1>
          <p className="mt-1 text-sm text-slate-500">
            Auditoría forense de registros eliminados del sistema.
          </p>
        </div>
        <Tag
          value={`${pagination.total} registros`}
          severity="secondary"
          className="text-sm"
        />
      </div>

      <div className="mb-4 flex items-center gap-3">
        <Dropdown
          value={type}
          options={TRASH_TYPES}
          onChange={(e) => setType(e.value)}
          className="w-80"
          placeholder="Seleccionar módulo"
        />
        <InputText
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setPage(1);
          }}
          placeholder="Buscar por nombre, SKU o email..."
          className="w-72"
        />
      </div>

      <div className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <DataTable
          value={items}
          loading={loading}
          paginator
          rows={15}
          totalRecords={pagination.total}
          lazy
          first={(pagination.current_page - 1) * 15}
          onPage={(e) => setPage(Math.floor(e.first / 15) + 1)}
          emptyMessage="No hay registros en la papelera."
          stripedRows
          size="small"
        >
          <Column header="Registro" body={nameColumn} style={{ minWidth: '180px' }} />
          {extraHeader[type] && (
            <Column header={extraHeader[type]} body={extraColumn} style={{ minWidth: '100px' }} />
          )}
          <Column header="Eliminado por" body={deletedByColumn} style={{ minWidth: '160px' }} />
          <Column header="Fecha de Baja" body={deletedAtColumn} style={{ minWidth: '160px' }} />
          <Column header="Motivo" body={reasonColumn} style={{ minWidth: '200px' }} />
          <Column header="Acciones" body={actionsColumn} style={{ minWidth: '200px' }} frozen alignFrozen="right" />
        </DataTable>
      </div>

      {/* Reason detail modal */}
      <Dialog
        visible={reasonModal.visible}
        onHide={() => setReasonModal({ visible: false, text: '' })}
        header="Motivo de la Baja"
        style={{ width: '480px' }}
        modal
        draggable={false}
      >
        <p className="text-sm text-slate-700 leading-relaxed whitespace-pre-wrap">
          {reasonModal.text}
        </p>
      </Dialog>

      {/* Purge confirmation modal — double validation */}
      <Dialog
        visible={purgeModal.visible}
        onHide={() => {
          if (actionLoading) return;
          setPurgeModal({ visible: false, item: null });
          setPurgeConfirmation('');
        }}
        header="Eliminar Permanentemente"
        style={{ width: '520px' }}
        modal
        draggable={false}
        closable={!actionLoading}
        footer={
          <div className="flex justify-end gap-2">
            <Button
              label="Cancelar"
              severity="secondary"
              outlined
              onClick={() => {
                setPurgeModal({ visible: false, item: null });
                setPurgeConfirmation('');
              }}
              disabled={!!actionLoading}
            />
            <Button
              label="Eliminar Permanentemente"
              severity="danger"
              onClick={handleForceDelete}
              disabled={purgeConfirmation !== 'ELIMINAR' || !!actionLoading}
              loading={!!actionLoading}
            />
          </div>
        }
      >
        <div className="space-y-4">
          <div className="rounded-lg border border-red-200 bg-red-50 p-4">
            <p className="text-sm font-semibold text-red-800">
              Esta acción es irreversible.
            </p>
            <p className="mt-1 text-sm text-red-700">
              El registro <strong>{purgeModal.item?.name}</strong> será eliminado
              permanentemente de la base de datos PostgreSQL. No podrá ser recuperado.
            </p>
          </div>

          {purgeModal.item?.deletion_reason && (
            <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
              <p className="text-xs font-medium text-slate-500 mb-1">Motivo de la baja original:</p>
              <p className="text-sm text-slate-700">{purgeModal.item.deletion_reason}</p>
            </div>
          )}

          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1.5">
              Escribe <strong className="text-red-600">ELIMINAR</strong> para confirmar:
            </label>
            <InputText
              value={purgeConfirmation}
              onChange={(e) => setPurgeConfirmation(e.target.value)}
              placeholder="ELIMINAR"
              className="w-full"
              autoFocus
            />
          </div>
        </div>
      </Dialog>
    </AppLayout>
  );
}
