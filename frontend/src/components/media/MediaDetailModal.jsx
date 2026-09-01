import { useCallback, useEffect, useState } from 'react';
import { Dialog } from 'primereact/dialog';
import { Button } from 'primereact/button';
import { InputText } from 'primereact/inputtext';
import { InputTextarea } from 'primereact/inputtextarea';
import { InputSwitch } from 'primereact/inputswitch';
import { Tag } from 'primereact/tag';
import { toast } from 'sonner';
import mediaApi from '../../api/media';
import MediaPreviewTile from './MediaPreviewTile';
import { embeddableInDetail, VISIBILITY_LABELS, formatDateTime } from '../../lib/mediaPreview';
import { dialogClass, DIALOG_PT } from '../../lib/responsive';

/**
 * The WordPress-style attachment details modal.
 *
 * Left: the preview, at the largest size the layout allows. Right: the
 * editable metadata, then the immutable facts of the object.
 *
 * The split between the two right-hand blocks is the point. Name, alt text,
 * description and status describe how the ORGANIZATION uses the file and are
 * freely editable. Extension, MIME type, size, checksum, dimensions and Drive
 * id describe the BYTES, are read-only, and are shown precisely because an
 * operator investigating an incident needs them — a checksum that no longer
 * matches is how a swapped file is caught.
 */
