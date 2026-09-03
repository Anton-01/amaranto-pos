import { useCallback, useEffect, useState } from 'react';
import { Dialog } from 'primereact/dialog';
import { Button } from 'primereact/button';
import { Dropdown } from 'primereact/dropdown';
import { InputNumber } from 'primereact/inputnumber';
import { InputText } from 'primereact/inputtext';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { Tag } from 'primereact/tag';
import { toast } from 'sonner';
import mediaApi from '../../api/media';
import { SHARE_STATUS, formatDateTime } from '../../lib/mediaPreview';
import { dialogClass, DIALOG_PT, HIDE_BELOW } from '../../lib/responsive';

/**
 * Issue and revocation of controlled share links.
 *
 * THE ONE THING THIS DIALOG MUST GET RIGHT: the freshly minted URL is shown
 * exactly once. The server stores only the token's SHA-256, so there is no
 * second chance to display it — the same contract as an API key. The copy box
 * therefore stays open until the operator dismisses it deliberately, and the
 * table below can only ever show the first characters of older links.
 *
 * Note what is NOT offered anywhere on this screen: an option to make the file
 * public in Drive, and an option for a link that never expires. Both are absent
 * by design.
 */
export default function MediaShareDialog({ visible, onHide, file }) {
  const [links, setLinks] = useState([]);
  const [options, setOptions] = useState({ expiration: [], permissions: [] });
  const [loading, setLoading] = useState(false);
  const [issuing, setIssuing] = useState(false);
  const [form, setForm] = useState({ expires_in_hours: 24, permission: 'view', max_downloads: null });
  const [minted, setMinted] = useState(null);

  // Lifted out of `file` so the fetch depends on the identity of the file and
  // not on every field of it.
  const fileId = file?.id;

  const fetchLinks = useCallback(async () => {
    if (!fileId) return;

    setLoading(true);
    try {
      const res = await mediaApi.shareLinks(fileId);
      setLinks(res.data);
      setOptions({
        expiration: res.metadata?.expiration_options ?? [],
        permissions: res.metadata?.permissions ?? [],
      });
    } catch {
      toast.error('No se pudieron cargar los enlaces del archivo.');
    } finally {
      setLoading(false);
    }
  }, [fileId]);

  useEffect(() => {
    if (visible) {
      setMinted(null);
      fetchLinks();
    }
  }, [visible, fetchLinks]);

  const handleIssue = async () => {
    setIssuing(true);
    try {
      const res = await mediaApi.createShareLink(file.id, form);
      setMinted({ url: res.data.url, expiresAt: res.metadata?.expires_at });
      toast.success('Enlace generado', { description: res.metadata?.message });
      fetchLinks();
    } catch (err) {
      toast.error('No se pudo generar el enlace', {
        description: err.response?.data?.message || 'Intenta de nuevo.',
      });
    } finally {
      setIssuing(false);
    }
  };

  const handleRevoke = async (link) => {
    try {
      await mediaApi.revokeShareLink(file.id, link.id);
      toast.success('Enlace revocado. Deja de funcionar de inmediato.');
      fetchLinks();
    } catch (err) {
      toast.error('No se pudo revocar', { description: err.response?.data?.message });
    }
  };

  const copyUrl = async (url) => {
    try {
      await navigator.clipboard.writeText(url);
      toast.success('Enlace copiado al portapapeles.');
    } catch {
      // Clipboard access is denied outside a secure context. Selecting the
      // text by hand still works, so this is a note and not an error.
      toast.info('Copia el enlace manualmente desde el campo.');
    }
  };

  const statusTemplate = (row) => {
    const status = SHARE_STATUS[row.status] ?? SHARE_STATUS.expired;
    return <Tag value={status.label} severity={status.severity} className="text-xs" />;
  };

  const usageTemplate = (row) =>
    row.max_downloads === null
      ? `${row.download_count} (sin tope)`
      : `${row.download_count} / ${row.max_downloads}`;

  const actionsTemplate = (row) =>
    row.status === 'active' ? (
      <Button
        icon="pi pi-ban"
        label="Revocar"
        size="small"
        severity="danger"
        outlined
        onClick={() => handleRevoke(row)}
      />
    ) : (
      <span className="text-xs text-slate-400">—</span>
    );

  return (
    <Dialog
      header={`Compartir · ${file?.name ?? ''}`}
      visible={visible}
      onHide={onHide}
      className={dialogClass('xl')}
      pt={DIALOG_PT}
      draggable={false}
    >
      <div className="space-y-5">
        <div className="rounded-lg border border-sky-200 bg-sky-50 p-3">
          <p className="text-xs leading-relaxed text-sky-900">
            <strong>El archivo nunca se hace público en Google Drive.</strong> El enlace apunta al POS,
            que valida vigencia, tope de descargas y revocación en cada acceso, y registra cada apertura
            en la auditoría con su dirección IP.
          </p>
        </div>

        {/* The minted URL: shown once, dismissed deliberately. */}
        {minted && (
          <div className="rounded-lg border border-emerald-300 bg-emerald-50 p-3">
            <p className="text-sm font-semibold text-emerald-900">
              Enlace generado — cópialo ahora, no vuelve a mostrarse
            </p>
            <div className="mt-2 flex flex-col gap-2 sm:flex-row">
              <InputText value={minted.url} readOnly className="w-full font-mono text-xs" />
              <Button
                icon="pi pi-copy"
                label="Copiar"
                onClick={() => copyUrl(minted.url)}
                className="shrink-0"
              />
            </div>
            <p className="mt-2 text-xs text-emerald-700">
              Expira el {formatDateTime(minted.expiresAt)}
            </p>
          </div>
        )}

        <div className="grid gap-3 sm:grid-cols-3">
          <div>
            <label className="mb-1 block text-xs font-semibold text-slate-600">Vigencia</label>
            <Dropdown
              value={form.expires_in_hours}
              options={options.expiration}
              optionLabel="label"
              optionValue="value"
              onChange={(e) => setForm({ ...form, expires_in_hours: e.value })}
              className="w-full"
              placeholder="Selecciona"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-semibold text-slate-600">Nivel de acceso</label>
            <Dropdown
              value={form.permission}
              options={options.permissions}
              optionLabel="label"
              optionValue="value"
              onChange={(e) => setForm({ ...form, permission: e.value })}
              className="w-full"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-semibold text-slate-600">
              Tope de accesos <span className="font-normal text-slate-400">(opcional)</span>
            </label>
            <InputNumber
              value={form.max_downloads}
              onValueChange={(e) => setForm({ ...form, max_downloads: e.value })}
              min={1}
              max={1000}
              className="w-full"
              inputClassName="w-full"
              placeholder="Sin tope"
            />
          </div>
        </div>

        <Button
          label="Generar enlace seguro"
          icon="pi pi-link"
          onClick={handleIssue}
          loading={issuing}
          className="w-full sm:w-auto"
        />

        <div>
          <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
            Historial de enlaces
          </p>
          <DataTable
            value={links}
            loading={loading}
            size="small"
            emptyMessage="Este archivo no se ha compartido nunca."
            className="text-sm"
          >
            <Column field="token_preview" header="Token" body={(r) => (
              <span className="font-mono text-xs text-slate-500">{r.token_preview}…</span>
            )} />
            <Column header="Estatus" body={statusTemplate} />
            <Column field="permission" header="Acceso" className={HIDE_BELOW.sm} body={(r) => (
              r.permission === 'download' ? 'Vista y descarga' : 'Solo vista'
            )} />
            <Column header="Usos" body={usageTemplate} className={HIDE_BELOW.sm} />
            <Column header="Expira" className={HIDE_BELOW.md} body={(r) => formatDateTime(r.expires_at)} />
            <Column header="Creado por" className={HIDE_BELOW.lg} body={(r) => r.created_by_user?.name ?? '—'} />
            <Column header="" body={actionsTemplate} />
          </DataTable>
        </div>
      </div>
    </Dialog>
  );
}
