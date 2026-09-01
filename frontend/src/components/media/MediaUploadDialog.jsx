import { useCallback, useRef, useState } from 'react';
import { Dialog } from 'primereact/dialog';
import { Button } from 'primereact/button';
import { InputText } from 'primereact/inputtext';
import { InputTextarea } from 'primereact/inputtextarea';
import { ProgressBar } from 'primereact/progressbar';
import { Tag } from 'primereact/tag';
import { toast } from 'sonner';
import mediaApi from '../../api/media';
import { humanBytes } from '../../lib/mediaPreview';
import { dialogClass, DIALOG_PT } from '../../lib/responsive';

const emptyMeta = { name: '', alt_text: '', description: '' };

/**
 * Upload dialog, with drag and drop.
 *
 * WHAT IT DELIBERATELY DOES NOT DO: pre-filter by extension. The `accept`
 * attribute is built from the ACTIVE types the backend reported, so the file
 * picker is helpful, but nothing here decides whether a file is acceptable —
 * that verdict belongs to `allowed_file_types` and is issued by the server on
 * every single upload. A client-side gate would be a second source of truth,
 * and it would drift the moment an administrator disabled a type.
 *
 * The consequence is that rejections come back as HTTP 422 with a reason code,
 * and this dialog's job is to render them clearly enough that the operator
 * knows whether to ask an administrator, pick another file, or shrink this one.
 */
