import { useCallback, useMemo, useRef, useState } from 'react';
import { Dialog } from 'primereact/dialog';
import { Button } from 'primereact/button';
import { InputText } from 'primereact/inputtext';
import { InputTextarea } from 'primereact/inputtextarea';
import { ProgressBar } from 'primereact/progressbar';
import { toast } from 'sonner';
import mediaApi from '../../api/media';
import { humanBytes } from '../../lib/mediaPreview';
import { dialogClass, DIALOG_PT } from '../../lib/responsive';
import { baseNameOf, checkPolicy, extensionOf, isEditableImage } from '../../lib/mediaEditing';
import MediaFileEditor from './MediaFileEditor';

const emptyMeta = { name: '', alt_text: '', description: '' };

/**
 * Upload dialog: a workbench on the left, the library record on the right.
 *
 * WHERE THE VALIDATION LIVES, AND WHY IT IS INVISIBLE. The server is still the
 * only authority on what may be stored: `allowed_file_types` is re-checked on
 * every request, magic bytes included, and nothing decided in this component
 * can widen it. What changed is that the policy is no longer DISPLAYED. The
 * dialog used to open with a strip of pills listing every enabled extension and
 * its size ceiling — accurate, and noise for the ninety-nine uploads out of a
 * hundred that were going to be accepted anyway. It also read as a set of
 * instructions the operator had to satisfy before being allowed to proceed,
 * which is not how the module works: they pick a file, and the system either
 * takes it or explains itself.
 *
 * So the rules now run silently — the file picker still filters by the active
 * extensions, and a file that cannot be stored is refused the moment it is
 * picked, with the reason and nothing else. A user who never picks a bad file
 * never learns the policy exists, which is the correct amount of attention to
 * charge them for it.
 *
 * WHY THE CHECK IS DUPLICATED CLIENT-SIDE AT ALL. Not to make the verdict
 * faster: to make the editor possible. A crop tool that can shrink a file needs
 * to know the ceiling it is aiming at, and discovering a 40 MB photo was too
 * large only after uploading it is the round trip the whole panel exists to
 * remove. See lib/mediaEditing.js for how the duplication is bounded.
 */
