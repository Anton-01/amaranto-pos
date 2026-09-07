import { useCallback, useEffect, useRef, useState } from 'react';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { Dropdown } from 'primereact/dropdown';
import { Calendar } from 'primereact/calendar';
import { Button } from 'primereact/button';
import { InputText } from 'primereact/inputtext';
import { Tag } from 'primereact/tag';
import { Dialog } from 'primereact/dialog';
import { InputSwitch } from 'primereact/inputswitch';
import { toast } from 'sonner';
import AppLayout from '../../components/layout/AppLayout';
import mediaApi from '../../api/media';
import { formatDateTime } from '../../lib/mediaPreview';
import { dialogClass, DIALOG_PT, HIDE_BELOW, STACK_TABLE, STACK_CLASS } from '../../lib/responsive';
import { toLocalYmd } from '../../lib/dates';

const SORT_OPTIONS = [
  { label: 'Más recientes primero', value: 'desc' },
  { label: 'Más antiguos primero', value: 'asc' },
];

const ROWS_PER_PAGE = [10, 25, 50, 100];

const DEFAULT_PER_PAGE = 25;

/**
 * Forensic viewer of the media module.
 *
 * Read-only, and that is the feature: the trail is written exclusively as a
 * side effect of real actions, so there is nothing to add or edit here. What
 * this screen owes the person reading it is the ability to answer a specific
 * question — who took that file out of the system, and when.
 *
 * WHY A LAZY DATATABLE AND NOT A PLAIN LIST. The trail is append-only and grows
 * forever; it is the one table in the system guaranteed to reach five figures.
 * So the grid is server-driven end to end — `lazy` with the paginator inside
 * the table: page, page size, ordering and every filter travel to the API and
 * only the visible page is ever in memory. Nothing here filters client-side,
 * because a client-side filter over one page of 25 rows silently answers a
 * different question than the one the investigator asked.
 *
 * WHY FILTERS ARE APPLIED EXPLICITLY. Everything except the search box waits
 * for "Aplicar Filtros". These reads are the heaviest in the module, and a
 * dropdown wired straight to a request fires one per adjustment while the user
 * is still assembling the query. The search box is the exception — it is
 * debounced, because a text needle is typed and read incrementally.
 */