export default function MediaDetailModal({ visible, onHide, fileId, onChanged, canManage }) {
  const [file, setFile] = useState(null);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState({ name: '', alt_text: '', description: '', is_active: true });
  const [embedUrl, setEmbedUrl] = useState(null);
  const [hardening, setHardening] = useState(false);

  const fetchFile = useCallback(async () => {
    if (!fileId) return;

    setLoading(true);
    try {
      const data = await mediaApi.show(fileId);
      setFile(data);
      setForm({
        name: data.name ?? '',
        alt_text: data.alt_text ?? '',
        description: data.description ?? '',
        is_active: data.is_active,
      });
    } catch {
      toast.error('No se pudo cargar el archivo.');
    } finally {
      setLoading(false);
    }
  }, [fileId]);

  useEffect(() => {
    if (visible) fetchFile();
  }, [visible, fetchFile]);

  /*
   * The embedded preview is fetched only for the kinds a browser can render
   * natively, and only while the modal is open. The object URL is released on
   * close: a modal reopened twenty times during a review would otherwise pin
   * twenty copies of the file in memory.
   */
  const embedId = file?.id;
  const isEmbeddable = file ? embeddableInDetail(file) : false;

  useEffect(() => {
    if (!visible || !embedId || !isEmbeddable) {
      return undefined;
    }

    let released = false;
    let url = null;

    mediaApi
      .contentUrl(embedId)
      .then((created) => {
        if (released) {
          URL.revokeObjectURL(created);
          return;
        }
        url = created;
        setEmbedUrl(created);
      })
      .catch(() => setEmbedUrl(null));

    return () => {
      released = true;
      setEmbedUrl(null);
      if (url) URL.revokeObjectURL(url);
    };
  }, [visible, embedId, isEmbeddable]);

  const handleSave = async () => {
    setSaving(true);
    try {
      const res = await mediaApi.update(file.id, form);
      toast.success('Metadatos actualizados.');
      setFile(res.data);
      onChanged?.();
    } catch (err) {
      toast.error('No se pudo guardar', {
        description: err.response?.data?.message || 'Verifica los campos.',
      });
    } finally {
      setSaving(false);
    }
  };

  const handleDownload = async () => {
    try {
      const url = await mediaApi.contentUrl(file.id, { download: true });
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = `${file.name}.${file.extension}`;
      anchor.click();
      // The anchor is transient; the object URL behind it is not. Releasing it
      // on the next tick gives the browser time to start the save.
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch {
      toast.error('No se pudo descargar el archivo.');
    }
  };

  /**
   * Re-applies the module's privacy contract in Drive.
   *
   * Offered as an explicit action because permissions can be changed from
   * OUTSIDE the POS: anybody with folder access can click "share" in the Drive
   * interface and the object silently becomes readable by anyone with the link.
   * A non-zero count in the result is an incident, not a routine outcome, so
   * the toast says so.
   */
  const handleReapplyPermissions = async () => {
    setHardening(true);
    try {
      const res = await mediaApi.reapplyPermissions(file.id);
      const removed = res.data?.removed ?? 0;

      if (removed > 0) {
        toast.warning('Se detectaron permisos públicos', { description: res.metadata?.message });
      } else {
        toast.success('Permisos verificados', { description: res.metadata?.message });
      }

      fetchFile();
      onChanged?.();
    } catch (err) {
      toast.error('No se pudieron revisar los permisos', {
        description: err.response?.data?.message,
      });
    } finally {
      setHardening(false);
    }
  };

  const factRow = (label, value) => (
    <div className="flex items-start justify-between gap-3 border-b border-slate-100 py-1.5 last:border-0">
      <span className="shrink-0 text-xs text-slate-500">{label}</span>
      <span className="text-right text-xs font-medium break-all text-slate-800">{value ?? '—'}</span>
    </div>
  );

  return (
    <Dialog
      header="Detalles del archivo"
      visible={visible}
      onHide={onHide}
      className={dialogClass('xl')}
      pt={DIALOG_PT}
      draggable={false}
    >
      {loading || !file ? (
        <div className="flex h-64 items-center justify-center">
          <div className="h-8 w-8 animate-spin rounded-full border-4 border-indigo-600 border-t-transparent" />
        </div>
      ) : (
        <div className="grid gap-6 lg:grid-cols-5">
          {/* Preview column */}
          <div className="lg:col-span-2">
            <div className="overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
              {embedUrl && file.preview_kind === 'image' && (
                <img src={embedUrl} alt={file.alt_text || file.name} className="max-h-[26rem] w-full object-contain" />
              )}
              {embedUrl && file.preview_kind === 'pdf' && (
                <iframe
                  src={embedUrl}
                  title={file.name}
                  className="h-[26rem] w-full border-0"
                  /* The bytes come from a user upload. The sandbox keeps a
                     crafted PDF from running script or navigating the parent
                     window inside this application's origin. */
                  sandbox=""
                />
              )}
              {!embeddableInDetail(file) && (
                <div className="flex h-64 items-center justify-center">
                  <MediaPreviewTile file={file} size="lg" />
                </div>
              )}
            </div>

            <div className="mt-3 flex flex-wrap gap-2">
              <Button
                label="Descargar"
                icon="pi pi-download"
                size="small"
                outlined
                onClick={handleDownload}
              />
              {canManage && (
                <Button
                  label="Revisar permisos en Drive"
                  icon="pi pi-shield"
                  size="small"
                  severity="secondary"
                  outlined
                  loading={hardening}
                  onClick={handleReapplyPermissions}
                />
              )}
            </div>
          </div>

          {/* Metadata column */}
          <div className="space-y-4 lg:col-span-3">
            <div className="flex flex-wrap items-center gap-2">
              <Tag value={file.extension?.toUpperCase()} severity="info" className="text-xs" />
              <Tag
                value={VISIBILITY_LABELS[file.visibility] ?? file.visibility}
                severity="success"
                className="text-xs"
                icon="pi pi-lock"
              />
              <Tag
                value={file.is_active ? 'Activo' : 'Archivado'}
                severity={file.is_active ? 'success' : 'secondary'}
                className="text-xs"
              />
            </div>

            <div>
              <label className="mb-1 block text-xs font-semibold text-slate-600">Nombre</label>
              <InputText
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
                className="w-full"
                disabled={!canManage}
              />
            </div>

            <div>
              <label className="mb-1 block text-xs font-semibold text-slate-600">
                Texto alternativo <span className="font-normal text-slate-400">(accesibilidad)</span>
              </label>
              <InputText
                value={form.alt_text}
                onChange={(e) => setForm({ ...form, alt_text: e.target.value })}
                className="w-full"
                disabled={!canManage}
              />
            </div>

            <div>
              <label className="mb-1 block text-xs font-semibold text-slate-600">Descripción</label>
              <InputTextarea
                value={form.description}
                onChange={(e) => setForm({ ...form, description: e.target.value })}
                rows={3}
                autoResize
                className="w-full"
                disabled={!canManage}
              />
            </div>

            {canManage && (
              <div className="flex items-center gap-3">
                <InputSwitch
                  checked={form.is_active}
                  onChange={(e) => setForm({ ...form, is_active: e.value })}
                />
                <span className="text-sm text-slate-700">
                  Activo en la biblioteca
                  {!form.is_active && (
                    <span className="ml-1 text-xs text-amber-600">
                      · al archivar se revocan sus enlaces compartidos
                    </span>
                  )}
                </span>
              </div>
            )}

            {/* Immutable facts of the stored object. */}
            <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
              <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                Propiedades del archivo
              </p>
              {factRow('Nombre original', file.original_name)}
              {factRow('Tipo MIME', file.mime_type)}
              {factRow('Tamaño', file.human_size)}
              {factRow('Dimensiones', file.dimensions)}
              {factRow('Checksum SHA-256', file.checksum && (
                <span className="font-mono text-[10px]">{file.checksum}</span>
              ))}
              {factRow('ID en Google Drive', (
                <span className="font-mono text-[10px]">{file.drive_file_id}</span>
              ))}
              {factRow('Subido por', file.uploaded_by_user?.name)}
              {factRow('Fecha de subida', formatDateTime(file.created_at))}
              {factRow('Última modificación', formatDateTime(file.updated_at))}
            </div>

            {canManage && (
              <div className="flex justify-end border-t border-slate-100 pt-3">
                <Button label="Guardar cambios" icon="pi pi-check" onClick={handleSave} loading={saving} />
              </div>
            )}
          </div>
        </div>
      )}
    </Dialog>
  );
}
