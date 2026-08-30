import { useEffect, useRef, useState } from 'react';
import { Navigate } from 'react-router-dom';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { Dialog } from 'primereact/dialog';
import { Dropdown } from 'primereact/dropdown';
import { Calendar } from 'primereact/calendar';
import { InputText } from 'primereact/inputtext';
import { Tag } from 'primereact/tag';
import { Button } from 'primereact/button';
import { TabView, TabPanel } from 'primereact/tabview';
import { toast } from 'sonner';
import api from '../../api/axios';
import AppLayout from '../../components/layout/AppLayout';
import { useAuth } from '../../context/AuthContext';
import { toLocalYmd } from '../../lib/dates';
import { STACK_TABLE, STACK_CLASS, HIDE_BELOW, dialogClass, DIALOG_PT } from '../../lib/responsive';

/**
 * Monitor de Telemetria de Jobs y Boveda de Respaldos — EXCLUSIVO de admin.
 *
 * Doble candado: este gate redirige a cualquier no-admin y el backend protege
 * todos los endpoints con middleware role:admin. Se restringe a admin porque
 * las trazas de excepcion exponen rutas internas y estado del sistema, y
 * porque el rollback puede reescribir la base de datos completa.
 */

const STATUS_META = {
  pending: { label: 'En cola', severity: 'info', icon: 'pi-clock' },
  running: { label: 'Ejecutando', severity: 'warning', icon: 'pi-spin pi-spinner' },
  success: { label: 'Exitoso', severity: 'success', icon: 'pi-check-circle' },
  failed: { label: 'Fallido', severity: 'danger', icon: 'pi-times-circle' },
};

const TRIGGER_LABELS = {
  user: 'Usuario',
  system: 'Sistema',
  console: 'Consola',
  scheduler: 'Programado',
};

const STATUS_OPTIONS = [
  { label: 'Todos los estatus', value: null },
  ...Object.entries(STATUS_META).map(([value, meta]) => ({ label: meta.label, value })),
];

const fmtDate = (value) =>
  value ? new Date(value).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'medium' }) : '—';

/** 45 -> "45 ms". 1250 -> "1.25 s". 95000 -> "1m 35s". */
const fmtDuration = (ms) => {
  if (ms === null || ms === undefined) return '—';
  if (ms < 1000) return `${ms} ms`;
  if (ms < 60000) return `${(ms / 1000).toFixed(2)} s`;
  return `${Math.floor(ms / 60000)}m ${Math.round((ms % 60000) / 1000)}s`;
};

function StatusBadge({ status }) {
  const meta = STATUS_META[status] ?? { label: status, severity: null, icon: 'pi-question' };

  return (
    <Tag severity={meta.severity} className="gap-1.5 px-2.5 py-1 text-xs font-semibold">
      <i className={`pi ${meta.icon} text-[10px]`} />
      {meta.label}
    </Tag>
  );
}

function SummaryCard({ label, value, tone, icon }) {
  const tones = {
    success: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    danger: 'border-rose-200 bg-rose-50 text-rose-700',
    info: 'border-sky-200 bg-sky-50 text-sky-700',
    neutral: 'border-slate-200 bg-slate-50 text-slate-700',
  };

  return (
    <div className={`flex min-w-0 items-center gap-3 rounded-xl border p-3 sm:p-4 ${tones[tone] ?? tones.neutral}`}>
      <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white/70">
        <i className={`pi ${icon}`} />
      </span>
      <div className="min-w-0">
        <p className="truncate text-xl font-bold leading-none tabular-nums sm:text-2xl">{value}</p>
        <p className="mt-1 truncate text-xs font-medium uppercase tracking-wide opacity-80">{label}</p>
      </div>
    </div>
  );
}

