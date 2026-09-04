import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Cropper from 'cropperjs';
import 'cropperjs/dist/cropper.css';
import { Button } from 'primereact/button';
import { Dropdown } from 'primereact/dropdown';
import { Slider } from 'primereact/slider';
import { toast } from 'sonner';
import { humanBytes, previewStyle } from '../../lib/mediaPreview';
import {
  OUTPUT_FORMATS,
  SIZE_PRESETS,
  canvasToFile,
  checkPolicy,
  extensionOf,
  isEditableImage,
  localPreviewKind,
  resolveOutput,
} from '../../lib/mediaEditing';

/**
 * The viewer/editor half of the upload dialog.
 *
 * WHAT IT IS FOR. Everything this panel does happens BEFORE a single byte
 * leaves the browser. A photo taken by a phone arrives at 4000 px and eight
 * megabytes; the library will show it at 400. Cropping and re-encoding it here
 * costs one canvas pass on a machine that is already idle, and saves that
 * weight from the upload, from Drive, and from every later download. Doing the
 * same work server-side would mean accepting the eight megabytes first, which
 * is the cost we are trying to avoid.
 *
 * WHY cropper.js AND NOT A HAND-ROLLED CANVAS. Cropping is not the hard part —
 * the drag handles, the pinch and wheel zoom, the rotation that keeps the crop
 * box consistent, and the touch behaviour on a tablet are. That is a library's
 * worth of edge cases, and this module is not the place to reimplement them.
 * The vanilla build is driven directly through a ref instead of through a React
 * wrapper: the wrapper would add a dependency whose React-version support has
 * to be tracked, for an API that is three method calls wide.
 *
 * WHAT IT DOES FOR A DOCUMENT. A PDF is rendered inline in a frame, because a
 * PDF is the one non-image format every browser can already draw and "is this
 * the right invoice?" is the question the operator actually has. A spreadsheet
 * or a text document gets the large typed icon and its metadata instead: the
 * browser cannot render either without shipping a parser for a format the user
 * is about to upload unchanged, and a wrong icon is not what would mislead them
 * — a half-rendered spreadsheet would be.
 */
