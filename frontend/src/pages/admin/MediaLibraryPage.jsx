import { useCallback, useEffect, useMemo, useState } from 'react';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { InputText } from 'primereact/inputtext';
import { Dropdown } from 'primereact/dropdown';
import { Button } from 'primereact/button';
import { Tag } from 'primereact/tag';
import { Paginator } from 'primereact/paginator';
import { toast } from 'sonner';
import AppLayout from '../../components/layout/AppLayout';
import MediaPreviewTile from '../../components/media/MediaPreviewTile';
import MediaUploadDialog from '../../components/media/MediaUploadDialog';
import MediaDetailModal from '../../components/media/MediaDetailModal';
import MediaShareDialog from '../../components/media/MediaShareDialog';
import DeleteDialog from '../../components/catalog/DeleteDialog';
import mediaApi from '../../api/media';
import { useAuth } from '../../context/AuthContext';
import { VISIBILITY_LABELS, formatDateTime } from '../../lib/mediaPreview';
import { HIDE_BELOW } from '../../lib/responsive';

const statusOptions = [
  { label: 'Todos', value: null },
  { label: 'Activos', value: 'active' },
  { label: 'Archivados', value: 'inactive' },
];

const categoryOptions = [
  { label: 'Todas las categorías', value: null },
  { label: 'Imágenes', value: 'image' },
  { label: 'Documentos', value: 'document' },
  { label: 'Hojas de cálculo', value: 'spreadsheet' },
  { label: 'Presentaciones', value: 'presentation' },
  { label: 'Comprimidos', value: 'archive' },
  { label: 'Otros', value: 'other' },
];

/**
 * The media library — grid and list, in the shape operators already know from
 * WordPress.
 *
 * The two view modes are not decoration. The grid answers "which image was
 * it?" and the list answers "who uploaded this and how big is it?"; forcing
 * either question through the other layout is what makes a media manager
 * tiring to use. The chosen mode is remembered in localStorage because it is a
 * per-person preference, not shared state.
 */