export default function JobsMonitorPage() {
  const { user } = useAuth();
  const isAdmin = user?.roles?.includes('admin');

  // --- Catalogo ---
  const [catalog, setCatalog] = useState([]);
  const [summary, setSummary] = useState(null);
  const [loadingCatalog, setLoadingCatalog] = useState(true);

  // --- Historico ---
  const [history, setHistory] = useState([]);
  const [loadingHistory, setLoadingHistory] = useState(true);
  const [page, setPage] = useState(0);
  const [totalRecords, setTotalRecords] = useState(0);
  const [statusFilter, setStatusFilter] = useState(null);
  const [jobFilter, setJobFilter] = useState(null);
  const [dateRange, setDateRange] = useState(null);
  const [search, setSearch] = useState('');

  // --- Modal de traza ---
  const [detail, setDetail] = useState(null);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [retryingId, setRetryingId] = useState(null);

  // --- Boveda de respaldos ---
  const [vault, setVault] = useState(null);
  const [snapshots, setSnapshots] = useState([]);
  const [loadingVault, setLoadingVault] = useState(true);
  const [restorePhrase, setRestorePhrase] = useState('');
  const [restoreTarget, setRestoreTarget] = useState(null);
  const [restoring, setRestoring] = useState(false);
  const [verifyingName, setVerifyingName] = useState(null);
  const [requestingBackup, setRequestingBackup] = useState(false);

  const fetchCatalog = async () => {
    setLoadingCatalog(true);
    try {
      const res = await api.get('/admin/jobs');
      setCatalog(res.data.data.jobs);
      setSummary(res.data.data.summary);
    } catch (err) {
      toast.error(err.response?.data?.message || 'No se pudo cargar el catálogo de jobs.');
    } finally {
      setLoadingCatalog(false);
    }
  };

  /**
   * Carga explicita del historico. Los filtros se pasan como `overrides`
   * en lugar de leerse del estado para evitar el anti-patron de un
   * useCallback con dependencias que dispara una peticion por cada tecla.
   */
  const fetchHistory = async (targetPage = 0, overrides = {}) => {
    const f = { statusFilter, jobFilter, dateRange, search, ...overrides };
    setLoadingHistory(true);
    try {
      const res = await api.get('/admin/jobs/history', {
        params: {
          page: targetPage + 1,
          per_page: 15,
          status: f.statusFilter ?? undefined,
          job_name: f.jobFilter ?? undefined,
          date_from: toLocalYmd(f.dateRange?.[0]) ?? undefined,
          date_to: toLocalYmd(f.dateRange?.[1]) ?? undefined,
          search: f.search || undefined,
        },
      });
      setHistory(res.data.data.data);
      setTotalRecords(res.data.data.total);
      setPage(targetPage);
    } catch (err) {
      toast.error(err.response?.data?.message || 'No se pudo cargar el histórico de ejecuciones.');
    } finally {
      setLoadingHistory(false);
    }
  };

  const fetchVault = async () => {
    setLoadingVault(true);
    try {
      const res = await api.get('/admin/backups');
      setVault(res.data.data.vault);
      setSnapshots(res.data.data.snapshots);
      setRestorePhrase('');
    } catch (err) {
      // Un 503 aqui significa boveda inalcanzable: hay que DECIRLO, no
      // dejar una tabla vacia que se lee como "todo en orden".
      setVault(err.response?.data?.metadata?.vault ?? null);
      setSnapshots([]);
      toast.error(err.response?.data?.message || 'No se pudo consultar la bóveda de respaldos.');
    } finally {
      setLoadingVault(false);
    }
  };

  // Carga inicial unica, en cuanto el contexto confirma el rol admin.
  const didInitialFetch = useRef(false);
  useEffect(() => {
    if (!isAdmin || didInitialFetch.current) return;
    didInitialFetch.current = true;
    fetchCatalog();
    fetchHistory(0);
    fetchVault();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isAdmin]);

  const openDetail = async (row) => {
    setLoadingDetail(true);
    setDetail(row);
    try {
      const res = await api.get(`/admin/jobs/${row.id}`);
      setDetail(res.data.data);
    } catch {
      toast.error('No se pudo cargar el detalle técnico del job.');
    } finally {
      setLoadingDetail(false);
    }
  };

  const retryJob = async (row) => {
    setRetryingId(row.id);
    try {
      const res = await api.post(`/admin/jobs/${row.id}/retry`);
      toast.success('Job reencolado', { description: res.data.message });
      setDetail(null);
      fetchHistory(page);
      fetchCatalog();
    } catch (err) {
      toast.error('No se pudo reintentar', {
        description: err.response?.data?.message || 'Error inesperado.',
      });
    } finally {
      setRetryingId(null);
    }
  };

  const requestBackup = async () => {
    setRequestingBackup(true);
    try {
      const res = await api.post('/admin/backups');
      toast.success('Respaldo encolado', { description: res.data.message });
      fetchHistory(0);
      fetchCatalog();
    } catch (err) {
      toast.error(err.response?.data?.message || 'No se pudo encolar el respaldo.');
    } finally {
      setRequestingBackup(false);
    }
  };

  const verifySnapshot = async (snapshot) => {
    setVerifyingName(snapshot.name);
    try {
      const res = await api.get(`/admin/backups/${snapshot.name}/verify`);
      toast.success('Integridad verificada', { description: res.data.message });
    } catch (err) {
      toast.error('Integridad comprometida', {
        description: err.response?.data?.message || 'No se pudo verificar el snapshot.',
      });
    } finally {
      setVerifyingName(null);
    }
  };

  const executeRestore = async () => {
    setRestoring(true);
    try {
      const res = await api.post(`/admin/backups/${restoreTarget.name}/restore`, {
        confirmation_phrase: restorePhrase,
      });
      toast.success('Restauración completada', { description: res.data.message });
      setRestoreTarget(null);
      setRestorePhrase('');
      fetchVault();
    } catch (err) {
      toast.error('Rollback fallido', {
        description: err.response?.data?.message || 'Error inesperado.',
      });
    } finally {
      setRestoring(false);
    }
  };

  if (user && !isAdmin) {
    return <Navigate to="/dashboard" replace />;
  }

  const jobOptions = [
    { label: 'Todos los jobs', value: null },
    ...catalog.map((j) => ({ label: j.short_name, value: j.job_name })),
  ];

  return (
    <AppLayout>
      <div className="space-y-6">
        {summary && (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <SummaryCard label="Exitosos (24 h)" value={summary.success_24h} tone="success" icon="pi-check-circle" />
            <SummaryCard label="Fallidos (24 h)" value={summary.failed_24h} tone="danger" icon="pi-times-circle" />
            <SummaryCard label="En cola" value={summary.pending} tone="info" icon="pi-clock" />
            <SummaryCard label="Ejecutando" value={summary.running} tone="neutral" icon="pi-sync" />
          </div>
        )}

        {summary && !summary.telemetry_enabled && (
          <div role="alert" className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            <i className="pi pi-exclamation-triangle mr-2" />
            La captura de telemetría está <strong>desactivada</strong> (JOB_TELEMETRY_ENABLED=false). El
            histórico mostrado es el que quedó registrado antes de apagarla.
          </div>
        )}

        <TabView>
          {/* ---------------- Catalogo de jobs ---------------- */}
          <TabPanel header="Catálogo de Jobs" leftIcon="pi pi-list mr-2">
            <DataTable
              value={catalog}
              loading={loadingCatalog}
              dataKey="job_name"
              emptyMessage="Todavía no se ha ejecutado ningún job en segundo plano."
              className={`text-sm ${STACK_CLASS}`}
              stripedRows
              {...STACK_TABLE}
            >
              <Column
                header="Job"
                body={(row) => (
                  <div className="min-w-0">
                    <p className="font-semibold text-slate-800">{row.short_name}</p>
                    <p className="truncate font-mono text-[11px] text-slate-400">{row.job_name}</p>
                  </div>
                )}
              />
              <Column header="Último estado" body={(row) => <StatusBadge status={row.last_status} />} />
              {/* The run count is the sum of the success/failure split shown
                  next to it, so on a phone card it is pure redundancy. */}
              <Column header="Ejecuciones" body={(row) => <span className="tabular-nums">{row.total_runs}</span>} className={HIDE_BELOW.md} />
              <Column
                header="Éxito / Fallo"
                body={(row) => (
                  <span className="tabular-nums">
                    <span className="font-semibold text-emerald-600">{row.success_count}</span>
                    <span className="mx-1 text-slate-300">/</span>
                    <span className="font-semibold text-rose-600">{row.failed_count}</span>
                  </span>
                )}
              />
              <Column
                header="Tasa de éxito"
                body={(row) =>
                  row.success_rate === null ? (
                    <span className="text-slate-400">—</span>
                  ) : (
                    <span
                      className={`font-semibold tabular-nums ${
                        row.success_rate >= 95
                          ? 'text-emerald-600'
                          : row.success_rate >= 75
                            ? 'text-amber-600'
                            : 'text-rose-600'
                      }`}
                    >
                      {row.success_rate}%
                    </span>
                  )
                }
              />
              <Column header="Duración media" body={(row) => fmtDuration(row.avg_duration_ms)} className={HIDE_BELOW.lg} />
              <Column header="Última ejecución" body={(row) => fmtDate(row.last_seen_at)} />
              <Column
                header="Histórico"
                body={(row) => (
                  <Button
                    icon="pi pi-filter"
                    rounded
                    text
                    size="small"
                    tooltip="Ver histórico de este job"
                    tooltipOptions={{ position: 'left' }}
                    onClick={() => {
                      setJobFilter(row.job_name);
                      fetchHistory(0, { jobFilter: row.job_name });
                    }}
                  />
                )}
              />
            </DataTable>
          </TabPanel>

          {/* ---------------- Historico forense ---------------- */}
          <TabPanel header="Histórico Forense" leftIcon="pi pi-history mr-2">
            {/* Six controls: a two-column grid on phones (the two action
                buttons share the last row), a wrapping row from md up. */}
            <div className="mb-4 grid grid-cols-2 gap-2 md:flex md:flex-wrap md:items-center">
              <Dropdown
                value={statusFilter}
                options={STATUS_OPTIONS}
                onChange={(e) => {
                  setStatusFilter(e.value);
                  fetchHistory(0, { statusFilter: e.value });
                }}
                placeholder="Estatus"
                className="col-span-2 w-full text-sm md:w-44"
              />
              <Dropdown
                value={jobFilter}
                options={jobOptions}
                onChange={(e) => {
                  setJobFilter(e.value);
                  fetchHistory(0, { jobFilter: e.value });
                }}
                placeholder="Job"
                filter
                className="col-span-2 w-full text-sm md:w-56"
              />
              <Calendar
                value={dateRange}
                onChange={(e) => {
                  setDateRange(e.value);
                  if (!e.value || e.value[1]) fetchHistory(0, { dateRange: e.value });
                }}
                selectionMode="range"
                readOnlyInput
                showButtonBar
                placeholder="Rango de fechas"
                dateFormat="dd/mm/yy"
                className="col-span-2 w-full text-sm md:w-60"
                pt={{ root: { className: 'w-full md:w-60' } }}
              />
              <span className="col-span-2 p-input-icon-left w-full md:w-auto">
                <InputText
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && fetchHistory(0)}
                  placeholder="Buscar job o error…"
                  className="w-full text-sm md:w-60"
                />
              </span>
              <Button label="Aplicar" icon="pi pi-search" size="small" onClick={() => fetchHistory(0)} className="w-full md:w-auto" />
              <Button
                label="Limpiar"
                icon="pi pi-times"
                size="small"
                outlined
                className="w-full md:w-auto"
                onClick={() => {
                  setStatusFilter(null);
                  setJobFilter(null);
                  setDateRange(null);
                  setSearch('');
                  fetchHistory(0, { statusFilter: null, jobFilter: null, dateRange: null, search: '' });
                }}
              />
            </div>

            <DataTable
              value={history}
              loading={loadingHistory}
              dataKey="id"
              lazy
              paginator
              rows={15}
              first={page * 15}
              totalRecords={totalRecords}
              onPage={(e) => fetchHistory(e.page)}
              emptyMessage="Sin ejecuciones que coincidan con los filtros."
              className={`text-sm ${STACK_CLASS}`}
              stripedRows
              {...STACK_TABLE}
              rowClassName={(row) => (row.status === 'failed' ? 'bg-rose-50/40' : '')}
            >
              <Column header="Estatus" body={(row) => <StatusBadge status={row.status} />} />
              <Column
                header="Job"
                body={(row) => (
                  <div className="min-w-0">
                    <p className="font-semibold text-slate-800">{row.short_name}</p>
                    {row.attempt > 1 && (
                      <span className="text-[11px] font-medium text-amber-600">Intento #{row.attempt}</span>
                    )}
                  </div>
                )}
              />
              <Column header="Duración" body={(row) => fmtDuration(row.duration_ms)} className={HIDE_BELOW.md} />
              <Column
                header="Disparado por"
                className={HIDE_BELOW.md}
                body={(row) => (
                  <div className="min-w-0">
                    <p className="truncate text-slate-700">
                      {row.triggered_by?.name ?? TRIGGER_LABELS[row.trigger_source] ?? 'Sistema'}
                    </p>
                    {row.triggered_by && (
                      <p className="truncate text-[11px] text-slate-400">{row.triggered_by.email}</p>
                    )}
                  </div>
                )}
              />
              <Column
                header="Error"
                body={(row) =>
                  row.exception_message ? (
                    <span className="line-clamp-1 max-w-xs text-rose-700">{row.exception_message}</span>
                  ) : (
                    <span className="text-slate-300">—</span>
                  )
                }
              />
              <Column header="Fecha" body={(row) => fmtDate(row.created_at)} />
              <Column
                header="Acciones"
                body={(row) => (
                  <div className="flex gap-1">
                    <Button
                      icon="pi pi-eye"
                      rounded
                      text
                      size="small"
                      tooltip="Ver detalle técnico"
                      tooltipOptions={{ position: 'left' }}
                      onClick={() => openDetail(row)}
                    />
                    {row.retryable && (
                      <Button
                        icon="pi pi-replay"
                        rounded
                        text
                        severity="warning"
                        size="small"
                        loading={retryingId === row.id}
                        tooltip="Reintentar job"
                        tooltipOptions={{ position: 'left' }}
                        onClick={() => retryJob(row)}
                      />
                    )}
                  </div>
                )}
              />
            </DataTable>
          </TabPanel>

          {/* ---------------- Boveda de respaldos ---------------- */}
          <TabPanel header="Puntos de Restauración" leftIcon="pi pi-database mr-2">
            {vault && (
              <div
                className={`mb-4 rounded-xl border p-4 ${
                  vault.isolated && vault.encrypted
                    ? 'border-emerald-200 bg-emerald-50'
                    : 'border-amber-200 bg-amber-50'
                }`}
              >
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p
                      className={`text-sm font-semibold ${
                        vault.isolated && vault.encrypted ? 'text-emerald-900' : 'text-amber-900'
                      }`}
                    >
                      <i className={`pi ${vault.isolated ? 'pi-cloud' : 'pi-exclamation-triangle'} mr-2`} />
                      Bóveda: {vault.disk}
                      {vault.isolated ? ' — aislada en Google Cloud Storage' : ' — SIN aislamiento'}
                    </p>
                    <p className={`mt-1 text-sm ${vault.isolated && vault.encrypted ? 'text-emerald-700' : 'text-amber-800'}`}>
                      {vault.reason ?? 'Respaldos cifrados en reposo y almacenados fuera de la infraestructura primaria.'}
                    </p>
                  </div>
                  <Button
                    label="Generar respaldo"
                    icon="pi pi-cloud-upload"
                    size="small"
                    loading={requestingBackup}
                    onClick={requestBackup}
                  />
                </div>
              </div>
            )}

            <DataTable
              value={snapshots}
              loading={loadingVault}
              dataKey="name"
              emptyMessage="No hay puntos de restauración en la bóveda."
              className={`text-sm ${STACK_CLASS}`}
              stripedRows
              {...STACK_TABLE}
            >
              <Column
                header="Snapshot"
                body={(row) => (
                  <div className="min-w-0">
                    <p className="font-mono text-xs font-semibold text-slate-800">{row.name}</p>
                    <p className="truncate font-mono text-[10px] text-slate-400">{row.checksum?.slice(0, 24)}…</p>
                  </div>
                )}
              />
              <Column header="Creado" body={(row) => fmtDate(row.created_at)} />
              <Column header="Tamaño" body={(row) => row.size_human} />
              <Column
                header="Cifrado"
                body={(row) =>
                  row.encrypted ? (
                    <Tag severity="success" className="text-xs">
                      <i className="pi pi-lock mr-1 text-[10px]" />
                      {row.cipher}
                    </Tag>
                  ) : (
                    <Tag severity="warning" className="text-xs">
                      Sin cifrar
                    </Tag>
                  )
                }
              />
              <Column header="Origen" body={(row) => <span className="text-slate-600">{row.trigger}</span>} className={HIDE_BELOW.lg} />
              <Column header="Autor" body={(row) => row.created_by_name ?? '—'} className={HIDE_BELOW.md} />
              <Column
                header="Acciones"
                body={(row) => (
                  <div className="flex gap-1">
                    <Button
                      icon="pi pi-shield"
                      rounded
                      text
                      size="small"
                      loading={verifyingName === row.name}
                      tooltip="Verificar integridad (checksum)"
                      tooltipOptions={{ position: 'left' }}
                      onClick={() => verifySnapshot(row)}
                    />
                    <Button
                      icon="pi pi-replay"
                      rounded
                      text
                      severity="danger"
                      size="small"
                      tooltip="Restaurar (rollback)"
                      tooltipOptions={{ position: 'left' }}
                      onClick={() => {
                        setRestoreTarget(row);
                        setRestorePhrase('');
                      }}
                    />
                  </div>
                )}
              />
            </DataTable>
          </TabPanel>
        </TabView>
      </div>

      {/* ---------------- Modal: traza tecnica ---------------- */}
      <Dialog
        visible={!!detail}
        onHide={() => setDetail(null)}
        header={detail ? `${detail.short_name} — intento #${detail.attempt}` : ''}
        className={dialogClass('xl')}
        pt={DIALOG_PT}
        dismissableMask
      >
        {detail && (
          <div className="space-y-4 text-sm">
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
              {[
                ['Estatus', <StatusBadge key="s" status={detail.status} />],
                ['Duración', fmtDuration(detail.duration_ms)],
                ['Conexión / cola', `${detail.connection ?? '—'} / ${detail.queue ?? '—'}`],
                ['Encolado', fmtDate(detail.queued_at)],
                ['Iniciado', fmtDate(detail.started_at)],
                ['Finalizado', fmtDate(detail.finished_at)],
              ].map(([label, value]) => (
                <div key={label} className="rounded-lg bg-slate-50 p-3">
                  <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">{label}</p>
                  <div className="mt-1 font-medium text-slate-800">{value}</div>
                </div>
              ))}
            </div>

            <div className="rounded-lg bg-slate-50 p-3">
              <p className="text-[11px] font-medium uppercase tracking-wide text-slate-400">Clase</p>
              <p className="mt-1 break-all font-mono text-xs text-slate-700">{detail.job_name}</p>
              {detail.job_uuid && (
                <p className="mt-1 break-all font-mono text-[11px] text-slate-400">UUID: {detail.job_uuid}</p>
              )}
            </div>

            {detail.exception_message && (
              <div className="rounded-lg border border-rose-200 bg-rose-50 p-3">
                <p className="text-[11px] font-medium uppercase tracking-wide text-rose-500">
                  {detail.exception_class}
                </p>
                <p className="mt-1 font-medium text-rose-900">{detail.exception_message}</p>
              </div>
            )}

            {loadingDetail ? (
              <p className="py-6 text-center text-slate-400">
                <i className="pi pi-spin pi-spinner mr-2" />
                Cargando traza técnica…
              </p>
            ) : (
              detail.exception_trace && (
                <div>
                  <p className="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-slate-400">
                    Traza de la excepción
                  </p>
                  {/* overflow-x propio: la traza nunca debe empujar el ancho del modal */}
                  <pre className="max-h-80 overflow-auto rounded-lg bg-slate-900 p-4 font-mono text-[11px] leading-relaxed text-slate-200">
                    {detail.exception_trace}
                  </pre>
                </div>
              )
            )}

            {detail.retryable && (
              <div className="flex justify-end border-t pt-4">
                <Button
                  label="Reintentar este job"
                  icon="pi pi-replay"
                  severity="warning"
                  loading={retryingId === detail.id}
                  onClick={() => retryJob(detail)}
                />
              </div>
            )}
          </div>
        )}
      </Dialog>

      {/* ---------------- Modal: rollback ---------------- */}
      <Dialog
        visible={!!restoreTarget}
        onHide={() => (restoring ? null : setRestoreTarget(null))}
        closable={!restoring}
        header="Restauración de emergencia (Rollback)"
        className={dialogClass('md')}
        pt={DIALOG_PT}
      >
        {restoreTarget && (
          <div className="space-y-4 text-sm">
            <div className="rounded-xl border border-rose-200 bg-rose-50 p-4">
              <p className="font-semibold text-rose-900">
                <i className="pi pi-exclamation-triangle mr-2" />
                Esta operación SOBRESCRIBE la base de datos actual
              </p>
              <p className="mt-2 leading-relaxed text-rose-800">
                Se validará el checksum del snapshot y, si coincide, se aplicará el volcado dentro de una
                transacción única: o se restaura por completo, o no se modifica nada.
              </p>
            </div>

            <div className="space-y-1.5 rounded-lg bg-slate-50 p-3">
              <p className="font-mono text-xs font-semibold text-slate-800">{restoreTarget.name}</p>
              <p className="text-xs text-slate-500">
                Creado el {fmtDate(restoreTarget.created_at)} · {restoreTarget.size_human} · entorno{' '}
                {restoreTarget.app_env}
              </p>
            </div>

            <div>
              <label htmlFor="phrase" className="mb-1.5 block font-medium text-slate-700">
                Escribe <code className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs">RESTAURAR PRODUCCION</code>{' '}
                para confirmar
              </label>
              <InputText
                id="phrase"
                value={restorePhrase}
                onChange={(e) => setRestorePhrase(e.target.value)}
                disabled={restoring}
                autoComplete="off"
                className="w-full"
              />
            </div>

            <div className="flex justify-end gap-2 border-t pt-4">
              <Button label="Cancelar" outlined disabled={restoring} onClick={() => setRestoreTarget(null)} />
              <Button
                label="Ejecutar rollback"
                icon="pi pi-replay"
                severity="danger"
                loading={restoring}
                disabled={restorePhrase.trim().length === 0}
                onClick={executeRestore}
              />
            </div>
          </div>
        )}
      </Dialog>
    </AppLayout>
  );
}