export default function MediaFileEditor({ file, edited, activeTypes, disabled, onEdited }) {
  const imageRef = useRef(null);
  const cropperRef = useRef(null);

  // 0 means "free". cropper.js encodes that as NaN, which cannot be used as a
  // dropdown value because NaN never equals itself and the control would show
  // no selection at all; the translation happens at the single point of use.
  const [aspectRatio, setAspectRatio] = useState(0);
  const [format, setFormat] = useState('keep');
  const [quality, setQuality] = useState(82);
  const [maxSize, setMaxSize] = useState(0);
  const [working, setWorking] = useState(false);

  const editable = isEditableImage(file);
  const kind = localPreviewKind(file, activeTypes);
  const style = previewStyle({ preview_kind: kind });
  const output = useMemo(() => resolveOutput(file, format), [file, format]);

  // Read when a cropper is constructed, which happens on every new file.
  // Without it a ratio chosen for one file would silently reset to free when
  // the next one loads, and adding the ratio to that effect's dependencies to
  // avoid it would rebuild the cropper — throwing away the user's crop box —
  // every time the ratio changed.
  const aspectRatioRef = useRef(aspectRatio);

  /*
   * One object URL per picked file, revoked when it is replaced or when the
   * dialog closes. An un-revoked blob URL pins the whole file in memory for the
   * lifetime of the document, and a media dialog is opened dozens of times in a
   * shift.
   *
   * Derived rather than held in state: the URL is a pure function of the file,
   * and storing it would make the first render after every pick paint an empty
   * frame before the effect filled it in.
   */
  const previewUrl = useMemo(() => (file ? URL.createObjectURL(file) : null), [file]);

  useEffect(() => () => {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
  }, [previewUrl]);

  /*
   * The cropper is bound to the <img> after the element exists and is torn
   * down on every change of file. The teardown is not optional: React runs
   * effects twice in development, and a second Cropper attached to the same
   * element leaves two sets of drag handles that fight each other.
   */
  useEffect(() => {
    if (!editable || !previewUrl || !imageRef.current) return undefined;

    const cropper = new Cropper(imageRef.current, {
      viewMode: 1,
      autoCropArea: 1,
      background: false,
      responsive: true,
      checkOrientation: true,
      // Dragging the canvas pans the image under the crop box instead of
      // drawing a new one. With the box starting at the full frame, the
      // alternative would mean the first drag silently discards the default
      // selection — the surprising outcome of a gesture meant to reposition.
      dragMode: 'move',
      toggleDragModeOnDblclick: false,
      aspectRatio: aspectRatioRef.current || NaN,
    });

    cropperRef.current = cropper;

    return () => {
      cropper.destroy();
      cropperRef.current = null;
    };
  }, [editable, previewUrl]);

  useEffect(() => {
    aspectRatioRef.current = aspectRatio;
    cropperRef.current?.setAspectRatio(aspectRatio || NaN);
  }, [aspectRatio]);

  /** Applies the current crop, rotation, scale and export settings. */
  const applyEdit = useCallback(async () => {
    const cropper = cropperRef.current;

    if (!cropper) return;

    setWorking(true);

    try {
      const data = cropper.getData(true);
      let width = Math.max(1, Math.round(data.width));
      let height = Math.max(1, Math.round(data.height));

      /*
       * The downscale is computed here rather than handed to getCroppedCanvas
       * as maxWidth/maxHeight: those two clamp each axis independently, so a
       * panoramic crop comes back squashed instead of smaller. Scaling both
       * axes by one factor is the only way the aspect ratio survives.
       */
      if (maxSize > 0 && Math.max(width, height) > maxSize) {
        const scale = maxSize / Math.max(width, height);
        width = Math.max(1, Math.round(width * scale));
        height = Math.max(1, Math.round(height * scale));
      }

      const canvas = cropper.getCroppedCanvas({
        width,
        height,
        imageSmoothingEnabled: true,
        imageSmoothingQuality: 'high',
        // A lossy format has no alpha channel, and an unfilled canvas exported
        // to JPEG turns every transparent pixel black. Filling white first is
        // what a logo with a transparent background needs to survive.
        ...(output.lossy ? { fillColor: '#ffffff' } : {}),
      });

      const result = await canvasToFile(canvas, file, {
        mime: output.mime,
        extension: output.extension,
        quality: output.lossy ? quality / 100 : undefined,
      });

      /*
       * The edited file is re-validated before it is accepted. Changing the
       * export format changes the extension, and an extension the catalogue
       * does not carry would be rejected by the server after the upload — the
       * exact round trip this panel exists to avoid.
       */
      const rejection = checkPolicy(result, activeTypes);

      if (rejection) {
        toast.error('La edición no se puede subir', { description: rejection.message });

        return;
      }

      onEdited(result);
      toast.success('Edición aplicada', {
        description: `${width} × ${height} px · ${humanBytes(result.size)}`,
      });
    } catch (error) {
      toast.error('No se pudo procesar la imagen', { description: error.message });
    } finally {
      setWorking(false);
    }
  }, [activeTypes, file, maxSize, onEdited, output, quality]);

  const revert = useCallback(() => {
    cropperRef.current?.reset();
    onEdited(null);
  }, [onEdited]);

  if (!file) {
    return (
      <div className="flex h-56 flex-col items-center justify-center rounded-xl border border-dashed border-slate-200 bg-slate-50 p-6 text-center">
        <i className="pi pi-image text-3xl text-slate-300" />
        <p className="mt-2 text-sm text-slate-500">
          Selecciona un archivo para previsualizarlo y editarlo antes de subirlo.
        </p>
      </div>
    );
  }

  return (
    <div className="flex h-full flex-col gap-3">
      {/* --- Surface ---------------------------------------------------- */}
      {editable ? (
        <div className="overflow-hidden rounded-xl bg-slate-900">
          <div className="h-[38vh] min-h-[16rem] w-full">
            {/* cropper.js measures this element's parent, so the height above
                is what defines the workspace. */}
            <img ref={imageRef} src={previewUrl} alt={file.name} className="block max-w-full" />
          </div>
        </div>
      ) : kind === 'pdf' ? (
        /*
         * <object> rather than <iframe> for one reason: it renders its children
         * when the browser cannot display the resource. A browser with no
         * built-in PDF viewer shows an empty iframe and no way to tell that
         * from a blank first page, whereas here it falls back to the same
         * identity card every other document gets.
         */
        <object
          data={previewUrl}
          type="application/pdf"
          aria-label={file.name}
          className="h-[38vh] min-h-[16rem] w-full overflow-hidden rounded-xl border border-slate-200 bg-slate-50"
        >
          <FileIdentity file={file} style={style} />
        </object>
      ) : (
        <FileIdentity file={file} style={style} />
      )}

      {/* --- Tools ------------------------------------------------------ */}
      {editable && (
        <div className="space-y-3 rounded-xl border border-slate-200 bg-white p-3">
          <div className="flex flex-wrap items-center gap-1.5">
            <Button
              type="button"
              icon="pi pi-undo"
              tooltip="Girar 90° a la izquierda"
              tooltipOptions={{ position: 'top' }}
              outlined
              size="small"
              disabled={disabled}
              onClick={() => cropperRef.current?.rotate(-90)}
            />
            <Button
              type="button"
              icon="pi pi-refresh"
              tooltip="Girar 90° a la derecha"
              tooltipOptions={{ position: 'top' }}
              outlined
              size="small"
              disabled={disabled}
              onClick={() => cropperRef.current?.rotate(90)}
            />
            <Button
              type="button"
              icon="pi pi-arrows-h"
              tooltip="Reflejar horizontalmente"
              tooltipOptions={{ position: 'top' }}
              outlined
              size="small"
              disabled={disabled}
              onClick={() => cropperRef.current?.scaleX(-(cropperRef.current?.getData().scaleX || 1))}
            />
            <Button
              type="button"
              icon="pi pi-arrows-v"
              tooltip="Reflejar verticalmente"
              tooltipOptions={{ position: 'top' }}
              outlined
              size="small"
              disabled={disabled}
              onClick={() => cropperRef.current?.scaleY(-(cropperRef.current?.getData().scaleY || 1))}
            />
            <Button
              type="button"
              icon="pi pi-eraser"
              label="Restablecer"
              outlined
              size="small"
              disabled={disabled}
              onClick={revert}
              className="ml-auto"
            />
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <label className="mb-1 block text-[11px] font-semibold text-slate-600">Proporción</label>
              <Dropdown
                value={aspectRatio}
                onChange={(e) => setAspectRatio(e.value)}
                options={ASPECT_RATIOS}
                optionLabel="label"
                optionValue="value"
                className="w-full"
                disabled={disabled}
              />
            </div>
            <div>
              <label className="mb-1 block text-[11px] font-semibold text-slate-600">Resolución</label>
              <Dropdown
                value={maxSize}
                onChange={(e) => setMaxSize(e.value)}
                options={SIZE_PRESETS}
                optionLabel="label"
                optionValue="value"
                className="w-full"
                disabled={disabled}
              />
            </div>
            <div>
              <label className="mb-1 block text-[11px] font-semibold text-slate-600">Formato de salida</label>
              <Dropdown
                value={format}
                onChange={(e) => setFormat(e.value)}
                options={OUTPUT_FORMATS}
                optionLabel="label"
                optionValue="value"
                className="w-full"
                disabled={disabled}
              />
            </div>
            <div>
              <label className="mb-1 block text-[11px] font-semibold text-slate-600">
                Calidad {output.lossy ? `· ${quality}%` : '· no aplica'}
              </label>
              {/* A quality slider over a lossless format would be a control
                  that does nothing, so it is disabled rather than hidden: a
                  control that disappears reads as a bug. */}
              <div className="px-1 pt-3">
                <Slider
                  value={quality}
                  onChange={(e) => setQuality(e.value)}
                  min={40}
                  max={100}
                  disabled={disabled || !output.lossy}
                />
              </div>
            </div>
          </div>

          <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-[11px] text-slate-500">
              {edited
                ? `Se subirá la versión editada: ${edited.name} · ${humanBytes(edited.size)} `
                  + `(original ${humanBytes(file.size)}).`
                : `Se subirá el archivo original: ${file.name} · ${humanBytes(file.size)}.`}
            </p>
            <Button
              type="button"
              label={edited ? 'Rehacer edición' : 'Aplicar edición'}
              icon="pi pi-check"
              size="small"
              loading={working}
              disabled={disabled}
              onClick={applyEdit}
              className="w-full sm:w-auto"
            />
          </div>
        </div>
      )}
    </div>
  );
}