export default function MediaLibraryPage() {
  const { user } = useAuth();
  const canManage = user?.roles?.some((role) => role === 'admin' || role === 'manager');
  const isAdmin = user?.roles?.includes('admin');

  const [files, setFiles] = useState([]);
  const [loading, setLoading] = useState(true);
  const [view, setView] = useState(() => localStorage.getItem('media:view') ?? 'grid');

  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [category, setCategory] = useState(null);
  const [status, setStatus] = useState(null);
  const [page, setPage] = useState(0);
  const [pagination, setPagination] = useState({ total: 0, per_page: 24 });

  const [activeTypes, setActiveTypes] = useState([]);
  const [uploadOpen, setUploadOpen] = useState(false);
  const [detailId, setDetailId] = useState(null);
  const [shareTarget, setShareTarget] = useState(null);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleting, setDeleting] = useState(false);

  /*
   * The search box drives a server query, so it is debounced: typing
   * "factura" unthrottled fires seven requests, six of which are already stale
   * when they answer, and the last one can lose the race to an earlier one.
   */
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(search);
      setPage(0);
    }, 350);

    return () => clearTimeout(timer);
  }, [search]);

  const fetchFiles = useCallback(async () => {
    setLoading(true);
    try {
      const res = await mediaApi.list({
        search: debouncedSearch || undefined,
        category: category || undefined,
        status: status || undefined,
        page: page + 1,
        per_page: 24,
      });
      setFiles(res.data);
      setPagination(res.metadata.pagination);
    } catch (err) {
      toast.error('Error al cargar la biblioteca', {
        description: err.response?.data?.message,
      });
    } finally {
      setLoading(false);
    }
  }, [debouncedSearch, category, status, page]);

  useEffect(() => { fetchFiles(); }, [fetchFiles]);

  /*
   * The whitelist is loaded once, for the upload dialog's hint strip and its
   * `accept` attribute. It is admin-only on the API, so managers simply get an
   * upload dialog without the hint — the server still enforces the policy, and
   * a 403 here must not become a visible error for a user who did nothing
   * wrong.
   */
  useEffect(() => {
    if (!isAdmin) return;

    mediaApi
      .fileTypes({ status: 'active' })
      .then((res) => setActiveTypes(res.data))
      .catch(() => setActiveTypes([]));
  }, [isAdmin]);

  const changeView = (mode) => {
    setView(mode);
    localStorage.setItem('media:view', mode);
  };

  /*
   * The shared DeleteDialog requires a typed reason before it will confirm,
   * and it hands that text over here. It goes straight into the audit trail:
   * "who deleted it" is only half the question a forensic review asks.
   */
  const handleDelete = async (reason) => {
    setDeleting(true);
    try {
      await mediaApi.remove(deleteTarget.id, reason);
      toast.success('Archivo eliminado. Puedes recuperarlo desde la Papelera.');
      setDeleteTarget(null);
      fetchFiles();
    } catch (err) {
      toast.error('No se pudo eliminar', { description: err.response?.data?.message });
    } finally {
      setDeleting(false);
    }
  };

  const emptyState = useMemo(
    () => (
      <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-6 py-16 text-center">
        <svg className="h-12 w-12 text-slate-300" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z" />
        </svg>
        <p className="text-sm font-medium text-slate-600">
          {debouncedSearch || category || status
            ? 'Ningún archivo coincide con los filtros aplicados.'
            : 'La biblioteca está vacía.'}
        </p>
        {canManage && !debouncedSearch && !category && !status && (
          <Button label="Subir el primer archivo" icon="pi pi-cloud-upload" onClick={() => setUploadOpen(true)} />
        )}
      </div>
    ),
    [debouncedSearch, category, status, canManage],
  );

  // --- List mode templates -------------------------------------------------
  const nameTemplate = (row) => (
    <button
      type="button"
      onClick={() => setDetailId(row.id)}
      className="flex items-center gap-3 text-left"
    >
      <span className="h-10 w-10 shrink-0 overflow-hidden rounded-md border border-slate-200">
        <MediaPreviewTile file={row} size="sm" />
      </span>
      <span>
        <span className="block text-sm font-medium text-slate-900">{row.name}</span>
        <span className="block text-xs text-slate-500">{row.original_name}</span>
      </span>
    </button>
  );

  const statusTemplate = (row) => (
    <Tag
      value={row.is_active ? 'Activo' : 'Archivado'}
      severity={row.is_active ? 'success' : 'secondary'}
      className="text-xs"
    />
  );

  const actionsTemplate = (row) => (
    <div className="flex justify-end gap-1">
      <Button icon="pi pi-eye" size="small" text onClick={() => setDetailId(row.id)} tooltip="Detalles" />
      {canManage && (
        <>
          <Button icon="pi pi-link" size="small" text onClick={() => setShareTarget(row)} tooltip="Compartir" />
          <Button
            icon="pi pi-trash"
            size="small"
            text
            severity="danger"
            onClick={() => setDeleteTarget(row)}
            tooltip="Eliminar"
          />
        </>
      )}
    </div>
  );

  return (
    <AppLayout>
      <div className="space-y-5">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 className="text-xl font-bold text-slate-900 sm:text-2xl">Biblioteca de Medios</h1>
            <p className="text-sm text-slate-500">
              Archivos centralizados en Google Drive, privados y trazables.
            </p>
          </div>
          {canManage && (
            <Button
              label="Subir archivo"
              icon="pi pi-cloud-upload"
              onClick={() => setUploadOpen(true)}
              className="w-full sm:w-auto"
            />
          )}
        </div>

        {/* Filter bar */}
        <div className="flex flex-col gap-2 rounded-xl border border-slate-200 bg-white p-3 sm:flex-row sm:items-center">
          <span className="p-input-icon-left w-full sm:max-w-xs">
            <i className="pi pi-search" />
            <InputText
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Buscar por nombre o texto alternativo…"
              className="w-full"
            />
          </span>
          <Dropdown
            value={category}
            options={categoryOptions}
            onChange={(e) => { setCategory(e.value); setPage(0); }}
            className="w-full sm:w-56"
          />
          <Dropdown
            value={status}
            options={statusOptions}
            onChange={(e) => { setStatus(e.value); setPage(0); }}
            className="w-full sm:w-40"
          />
          <div className="ml-auto flex shrink-0 gap-1 rounded-lg bg-slate-100 p-1">
            <button
              type="button"
              onClick={() => changeView('grid')}
              aria-label="Vista de cuadrícula"
              aria-pressed={view === 'grid'}
              className={`flex h-9 w-9 items-center justify-center rounded-md transition-colors ${
                view === 'grid' ? 'bg-white text-indigo-600 shadow-sm' : 'text-slate-500 hover:text-slate-700'
              }`}
            >
              <i className="pi pi-th-large text-sm" />
            </button>
            <button
              type="button"
              onClick={() => changeView('list')}
              aria-label="Vista de lista"
              aria-pressed={view === 'list'}
              className={`flex h-9 w-9 items-center justify-center rounded-md transition-colors ${
                view === 'list' ? 'bg-white text-indigo-600 shadow-sm' : 'text-slate-500 hover:text-slate-700'
              }`}
            >
              <i className="pi pi-list text-sm" />
            </button>
          </div>
        </div>

        {loading ? (
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            {Array.from({ length: 10 }).map((_, index) => (
              <div key={index} className="aspect-square animate-pulse rounded-xl bg-slate-100" />
            ))}
          </div>
        ) : files.length === 0 ? (
          emptyState
        ) : view === 'grid' ? (
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            {files.map((file) => (
              <div
                key={file.id}
                className={`group relative overflow-hidden rounded-xl border bg-white transition-shadow hover:shadow-md ${
                  file.is_active ? 'border-slate-200' : 'border-slate-200 opacity-60'
                }`}
              >
                <button
                  type="button"
                  onClick={() => setDetailId(file.id)}
                  className="block aspect-square w-full overflow-hidden"
                >
                  <MediaPreviewTile file={file} />
                </button>

                <div className="border-t border-slate-100 p-2">
                  <p className="truncate text-xs font-medium text-slate-800" title={file.name}>
                    {file.name}
                  </p>
                  <div className="mt-1 flex items-center justify-between">
                    <span className="text-[10px] uppercase text-slate-400">{file.extension}</span>
                    <span className="text-[10px] text-slate-400">{file.human_size}</span>
                  </div>
                </div>

                {/* Every file carries a lock badge. It is not ornamental: it is
                    the module's guarantee that nothing here is public in Drive,
                    stated on the object itself. */}
                <span className="absolute left-1.5 top-1.5 flex items-center gap-1 rounded-full bg-slate-900/70 px-1.5 py-0.5 text-[9px] font-medium text-white backdrop-blur-sm">
                  <i className="pi pi-lock text-[8px]" />
                  {VISIBILITY_LABELS[file.visibility] ?? 'Privado'}
                </span>

                {file.active_share_links_count > 0 && (
                  <span className="absolute right-1.5 top-1.5 rounded-full bg-amber-500 px-1.5 py-0.5 text-[9px] font-semibold text-white">
                    {file.active_share_links_count} enlace(s)
                  </span>
                )}

                {canManage && (
                  <div className="absolute bottom-12 right-1.5 flex gap-1 opacity-0 transition-opacity group-hover:opacity-100 focus-within:opacity-100">
                    <button
                      type="button"
                      onClick={() => setShareTarget(file)}
                      aria-label={`Compartir ${file.name}`}
                      className="flex h-7 w-7 items-center justify-center rounded-full bg-white/90 text-slate-600 shadow-sm hover:text-indigo-600"
                    >
                      <i className="pi pi-link text-xs" />
                    </button>
                    <button
                      type="button"
                      onClick={() => setDeleteTarget(file)}
                      aria-label={`Eliminar ${file.name}`}
                      className="flex h-7 w-7 items-center justify-center rounded-full bg-white/90 text-slate-600 shadow-sm hover:text-rose-600"
                    >
                      <i className="pi pi-trash text-xs" />
                    </button>
                  </div>
                )}
              </div>
            ))}
          </div>
        ) : (
          <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <DataTable value={files} size="small" className="text-sm">
              <Column header="Archivo" body={nameTemplate} />
              <Column field="extension" header="Tipo" className={HIDE_BELOW.sm} body={(r) => r.extension?.toUpperCase()} />
              <Column field="human_size" header="Tamaño" className={HIDE_BELOW.sm} />
              <Column header="Privacidad" className={HIDE_BELOW.md} body={(r) => VISIBILITY_LABELS[r.visibility] ?? r.visibility} />
              <Column header="Subido por" className={HIDE_BELOW.lg} body={(r) => r.uploaded_by_user?.name ?? '—'} />
              <Column header="Fecha" className={HIDE_BELOW.lg} body={(r) => formatDateTime(r.created_at)} />
              <Column header="Estatus" body={statusTemplate} />
              <Column header="" body={actionsTemplate} />
            </DataTable>
          </div>
        )}

        {pagination.total > pagination.per_page && (
          <Paginator
            first={page * pagination.per_page}
            rows={pagination.per_page}
            totalRecords={pagination.total}
            onPageChange={(e) => setPage(e.page)}
          />
        )}
      </div>

      <MediaUploadDialog
        visible={uploadOpen}
        onHide={() => setUploadOpen(false)}
        onUploaded={fetchFiles}
        activeTypes={activeTypes}
      />

      <MediaDetailModal
        visible={detailId !== null}
        fileId={detailId}
        onHide={() => setDetailId(null)}
        onChanged={fetchFiles}
        canManage={canManage}
      />

      <MediaShareDialog
        visible={shareTarget !== null}
        file={shareTarget}
        onHide={() => { setShareTarget(null); fetchFiles(); }}
      />

      <DeleteDialog
        visible={deleteTarget !== null}
        onHide={() => setDeleteTarget(null)}
        onConfirm={handleDelete}
        loading={deleting}
        itemName={deleteTarget?.name}
      />
    </AppLayout>
  );
}