export default function MediaUploadDialog({ visible, onHide, onUploaded, activeTypes = [] }) {
  const [file, setFile] = useState(null);
  // The editor's output, when the operator applied one. Kept beside the source
  // rather than replacing it so "Restablecer" is a real undo and not a second
  // trip through the file picker.
  const [editedFile, setEditedFile] = useState(null);
  const [meta, setMeta] = useState(emptyMeta);
  const [progress, setProgress] = useState(0);
  const [uploading, setUploading] = useState(false);
  const [rejection, setRejection] = useState(null);
  const [dragging, setDragging] = useState(false);
  const inputRef = useRef(null);

  const accept = useMemo(
    () => activeTypes.map((type) => `.${type.extension}`).join(','),
    [activeTypes],
  );

  // What will actually be posted: the derivative when there is one.
  const outgoing = editedFile ?? file;

  const reset = useCallback(() => {
    setFile(null);
    setEditedFile(null);
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

  /**
   * Accepts a picked file, or refuses it before anything else happens.
   *
   * A refusal clears the previous selection on purpose. Leaving the last valid
   * file loaded under an error about a different one is how somebody uploads
   * the wrong thing while believing they fixed it.
   */
  const pick = (picked) => {
    if (!picked) return;

    const verdict = checkPolicy(picked, activeTypes);

    if (verdict) {
      setFile(null);
      setEditedFile(null);
      setRejection(verdict);
      toast.error('Archivo no admitido', { description: verdict.message });
      if (inputRef.current) inputRef.current.value = '';

      return;
    }

    setRejection(null);
    setFile(picked);
    setEditedFile(null);
    // The library name defaults to the file name without its extension — the
    // same default WordPress uses, and the one operators expect.
    setMeta((prev) => ({ ...prev, name: baseNameOf(picked.name) }));
  };

  const handleDrop = (event) => {
    event.preventDefault();
    setDragging(false);
    pick(event.dataTransfer.files?.[0]);
  };

  const handleUpload = async () => {
    if (!outgoing) return;

    setUploading(true);
    setProgress(0);
    setRejection(null);

    const formData = new FormData();
    formData.append('file', outgoing);
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
       * A server rejection is still surfaced inside the dialog rather than as a
       * toast that vanishes. It reaches this branch only when the local check
       * could not have caught it — a type disabled between the catalogue load
       * and the upload, or a file whose magic bytes contradict its extension —
       * and those are precisely the cases the operator has to re-read to
       * understand.
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
      className={dialogClass('xl')}
      pt={DIALOG_PT}
      draggable={false}
    >
      {/* The workbench is wide on a desktop and stacked on a phone: the editor
          needs room the metadata form does not, and a two-column layout below
          1024px would give neither enough. */}
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div className="min-w-0 space-y-3">
          {/* Drop zone. The click target is the whole surface, not just a
              button, because that is the affordance users already expect from
              every media library they have used. It collapses to a single line
              once a file is loaded, so the editor keeps the space. */}
          <div
            onDragOver={(e) => { e.preventDefault(); setDragging(true); }}
            onDragLeave={() => setDragging(false)}
            onDrop={handleDrop}
            onClick={() => !uploading && inputRef.current?.click()}
            className={`flex cursor-pointer items-center justify-center gap-3 rounded-xl border-2 border-dashed text-center transition-colors ${
              file ? 'px-4 py-3' : 'flex-col px-4 py-10'
            } ${
              dragging
                ? 'border-indigo-500 bg-indigo-50'
                : 'border-slate-300 bg-slate-50 hover:border-indigo-400 hover:bg-indigo-50/50'
            }`}
          >
            <svg
              className={`text-slate-400 ${file ? 'h-5 w-5' : 'h-10 w-10'}`}
              fill="none"
              viewBox="0 0 24 24"
              strokeWidth={1.5}
              stroke="currentColor"
            >
              <path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 7.5 7.5 12M12 7.5v12" />
            </svg>
            <div className={file ? 'min-w-0 text-left' : ''}>
              <p className="truncate text-sm font-medium text-slate-700">
                {file ? file.name : 'Arrastra un archivo o haz clic para seleccionarlo'}
              </p>
              <p className="text-xs text-slate-500">
                {file
                  ? `${humanBytes(file.size)} · haz clic para reemplazarlo`
                  : 'Previsualiza, recorta y optimiza antes de enviarlo.'}
              </p>
            </div>
            <input
              ref={inputRef}
              type="file"
              accept={accept || undefined}
              className="hidden"
              onChange={(e) => pick(e.target.files?.[0])}
            />
          </div>

          <MediaFileEditor
            file={file}
            edited={editedFile}
            activeTypes={activeTypes}
            disabled={uploading}
            onEdited={setEditedFile}
          />
        </div>

        {/* --- Library record ------------------------------------------- */}
        <div className="flex min-w-0 flex-col gap-3">
          {rejection && (
            <div className="rounded-lg border border-rose-200 bg-rose-50 p-3">
              <p className="text-sm font-semibold text-rose-800">Archivo no admitido</p>
              <p className="mt-1 text-sm text-rose-700">{rejection.message}</p>
              <p className="mt-1 font-mono text-[11px] text-rose-500">{rejection.code}</p>
            </div>
          )}

          {outgoing && (
            <>
              <dl className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs">
                <div className="flex items-baseline justify-between gap-2 py-0.5">
                  <dt className="text-slate-500">Formato</dt>
                  <dd className="font-mono font-semibold text-slate-700">.{extensionOf(outgoing.name)}</dd>
                </div>
                <div className="flex items-baseline justify-between gap-2 py-0.5">
                  <dt className="text-slate-500">Peso a enviar</dt>
                  <dd className="font-semibold text-slate-700">{humanBytes(outgoing.size)}</dd>
                </div>
                {editedFile && (
                  /* Only shown once an edit exists, because "original" has no
                     meaning while the two are the same file. */
                  <div className="flex items-baseline justify-between gap-2 py-0.5">
                    <dt className="text-slate-500">Original</dt>
                    <dd className="text-slate-500 line-through">{humanBytes(file.size)}</dd>
                  </div>
                )}
                {isEditableImage(file) && !editedFile && (
                  <p className="mt-1.5 text-[11px] text-slate-400">
                    Se enviará la imagen tal como se seleccionó. Aplica una edición para recortarla o
                    reducir su peso.
                  </p>
                )}
              </dl>

              <div>
                <label className="mb-1 block text-xs font-semibold text-slate-600">
                  Nombre en la biblioteca
                </label>
                <InputText
                  value={meta.name}
                  onChange={(e) => setMeta({ ...meta, name: e.target.value })}
                  className="w-full"
                  disabled={uploading}
                />
              </div>
              <div>
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
              <div>
                <label className="mb-1 block text-xs font-semibold text-slate-600">Descripción</label>
                <InputTextarea
                  value={meta.description}
                  onChange={(e) => setMeta({ ...meta, description: e.target.value })}
                  rows={4}
                  className="w-full"
                  disabled={uploading}
                  autoResize
                />
              </div>
            </>
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

          <div className="mt-auto flex flex-col-reverse gap-2 border-t border-slate-100 pt-4 sm:flex-row sm:justify-end lg:flex-col-reverse">
            <Button label="Cancelar" outlined onClick={close} disabled={uploading} className="w-full sm:w-auto lg:w-full" />
            <Button
              label="Subir archivo"
              icon="pi pi-cloud-upload"
              onClick={handleUpload}
              loading={uploading}
              disabled={!outgoing || uploading}
              className="w-full sm:w-auto lg:w-full"
            />
          </div>
        </div>
      </div>
    </Dialog>
  );
}
