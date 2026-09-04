/**
 * Client half of the upload pipeline: what a file IS, whether the policy in
 * force would take it, and how to turn an edited image back into a file.
 *
 * WHY A CLIENT-SIDE POLICY CHECK NOW EXISTS. The upload dialog used to send
 * every picked file to the server and render whatever verdict came back, on the
 * explicit grounds that a second copy of the rules would drift from the first.
 * That reasoning still holds for the VERDICT — the server remains the only
 * authority, and it re-validates every byte it receives — but it stopped
 * describing the dialog once the dialog gained an editor. An editor has to know
 * the ceiling it is compressing toward, and telling somebody a 40 MB photo was
 * rejected only AFTER they waited for it to upload is a worse experience than
 * the drift this module risks.
 *
 * The drift is bounded by construction: the rules here are read from the same
 * `allowed_file_types` rows the backend enforces, fetched at dialog open, never
 * hardcoded. When those rows are unavailable — a manager without the admin
 * permission that exposes the catalog — every check in this module abstains and
 * the server decides alone, exactly as before. A local check may therefore be
 * absent, but it can never be a rule the server does not have.
 */

/** Reason codes, deliberately the same strings the API answers with. */
export const REJECTION = {
  NOT_REGISTERED: 'ERR_MEDIA_TYPE_NOT_REGISTERED',
  TOO_LARGE: 'ERR_MEDIA_FILE_TOO_LARGE',
};

/** Lowercase extension without the dot, or '' when the name carries none. */
export function extensionOf(name) {
  const match = /\.([^.]+)$/.exec(String(name ?? ''));

  return match ? match[1].toLowerCase() : '';
}

/** Name without its extension — the default library name for an upload. */
export function baseNameOf(name) {
  return String(name ?? '').replace(/\.[^.]+$/, '');
}

/** The active policy row governing an extension, or null when there is none. */
export function policyFor(activeTypes, extension) {
  return (activeTypes ?? []).find((type) => type.extension === extension) ?? null;
}

/**
 * Whether a picked file would survive the policy in force.
 *
 * Returns null when the file is acceptable OR when there is nothing to check
 * against. Abstaining on an empty catalog is the important half: a user who
 * cannot read the catalog must not be blocked by its absence.
 *
 * The MIME layer of the server's validation is deliberately NOT mirrored here.
 * It compares the file's magic bytes, which the browser does not expose, so any
 * client approximation would compare the type string the OS guessed from the
 * same extension we already checked — a tautology that would catch nothing and
 * would reject legitimate files whose browser spells the type differently.
 */
export function checkPolicy(file, activeTypes) {
  if (!file || !activeTypes?.length) return null;

  const extension = extensionOf(file.name);
  const policy = policyFor(activeTypes, extension);

  if (!policy) {
    return {
      code: REJECTION.NOT_REGISTERED,
      message: extension
        ? `La extensión .${extension} no está habilitada en el catálogo de tipos permitidos. `
          + 'Pide a un administrador que la registre o elige otro archivo.'
        : 'El archivo no tiene extensión, así que no hay ninguna política que pueda autorizarlo.',
    };
  }

  const limitKb = Number(policy.effective_max_size_kb) || 0;
  const sizeKb = Math.ceil(file.size / 1024);

  if (limitKb > 0 && sizeKb > limitKb) {
    return {
      code: REJECTION.TOO_LARGE,
      message: `El archivo pesa ${formatKb(sizeKb)} y el límite para .${extension} es ${formatKb(limitKb)}. `
        + 'Si es una imagen puedes reducirla con las herramientas de este panel antes de subirla.',
    };
  }

  return null;
}

/** Size ceiling in bytes for a file's extension, or null when unknown. */
export function limitBytesFor(file, activeTypes) {
  const policy = policyFor(activeTypes ?? [], extensionOf(file?.name));
  const limitKb = Number(policy?.effective_max_size_kb) || 0;

  return limitKb > 0 ? limitKb * 1024 : null;
}

/** "820 KB" / "1.4 MB" from a kilobyte count. */
export function formatKb(kb) {
  return kb >= 1024 ? `${(kb / 1024).toFixed(1)} MB` : `${kb} KB`;
}

/**
 * How this file should be rendered, mirroring MediaFile::getPreviewKindAttribute.
 *
 * Mirrored rather than requested because the file has not been uploaded yet:
 * there is no row to ask. Keeping the branches in the same order as the backend
 * is what makes the preview in this dialog and the tile in the grid agree about
 * a file that has just crossed between them.
 */
