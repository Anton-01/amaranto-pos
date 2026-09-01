import { useEffect, useState } from 'react';
import mediaApi from '../../api/media';
import { previewStyle, rendersInline } from '../../lib/mediaPreview';

/**
 * The module's preview surface, in one component.
 *
 * WHY THE BYTES CANNOT BE AN `<img src>`. Every file in this library is private
 * in Google Drive and served by an authenticated endpoint of the POS. A plain
 * `src` attribute issues a request the browser builds itself, with no
 * Authorization header, and it comes back 401. So an image is fetched as a blob
 * through the authenticated client and handed over as an object URL.
 *
 * That URL is a resource the browser holds until it is explicitly released,
 * which is why the effect revokes it on cleanup. Without that, scrolling a
 * library of a few hundred images leaks every one of them for the lifetime of
 * the tab.
 *
 * Anything that is not an image renders as a typed icon instead. Downloading a
 * 10 MB spreadsheet to draw a green glyph over it would be pure waste, and a
 * PDF thumbnail is a render pass no browser performs for free.
 */
export default function MediaPreviewTile({ file, size = 'md', className = '' }) {
  const [objectUrl, setObjectUrl] = useState(null);
  const [failed, setFailed] = useState(false);
  const style = previewStyle(file);

  /*
   * The fetch keys on these two fields and nothing else. Lifting them out of
   * `file` keeps the effect from re-running when an unrelated field changes —
   * a rename would otherwise re-download the bytes — and lets the dependency
   * array name exactly what it depends on.
   */
  const fileId = file?.id;
  const isInline = rendersInline(file);

  useEffect(() => {
    if (!fileId || !isInline) {
      return undefined;
    }

    let revoked = false;
    let url = null;

    mediaApi
      .contentUrl(fileId)
      .then((created) => {
        // The tile may already be gone by the time the bytes arrive — a fast
        // scroll unmounts rows mid-flight. Releasing here avoids the leak the
        // cleanup below can no longer reach.
        if (revoked) {
          URL.revokeObjectURL(created);
          return;
        }
        url = created;
        setObjectUrl(created);
      })
      .catch(() => setFailed(true));

    return () => {
      revoked = true;
      if (url) URL.revokeObjectURL(url);
    };
  }, [fileId, isInline]);

  const iconSize = { sm: 'h-6 w-6', md: 'h-10 w-10', lg: 'h-16 w-16' }[size] ?? 'h-10 w-10';

  if (objectUrl && !failed) {
    return (
      <img
        src={objectUrl}
        alt={file.alt_text || file.name}
        loading="lazy"
        className={`h-full w-full object-cover ${className}`}
      />
    );
  }

  return (
    <div
      className={`flex h-full w-full flex-col items-center justify-center gap-1.5 ${style.surface} ${className}`}
    >
      <svg
        className={`${iconSize} ${style.accent}`}
        fill="none"
        viewBox="0 0 24 24"
        strokeWidth={1.5}
        stroke="currentColor"
        aria-hidden="true"
      >
        <path strokeLinecap="round" strokeLinejoin="round" d={style.icon} />
      </svg>
      {size !== 'sm' && (
        <span className={`text-[10px] font-semibold uppercase tracking-wide ${style.accent}`}>
          {file?.extension || style.label}
        </span>
      )}
    </div>
  );
}
