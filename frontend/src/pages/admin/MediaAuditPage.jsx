import { useCallback, useEffect, useState } from 'react';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { Dropdown } from 'primereact/dropdown';
import { Calendar } from 'primereact/calendar';
import { Button } from 'primereact/button';
import { Tag } from 'primereact/tag';
import { Dialog } from 'primereact/dialog';
import { InputSwitch } from 'primereact/inputswitch';
import { Paginator } from 'primereact/paginator';
import { toast } from 'sonner';
import AppLayout from '../../components/layout/AppLayout';
import mediaApi from '../../api/media';
import { formatDateTime } from '../../lib/mediaPreview';
import { dialogClass, DIALOG_PT, HIDE_BELOW } from '../../lib/responsive';
import { toLocalYmd } from '../../lib/dates';

/**
 * Forensic viewer of the media module.
 *
 * Read-only, and that is the feature: the trail is written exclusively as a
 * side effect of real actions, so there is nothing to add or edit here. What
 * this screen owes the person reading it is the ability to answer a specific
 * question — who took that file out of the system, and when — which is why the
 * filters are action, actor and date window, and why the detail dialog shows
 * the raw metadata of the entry rather than a prose summary of it.
 */
export default function MediaAuditPage() {
  const [logs, setLogs] = useState([]);
  const [actions, setActions] = useState([]);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(0);
  const [pagination, setPagination] = useState({ total: 0, per_page: 25 });

  const [action, setAction] = useState(null);
  const [criticalOnly, setCriticalOnly] = useState(false);
  const [range, setRange] = useState(null);
  const [detail, setDetail] = useState(null);

  useEffect(() => {
    mediaApi
      .auditCatalogs()
      .then((data) => setActions([{ label: 'Todas las acciones', value: null }, ...data.actions]))
      .catch(() => setActions([]));
  }, []);

  const fetchLogs = useCallback(async () => {
    setLoading(true);
    try {
      const [from, to] = Array.isArray(range) ? range : [null, null];

      const res = await mediaApi.auditLogs({
        action: action || undefined,
        critical_only: criticalOnly ? 1 : undefined,
        // Dates travel as local YYYY-MM-DD, per the system's date standard:
        // an ISO string would hand the server a UTC instant and shift the
        // window by six hours.
        from: from ? toLocalYmd(from) : undefined,
        to: to ? toLocalYmd(to) : undefined,
        page: page + 1,
      });

      setLogs(res.data);
      setPagination(res.metadata.pagination);
    } catch (err) {
      toast.error('Error al cargar la auditoría', { description: err.response?.data?.message });
    } finally {
      setLoading(false);
    }
  }, [action, criticalOnly, range, page]);

  useEffect(() => { fetchLogs(); }, [fetchLogs]);

  const actionTemplate = (row) => (
    <div className="flex items-center gap-2">
      {row.is_critical && <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-rose-500" />}
      <span className="text-sm">{row.action_label}</span>
    </div>
  );

  const resourceTemplate = (row) => (
    <span className="text-sm text-slate-700">
      {row.resource_name ?? row.media_file?.name ?? '—'}
      {row.media_file?.extension && (
        <span className="ml-1 text-[10px] uppercase text-slate-400">.{row.media_file.extension}</span>
      )}
    </span>
  );

  /*
   * The actor is read from the SNAPSHOT columns, not from the relation. That
   * is the point of snapshotting: a user deleted six months ago still has to
   * be named here, and `row.user` would be null for exactly the entries an
   * investigation cares most about.
   */
  const actorTemplate = (row) => (
    <div>
      <span className="block text-sm text-slate-800">{row.user_name ?? 'Sistema'}</span>
      {row.user_email && <span className="block text-[11px] text-slate-400">{row.user_email}</span>}
    </div>
  );

  return (
    <AppLayout>
      <div className="space-y-5">
        <div>
          <h1 className="text-xl font-bold text-slate-900 sm:text-2xl">Auditoría de Medios</h1>
          <p className="text-sm text-slate-500">
            Registro inmutable de cada acción sobre la biblioteca. Solo lectura.
          </p>
        </div>

        <div className="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-3 sm:flex-row sm:flex-wrap sm:items-center">
          <Dropdown
            value={action}
            options={actions}
            optionLabel="label"
            optionValue="value"
            onChange={(e) => { setAction(e.value); setPage(0); }}
            className="w-full sm:w-72"
            placeholder="Todas las acciones"
          />
          <Calendar
            value={range}
            onChange={(e) => { setRange(e.value); setPage(0); }}
            selectionMode="range"
            readOnlyInput
            showButtonBar
            dateFormat="dd/mm/yy"
            placeholder="Rango de fechas"
            className="w-full sm:w-64"
          />
          <div className="flex items-center gap-2">
            <InputSwitch checked={criticalOnly} onChange={(e) => { setCriticalOnly(e.value); setPage(0); }} />
            <span className="text-sm text-slate-700">Solo eventos críticos</span>
          </div>
          <Button
            icon="pi pi-refresh"
            label="Actualizar"
            outlined
            size="small"
            onClick={fetchLogs}
            className="sm:ml-auto"
          />
        </div>

        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
          <DataTable
            value={logs}
            loading={loading}
            size="small"
            className="text-sm"
            emptyMessage="No hay registros en el periodo seleccionado."
            onRowClick={(e) => setDetail(e.data)}
            rowClassName={() => 'cursor-pointer'}
          >
            <Column header="Fecha" body={(r) => formatDateTime(r.created_at)} />
            <Column header="Acción" body={actionTemplate} />
            <Column header="Recurso" body={resourceTemplate} className={HIDE_BELOW.sm} />
            <Column header="Operador" body={actorTemplate} className={HIDE_BELOW.md} />
            <Column header="IP" className={HIDE_BELOW.lg}
              body={(r) => <span className="font-mono text-[11px] text-slate-500">{r.ip_address ?? '—'}</span>} />
          </DataTable>
        </div>

        {pagination.total > pagination.per_page && (
          <Paginator
            first={page * pagination.per_page}
            rows={pagination.per_page}
            totalRecords={pagination.total}
            onPageChange={(e) => setPage(e.page)}
          />
        )}
      </div>

      <Dialog
        header="Detalle del registro"
        visible={detail !== null}
        onHide={() => setDetail(null)}
        className={dialogClass('lg')}
        pt={DIALOG_PT}
        draggable={false}
      >
        {detail && (
          <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
              <Tag value={detail.action_label} severity={detail.is_critical ? 'danger' : 'info'} className="text-xs" />
              <span className="text-xs text-slate-500">{formatDateTime(detail.created_at)}</span>
            </div>

            <dl className="grid gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
              <div>
                <dt className="text-xs text-slate-500">Operador</dt>
                <dd className="font-medium text-slate-800">{detail.user_name ?? 'Sistema'}</dd>
              </div>
              <div>
                <dt className="text-xs text-slate-500">Correo</dt>
                <dd className="font-medium text-slate-800">{detail.user_email ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-xs text-slate-500">Recurso</dt>
                <dd className="font-medium text-slate-800">{detail.resource_name ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-xs text-slate-500">ID en Drive</dt>
                <dd className="font-mono text-[11px] text-slate-700">{detail.drive_file_id ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-xs text-slate-500">Dirección IP</dt>
                <dd className="font-mono text-[11px] text-slate-700">{detail.ip_address ?? '—'}</dd>
              </div>
              <div className="sm:col-span-2">
                <dt className="text-xs text-slate-500">Agente de usuario</dt>
                <dd className="break-all text-[11px] text-slate-600">{detail.user_agent ?? '—'}</dd>
              </div>
            </dl>

            {detail.metadata && (
              <div>
                <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
                  Contexto registrado
                </p>
                {/* Raw, not summarized. An investigation needs the before/after
                    diff and the rejection reason exactly as they were stored. */}
                <pre className="max-h-72 overflow-auto rounded-lg bg-slate-900 p-3 text-[11px] leading-relaxed text-slate-100">
                  {JSON.stringify(detail.metadata, null, 2)}
                </pre>
              </div>
            )}
          </div>
        )}
      </Dialog>
    </AppLayout>
  );
}