export function localPreviewKind(file, activeTypes) {
  const type = String(file?.type ?? '');

  if (type.startsWith('image/')) return 'image';
  if (type === 'application/pdf' || extensionOf(file?.name) === 'pdf') return 'pdf';

  const category = policyFor(activeTypes ?? [], extensionOf(file?.name))?.category;

  if (category === 'spreadsheet') return 'spreadsheet';
  if (category === 'presentation') return 'presentation';
  if (category === 'archive') return 'archive';

  return 'document';
}

/** True for the files the interactive editor can actually operate on. */
export function isEditableImage(file) {
  // SVG is excluded even when an administrator has enabled it: it is a document
  // format, and drawing it to a canvas would rasterize it into something the
  // user did not ask for while silently dropping everything vector about it.
  return Boolean(file) && file.type.startsWith('image/') && file.type !== 'image/svg+xml';
}

/**
 * Output formats the editor can write.
 *
 * `keep` exists and is the default because re-encoding a file nobody asked to
 * re-encode is a loss with no upside — a second JPEG generation on an image
 * that was only rotated, or a PNG screenshot turned lossy behind the user's
 * back.
 */
export const OUTPUT_FORMATS = [
  { value: 'keep', label: 'Mantener original', mime: null, extension: null, lossy: null },
  { value: 'image/jpeg', label: 'JPEG (menor peso)', mime: 'image/jpeg', extension: 'jpg', lossy: true },
  { value: 'image/webp', label: 'WebP (mejor compresión)', mime: 'image/webp', extension: 'webp', lossy: true },
  { value: 'image/png', label: 'PNG (sin pérdida)', mime: 'image/png', extension: 'png', lossy: false },
];

/**
 * Resize presets offered beside the crop tools.
 *
 * The thumbnail preset is the smallest one and it is a real product decision,
 * not a rounding of the others: a catalogue photo that will only ever be shown
 * at 400 px does not need to travel, be stored, or be served at 4000 px.
 */
export const SIZE_PRESETS = [
  { value: 0, label: 'Tamaño real del recorte' },
  { value: 1600, label: 'Máx. 1600 px' },
  { value: 1024, label: 'Máx. 1024 px' },
  { value: 400, label: 'Miniatura (400 px)' },
];

/**
 * The MIME and extension an export will actually produce.
 *
 * Canvas can only write PNG, JPEG and WebP. Asking it for anything else does
 * not fail — it silently answers a PNG under the requested type, which would
 * hand the server a file whose bytes and extension disagree and earn a MIME
 * mismatch rejection nobody could explain. So an unsupported source format
 * falls back to PNG explicitly, and the dialog says so.
 */
export function resolveOutput(sourceFile, formatValue) {
  const chosen = OUTPUT_FORMATS.find((format) => format.value === formatValue);

  if (chosen?.mime) {
    return { mime: chosen.mime, extension: chosen.extension, lossy: chosen.lossy };
  }

  const sourceMime = String(sourceFile?.type ?? '');

  if (sourceMime === 'image/jpeg') return { mime: 'image/jpeg', extension: extensionOf(sourceFile.name) || 'jpg', lossy: true };
  if (sourceMime === 'image/webp') return { mime: 'image/webp', extension: 'webp', lossy: true };
  if (sourceMime === 'image/png') return { mime: 'image/png', extension: 'png', lossy: false };

  return { mime: 'image/png', extension: 'png', lossy: false };
}

/**
 * Turns a canvas into a File ready to be posted.
 *
 * The name is rebuilt from the ORIGINAL base name plus the extension of the
 * format actually written, never carried over from the source. That is what
 * keeps the server's first validation layer (extension) and its third
 * (magic bytes) in agreement after a format change — a cropped `logo.png`
 * exported as JPEG must arrive as `logo.jpg`, or it is rejected for lying
 * about itself.
 */
export function canvasToFile(canvas, sourceFile, { mime, extension, quality }) {
  return new Promise((resolve, reject) => {
    canvas.toBlob(
      (blob) => {
        if (!blob) {
          reject(new Error('El navegador no pudo generar la imagen editada.'));

          return;
        }

        resolve(
          new File([blob], `${baseNameOf(sourceFile.name)}.${extension}`, {
            type: mime,
            lastModified: Date.now(),
          }),
        );
      },
      mime,
      quality,
    );
  });
}

/** Pixel dimensions of an image file, or null when it cannot be decoded. */
export function readImageDimensions(file) {
  return new Promise((resolve) => {
    const url = URL.createObjectURL(file);
    const image = new Image();

    image.onload = () => {
      URL.revokeObjectURL(url);
      resolve({ width: image.naturalWidth, height: image.naturalHeight });
    };

    image.onerror = () => {
      URL.revokeObjectURL(url);
      resolve(null);
    };

    image.src = url;
  });
}