export default function MediaUploadDialog({ visible, onHide, onUploaded, activeTypes = [] }) {
  const [file, setFile] = useState(null);
  const [meta, setMeta] = useState(emptyMeta);
  const [progress, setProgress] = useState(0);
  const [uploading, setUploading] = useState(false);
  const [rejection, setRejection] = useState(null);
  const [dragging, setDragging] = useState(false);
  const inputRef = useRef(null);

  const accept = activeTypes.map((t) => `.${t.extension}`).join(',');

  const reset = useCallback(() => {
    setFile(null);
    setMeta(emptyMeta);
    setProgress(0);
    setRejection(null);
    setDragging(false);
    if (inputRef.current) inputRef.current.value = '';
  }, []);

  const close = () => {
    if (uploading) return;
    reset();
    onHide();
  };

  const pick = (picked) => {
    if (!picked) return;
    setRejection(null);
    setFile(picked);
    // The library name defaults to the file name without its extension — the
    // same default WordPress uses, and the one operators expect.
    setMeta((prev) => ({ ...prev, name: picked.name.replace(/\.[^.]+$/, '') }));
  };

  const handleDrop = (event) => {
    event.preventDefault();
    setDragging(false);
    pick(event.dataTransfer.files?.[0]);
  };

  const handleUpload = async () => {
    if (!file) return;

    setUploading(true);
    setProgress(0);
    setRejection(null);

    const formData = new FormData();
    formData.append('file', file);
    if (meta.name) formData.append('name', meta.name);
    if (meta.alt_text) formData.append('alt_text', meta.alt_text);
    if (meta.description) formData.append('description', meta.description);

    try {
      const result = await mediaApi.upload(formData, setProgress);
      toast.success('Archivo subido', { description: result.metadata?.message });
      reset();
      onUploaded?.(result.data);
      onHide();
    } catch (err) {
      const data = err.response?.data;

      /*
       * The three rejection codes are surfaced verbatim inside the dialog
       * rather than as a toast that vanishes. Each one asks a different thing
       * of the operator — talk to an administrator, respect a policy in force,
       * or shrink the file — and a message they can re-read while looking at
       * the file is what makes that actionable.
       */
      if (data?.code?.startsWith('ERR_MEDIA_')) {
        setRejection({ code: data.code, message: data.message });
        toast.error('Subida rechazada', { description: data.message });
      } else {
        toast.error('Error al subir', {
          description: data?.message || 'No se pudo completar la subida.',
        });
      }
    } finally {
      setUploading(false);
    }
  };

  return (
    <Dialog
      header="Subir archivo a la biblioteca"
      visible={visible}
      onHide={close}
      className={dialogClass('lg')}
      pt={DIALOG_PT}
      draggable={false}
    >
      <div className="space-y-4">
        {/* Drop zone. The click target is the whole surface, not just a
            button, because that is the affordance users already expect from
            every media library they have used. */}
        <div
          onDragOver={(e) => { e.preventDefault(); setDragging(true); }}
          onDragLeave={() => setDragging(false)}
          onDrop={handleDrop}
          onClick={() => !uploading && inputRef.current?.click()}
          className={`flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-4 py-10 text-center transition-colors ${
            dragging
              ? 'border-indigo-500 bg-indigo-50'
              : 'border-slate-300 bg-slate-50 hover:border-indigo-400 hover:bg-indigo-50/50'
          }`}
        >
          <svg className="h-10 w-10 text-slate-400" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 7.5 7.5 12M12 7.5v12" />
          </svg>
          <p className="text-sm font-medium text-slate-700">
            {file ? file.name : 'Arrastra un archivo o haz clic para seleccionarlo'}
          </p>
          <p className="text-xs text-slate-500">
            {file
              ? humanBytes(file.size)
              : 'El sistema valida el tipo y el tamaño contra el catálogo de tipos permitidos.'}
          </p>
          <input
            ref={inputRef}
            type="file"
            accept={accept || undefined}
            className="hidden"
            onChange={(e) => pick(e.target.files?.[0])}
          />
        </div>

        {/* Live copy of the policy in force, so the operator sees what is
            accepted right now instead of discovering it through a rejection. */}
        {activeTypes.length > 0 && (
          <div className="flex flex-wrap gap-1.5">
            {activeTypes.map((type) => (
              <Tag
                key={type.id}
                value={`.${type.extension} · ${type.human_max_size ?? `${type.effective_max_size_kb} KB`}`}
                severity="secondary"
                className="text-[10px]"
              />
            ))}
          </div>
        )}

        {rejection && (
          <div className="rounded-lg border border-rose-200 bg-rose-50 p-3">
            <p className="text-sm font-semibold text-rose-800">Subida rechazada</p>
            <p className="mt-1 text-sm text-rose-700">{rejection.message}</p>
            <p className="mt-1 font-mono text-[11px] text-rose-500">{rejection.code}</p>
          </div>
        )}

        {file && (
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="sm:col-span-2">
              <label className="mb-1 block text-xs font-semibold text-slate-600">Nombre en la biblioteca</label>
              <InputText
                value={meta.name}
                onChange={(e) => setMeta({ ...meta, name: e.target.value })}
                className="w-full"
                disabled={uploading}
              />
            </div>
            <div className="sm:col-span-2">
              <label className="mb-1 block text-xs font-semibold text-slate-600">
                Texto alternativo <span className="font-normal text-slate-400">(accesibilidad)</span>
              </label>
              <InputText
                value={meta.alt_text}
                onChange={(e) => setMeta({ ...meta, alt_text: e.target.value })}
                className="w-full"
                disabled={uploading}
              />
            </div>
            <div className="sm:col-span-2">
              <label className="mb-1 block text-xs font-semibold text-slate-600">Descripción</label>
              <InputTextarea
                value={meta.description}
                onChange={(e) => setMeta({ ...meta, description: e.target.value })}
                rows={3}
                className="w-full"
                disabled={uploading}
                autoResize
              />
            </div>
          </div>
        )}

        {uploading && (
          <div>
            <ProgressBar value={progress} showValue className="h-2" />
            <p className="mt-1.5 text-xs text-slate-500">
              {progress < 100
                ? 'Transfiriendo al servidor…'
                : 'Validando el tipo y asegurando los permisos en Google Drive…'}
            </p>
          </div>
        )}

        <div className="flex flex-col-reverse gap-2 border-t border-slate-100 pt-4 sm:flex-row sm:justify-end">
          <Button label="Cancelar" outlined onClick={close} disabled={uploading} className="w-full sm:w-auto" />
          <Button
            label="Subir archivo"
            icon="pi pi-cloud-upload"
            onClick={handleUpload}
            loading={uploading}
            disabled={!file || uploading}
            className="w-full sm:w-auto"
          />
        </div>
      </div>
    </Dialog>
  );
}