export default function MediaAuditPage() {
  const [logs, setLogs] = useState([]);
  const [actions, setActions] = useState([]);
  const [operators, setOperators] = useState([]);
  const [loading, setLoading] = useState(true);

  const [page, setPage] = useState(0);
  const [perPage, setPerPage] = useState(DEFAULT_PER_PAGE);
  const [totalRecords, setTotalRecords] = useState(0);
  const [activeWindow, setActiveWindow] = useState(null);

  const [action, setAction] = useState(null);
  const [operator, setOperator] = useState(null);
  const [criticalOnly, setCriticalOnly] = useState(false);
  const [range, setRange] = useState(null);
  const [search, setSearch] = useState('');
  const [sortOrder, setSortOrder] = useState('desc');
  const [showAdvanced, setShowAdvanced] = useState(false);

  const [detail, setDetail] = useState(null);

  useEffect(() => {
    mediaApi
      .auditCatalogs()
      .then((data) => {
        setActions([{ label: 'Todas las acciones', value: null }, ...data.actions]);
        setOperators([
          { label: 'Todos los operadores', value: null },
          ...(data.operators ?? []),
        ]);
      })
      .catch(() => {
        setActions([]);
        setOperators([]);
      });
  }, []);

  /**
   * Query string for the current filter state.
   *
   * `overrides` carries the value just chosen: React's setters have not landed
   * in state yet during the same event, so the handler passes the new value in
   * rather than reading a stale one.
   */
  const buildParams = useCallback((overrides = {}) => {
    const f = { action, operator, criticalOnly, range, search, sortOrder, ...overrides };
    const [from, to] = Array.isArray(f.range) ? f.range : [null, null];
    const needle = f.search.trim();

    return {
      action: f.action || undefined,
      user_id: f.operator || undefined,
      critical_only: f.criticalOnly ? 1 : undefined,
      search: needle === '' ? undefined : needle,
      sort_order: f.sortOrder,
      /*
       * Dates travel as local YYYY-MM-DD, per the system's date standard: an
       * ISO string would hand the server a UTC instant and shift the window by
       * six hours.
       */
      from: from ? toLocalYmd(from) : undefined,
      to: to ? toLocalYmd(to) : undefined,
    };
  }, [action, operator, criticalOnly, range, search, sortOrder]);

  /**
   * The single entry point for loading a page. Called explicitly — on mount,
   * on paging, on applying filters and on the manual refresh — never from an
   * effect that watches the filter state, so a request can never be triggered
   * by the render its own response causes.
   */
  const fetchLogs = useCallback(async (pageNum = 0, rows = DEFAULT_PER_PAGE, overrides = {}) => {
    setLoading(true);
    try {
      const res = await mediaApi.auditLogs({
        ...buildParams(overrides),
        page: pageNum + 1,
        per_page: rows,
      });

      setLogs(res.data);
      setTotalRecords(res.metadata.pagination.total);
      setActiveWindow(res.metadata.window ?? null);
    } catch (err) {
      toast.error('Error al cargar la auditoría', { description: err.response?.data?.message });
    } finally {
      setLoading(false);
    }
  }, [buildParams]);

  // Initial load: exactly ONE request on mount. The ref shields it from
  // StrictMode's double mount in development.
  const didInitialFetch = useRef(false);
  useEffect(() => {
    if (didInitialFetch.current) return;
    didInitialFetch.current = true;
    fetchLogs(0, DEFAULT_PER_PAGE);
  }, [fetchLogs]);

  /*
   * Debounced search. The needle is the one filter people type rather than
   * pick, and waiting for a button to see whether a filename matches makes the
   * box useless; 400ms is long enough that a word costs one request and not
   * one per letter.
   */
  const didMountSearch = useRef(false);
  const skipSearchEffect = useRef(false);
  useEffect(() => {
    if (!didMountSearch.current) {
      didMountSearch.current = true;
      return;
    }

    // "Limpiar" already reloaded with the emptied needle; without this the
    // reset of the box would queue a duplicate request 400ms behind it.
    if (skipSearchEffect.current) {
      skipSearchEffect.current = false;
      return;
    }

    const timer = setTimeout(() => {
      setPage(0);
      fetchLogs(0, perPage, { search });
    }, 400);

    return () => clearTimeout(timer);
    // `fetchLogs` is intentionally out of the deps: its identity changes with
    // every filter, and depending on it would turn this into a watcher that
    // re-fires the search whenever any unrelated dropdown moves.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search]);

  const applyFilters = (overrides = {}) => {
    setPage(0);
    fetchLogs(0, perPage, overrides);
  };

  const clearFilters = () => {
    setAction(null);
    setOperator(null);
    setCriticalOnly(false);
    setRange(null);
    if (search !== '') skipSearchEffect.current = true;
    setSearch('');
    setSortOrder('desc');
    setPage(0);
    fetchLogs(0, perPage, {
      action: null,
      operator: null,
      criticalOnly: false,
      range: null,
      search: '',
      sortOrder: 'desc',
    });
  };

  /** Paging and page size both come through here: PrimeReact reports both. */
  const onPage = (e) => {
    const rows = e.rows ?? perPage;
    const nextPage = Math.floor(e.first / rows);
    setPerPage(rows);
    setPage(nextPage);
    fetchLogs(nextPage, rows);
  };

  /*
   * Recency is the only ordering offered, in either direction. Every other
   * column is a snapshot, and sorting a trail by the operator's name says
   * nothing about the sequence of events an investigation is reconstructing.
   *
   * The handler lives on the DataTable because the grid is `lazy`: PrimeReact
   * does not reorder anything itself, it reports the click and the server
   * returns the page in the new order.
   */
  const onSort = (e) => {
    const next = e.sortOrder === 1 ? 'asc' : 'desc';
    setSortOrder(next);
    setPage(0);
    fetchLogs(0, perPage, { sortOrder: next });
  };

  const activeFilterCount =
    (action ? 1 : 0) + (operator ? 1 : 0) + (criticalOnly ? 1 : 0) + (range?.[0] ? 1 : 0);

  const dateTemplate = (row) => (
    <span className="whitespace-nowrap text-sm text-slate-700">{formatDateTime(row.created_at)}</span>
  );

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

  const detailTemplate = (row) => (
    <Button
      icon="pi pi-eye"
      severity="info"
      text
      rounded
      onClick={(e) => { e.stopPropagation(); setDetail(row); }}
      aria-label="Ver detalle del registro"
      tooltip="Ver detalle"
      tooltipOptions={{ position: 'top' }}
      className="cursor-pointer !h-8 !w-8"
    />
  );

  return (
    <AppLayout>
      <div className="space-y-4">
        <div>
          <h1 className="text-xl font-bold text-slate-900 sm:text-2xl">Auditoría de Medios</h1>
          <p className="text-sm text-slate-500">
            Registro inmutable de cada acción sobre la biblioteca. Solo lectura.
          </p>
        </div>

        {/* Search + panel toggle + manual refresh */}
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
          {/* `.search-field` (index.css) replaces PrimeReact's removed
              `p-input-icon-left`; see the rule for why it cannot live here as
              a Tailwind utility. */}
          <div className="search-field w-full sm:max-w-sm">
            <i className="pi pi-search search-field-icon" />
            <InputText
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Buscar archivo, operador, correo o IP…"
            />
          </div>

          <div className="flex items-center gap-2 sm:ml-auto">
            <Button
              label={showAdvanced ? 'Ocultar Filtros' : 'Filtros Avanzados'}
              icon={showAdvanced ? 'pi pi-chevron-up' : 'pi pi-filter'}
              badge={activeFilterCount > 0 ? String(activeFilterCount) : null}
              onClick={() => setShowAdvanced(!showAdvanced)}
              className="cursor-pointer rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50"
              pt={{ root: { className: 'border border-slate-200' } }}
            />
            <Button
              icon="pi pi-refresh"
              label="Actualizar"
              outlined
              size="small"
              disabled={loading}
              onClick={() => fetchLogs(page, perPage)}
              className="cursor-pointer"
            />
          </div>
        </div>

        {showAdvanced && (
          <div className="rounded-xl border border-slate-200 bg-white p-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-600">Acción</label>
                <Dropdown
                  value={action}
                  options={actions}
                  optionLabel="label"
                  optionValue="value"
                  onChange={(e) => setAction(e.value)}
                  placeholder="Todas las acciones"
                  filter
                  className="w-full text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>

              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-600">Operador</label>
                <Dropdown
                  value={operator}
                  options={operators}
                  optionLabel="label"
                  optionValue="value"
                  onChange={(e) => setOperator(e.value)}
                  placeholder="Todos los operadores"
                  filter
                  emptyMessage="Sin operadores en la traza"
                  className="w-full text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>

              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-600">Rango de fechas</label>
                <Calendar
                  value={range}
                  onChange={(e) => setRange(e.value)}
                  selectionMode="range"
                  readOnlyInput
                  showButtonBar
                  dateFormat="dd/mm/yy"
                  placeholder="Ventana por defecto"
                  className="w-full text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>

              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-600">Orden</label>
                <Dropdown
                  value={sortOrder}
                  options={SORT_OPTIONS}
                  optionLabel="label"
                  optionValue="value"
                  onChange={(e) => setSortOrder(e.value)}
                  className="w-full text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>

              <div className="flex items-center gap-2 sm:col-span-2">
                <InputSwitch checked={criticalOnly} onChange={(e) => setCriticalOnly(e.value)} />
                <span className="text-sm text-slate-700">Solo eventos críticos</span>
              </div>
            </div>

            {/* Los controles de arriba SOLO actualizan estado local: ninguna
                peticion sale hasta que el usuario aplica de forma manual. */}
            <div className="mt-4 flex flex-col gap-2 border-t border-slate-100 pt-3 sm:flex-row sm:items-center sm:justify-between">
              <p className="text-[11px] text-slate-400">
                {activeWindow
                  ? `Ventana consultada: ${activeWindow.from} a ${activeWindow.to}`
                  : 'Sin rango explícito se consulta la ventana por defecto del módulo.'}
              </p>
              <div className="flex gap-2">
                <Button
                  label="Limpiar"
                  icon="pi pi-eraser"
                  onClick={clearFilters}
                  disabled={loading}
                  className="cursor-pointer rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-medium text-slate-600 hover:bg-slate-50"
                  pt={{ root: { className: 'border border-slate-200' } }}
                />
                <Button
                  label="Aplicar Filtros"
                  icon="pi pi-search"
                  onClick={() => applyFilters()}
                  disabled={loading}
                  className="cursor-pointer rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-500"
                  pt={{ root: { className: 'border-0' } }}
                />
              </div>
            </div>
          </div>
        )}

        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
          <DataTable
            value={logs}
            loading={loading}
            size="small"
            stripedRows
            /*
             * Server-driven paging: `lazy` tells PrimeReact that `value` is a
             * single page, so it renders the footer from `totalRecords` and
             * hands paging back through `onPage` instead of slicing an array
             * it does not have.
             */
            lazy
            paginator
            first={page * perPage}
            rows={perPage}
            totalRecords={totalRecords}
            onPage={onPage}
            rowsPerPageOptions={ROWS_PER_PAGE}
            paginatorTemplate="FirstPageLink PrevPageLink CurrentPageReport NextPageLink LastPageLink RowsPerPageDropdown"
            currentPageReportTemplate="{first}–{last} de {totalRecords}"
            emptyMessage="No hay registros que coincidan con los filtros."
            sortField="created_at"
            sortOrder={sortOrder === 'asc' ? 1 : -1}
            onSort={onSort}
            onRowClick={(e) => setDetail(e.data)}
            rowClassName={() => 'cursor-pointer'}
            className={`text-sm ${STACK_CLASS}`}
            {...STACK_TABLE}
          >
            <Column header="Fecha" body={dateTemplate} sortable sortField="created_at" style={{ width: '180px' }} />
            <Column header="Acción" body={actionTemplate} />
            <Column header="Recurso" body={resourceTemplate} className={HIDE_BELOW.sm} />
            <Column header="Operador" body={actorTemplate} className={HIDE_BELOW.md} />
            <Column header="IP" className={HIDE_BELOW.lg}
              body={(r) => <span className="font-mono text-[11px] text-slate-500">{r.ip_address ?? '—'}</span>} />
            <Column header="Detalle" body={detailTemplate} style={{ width: '90px' }} />
          </DataTable>
        </div>
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