/**
 * The identity card of a file the browser cannot draw: the typed icon, the
 * name, and the three facts that let somebody confirm they picked the right
 * thing. It is the primary surface for a spreadsheet or a text document, and
 * the fallback inside the PDF frame.
 *
 * The colour comes from the same table the library grid uses, so the file looks
 * the same here as it will once it is stored.
 */
function FileIdentity({ file, style }) {
  return (
    <div className={`flex h-[38vh] min-h-[16rem] flex-col items-center justify-center gap-3 rounded-xl ring-1 ${style.surface} ${style.ring}`}>
      <svg className={`h-20 w-20 ${style.accent}`} fill="none" viewBox="0 0 24 24" strokeWidth={1.2} stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" d={style.icon} />
      </svg>
      <p className="px-6 text-center text-sm font-semibold text-slate-700">{file.name}</p>
      <p className="text-xs text-slate-500">
        {style.label} · .{extensionOf(file.name)} · {humanBytes(file.size)}
      </p>
      <p className="max-w-sm px-6 text-center text-[11px] text-slate-400">
        Este formato se sube tal cual, sin transformaciones. El sistema conserva el archivo original
        byte por byte.
      </p>
    </div>
  );
}

/**
 * Aspect ratios offered by the crop tool. `0` stands for "free" and is turned
 * into cropper.js's NaN at the call site — see the state declaration above.
 */
const ASPECT_RATIOS = [
  { label: 'Libre', value: 0 },
  { label: 'Cuadrado 1:1', value: 1 },
  { label: 'Horizontal 4:3', value: 4 / 3 },
  { label: 'Horizontal 16:9', value: 16 / 9 },
  { label: 'Vertical 3:4', value: 3 / 4 },
];
