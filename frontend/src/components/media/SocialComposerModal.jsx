import { useCallback, useEffect, useMemo, useState } from 'react';
import { Dialog } from 'primereact/dialog';
import { Button } from 'primereact/button';
import { InputTextarea } from 'primereact/inputtextarea';
import { Tag } from 'primereact/tag';
import { toast } from 'sonner';
import socialApi from '../../api/social';
import { formatDateTime } from '../../lib/mediaPreview';
import { dialogClass, DIALOG_PT } from '../../lib/responsive';

/**
 * The Social Composer, opened over the attachment details modal.
 *
 * WHAT THIS SCREEN IS AND IS NOT. It is a dispatcher, not a publisher: pressing
 * "Publicar Ahora" queues the work and closes. Nothing here waits on Meta, and
 * that is the whole reason the module exists as a queue — an Instagram
 * container can take half a minute to transcode, and a POS that freezes for
 * half a minute is a POS with a line of customers behind it.
 *
 * THREE THINGS THE UI MUST GET RIGHT:
 *
 * 1. A channel with no credentials is shown, disabled, and says WHY. Hiding it
 *    would leave an operator wondering why Instagram is not an option; failing
 *    silently after the click would cost them the caption they just wrote.
 * 2. The counter counts against the STRICTEST selected network. Instagram's
 *    2 200 characters and Facebook's 5 000 are different limits, and a single
 *    generic count would let a caption that Facebook accepts get truncated on
 *    Instagram without a word of warning.
 * 3. WhatsApp is labelled a simulation wherever it appears. An operator must
 *    never believe a status went out that never left the server.
 */

/**
 * Brand identity of each channel.
 *
 * Kept in the frontend on purpose: these are presentation, and the backend's
 * catalog stays the authority on WHICH channels exist and whether they are
 * usable. A provider the backend adds without an entry here still renders,
 * with the neutral fallback.
 */
const CHANNEL_STYLES = {
  facebook: {
    icon: 'pi pi-facebook',
    selected: 'border-[#1877F2] bg-[#1877F2]/10 ring-2 ring-[#1877F2]/30',
    accent: 'text-[#1877F2]',
  },
  instagram: {
    icon: 'pi pi-instagram',
    selected: 'border-[#C13584] bg-[#C13584]/10 ring-2 ring-[#C13584]/30',
    accent: 'text-[#C13584]',
  },
  whatsapp: {
    icon: 'pi pi-whatsapp',
    selected: 'border-[#25D366] bg-[#25D366]/10 ring-2 ring-[#25D366]/30',
    accent: 'text-[#128C7E]',
  },
};

const FALLBACK_STYLE = {
  icon: 'pi pi-share-alt',
  selected: 'border-indigo-500 bg-indigo-50 ring-2 ring-indigo-200',
  accent: 'text-indigo-600',
};

const STATUS_SEVERITY = {
  pending: 'secondary',
  publishing: 'info',
  success: 'success',
  failed: 'danger',
};

export default function SocialComposerModal({ visible, onHide, file, previewUrl }) {
  const [channels, setChannels] = useState([]);
  const [selected, setSelected] = useState([]);
  const [caption, setCaption] = useState('');
  const [history, setHistory] = useState([]);
  const [loading, setLoading] = useState(false);
  const [publishing, setPublishing] = useState(false);

  const fileId = file?.id;

  const fetchCatalogs = useCallback(async () => {
    if (!fileId) return;

    /*
     * The caption is cleared as part of the reload, not on the way in. Carrying
     * yesterday's text into a new image is the one mistake this modal can make
     * that reaches the public.
     */
    setCaption('');
    setLoading(true);

    try {
      const [catalogs, previous] = await Promise.all([
        socialApi.catalogs(),
        socialApi.history(fileId),
      ]);

      setChannels(catalogs.channels ?? []);
      setHistory(previous ?? []);

      /*
       * Every connected channel starts ticked. The operator opened a composer
       * to publish, so the useful default is "everywhere I can" — and
       * unticking one is a single click, whereas an empty form makes them do
       * the work of stating the obvious.
       */
      setSelected((catalogs.channels ?? []).filter((c) => c.connected).map((c) => c.provider));
    } catch {
      toast.error('No se pudieron cargar las redes configuradas.');
    } finally {
      setLoading(false);
    }
  }, [fileId]);

  useEffect(() => {
    if (visible) {
      fetchCatalogs();
    }
  }, [visible, fetchCatalogs]);

  const toggleChannel = (channel) => {
    if (!channel.connected) return;

    setSelected((current) =>
      current.includes(channel.provider)
        ? current.filter((p) => p !== channel.provider)
        : [...current, channel.provider],
    );
  };

  /**
   * The tightest ceiling among the selected networks, which is the only limit
   * a single counter can honestly display. With nothing selected it falls back
   * to the widest configured one so the field is never counting against zero.
   */
  const effectiveLimit = useMemo(() => {
    const limits = channels
      .filter((c) => selected.includes(c.provider))
      .map((c) => c.caption_limit)
      .filter((n) => Number.isFinite(n) && n > 0);

    if (limits.length > 0) return Math.min(...limits);

    const all = channels.map((c) => c.caption_limit).filter((n) => Number.isFinite(n) && n > 0);

    return all.length > 0 ? Math.max(...all) : 2200;
  }, [channels, selected]);

  const limitingChannel = useMemo(
    () => channels.find((c) => selected.includes(c.provider) && c.caption_limit === effectiveLimit),
    [channels, selected, effectiveLimit],
  );

  const overLimit = caption.length > effectiveLimit;
  const canPublish = selected.length > 0 && !overLimit && !publishing;

  const handlePublish = async () => {
    setPublishing(true);
    try {
      const res = await socialApi.publish(file.id, { caption, channels: selected });

      toast.success('Publicación en cola', {
        description: res.metadata?.message,
      });
      onHide();
    } catch (err) {
      toast.error('No se pudo encolar la publicación', {
        description: err.response?.data?.message || 'Verifica el texto y las redes seleccionadas.',
      });
    } finally {
      setPublishing(false);
    }
  };

  /** One selectable channel card. */
  const channelCard = (channel) => {
    const style = CHANNEL_STYLES[channel.provider] ?? FALLBACK_STYLE;
    const isSelected = selected.includes(channel.provider);
    const disabled = !channel.connected;

    return (
      <button
        key={channel.provider}
        type="button"
        onClick={() => toggleChannel(channel)}
        disabled={disabled}
        aria-pressed={isSelected}
        className={[
          'flex w-full items-start gap-3 rounded-xl border p-3 text-left transition',
          disabled
            ? 'cursor-not-allowed border-slate-200 bg-slate-50 opacity-60'
            : 'cursor-pointer hover:border-slate-300 hover:bg-slate-50',
          isSelected && !disabled ? style.selected : 'border-slate-200 bg-white',
        ].join(' ')}
      >
        <i className={`${style.icon} mt-0.5 text-xl ${disabled ? 'text-slate-400' : style.accent}`} />

        <span className="min-w-0 flex-1">
          <span className="flex items-center gap-2">
            <span className="text-sm font-semibold text-slate-800">{channel.label}</span>
            {channel.simulated && (
              <Tag value="Simulado" severity="warning" className="text-[10px]" />
            )}
          </span>

          <span className="mt-0.5 block truncate text-xs text-slate-500">
            {channel.connected
              ? channel.account_label || 'Conexión activa'
              : channel.missing?.length
                ? `Falta: ${channel.missing.join(', ')}`
                : 'Sin conexión configurada'}
          </span>

          {channel.connected && channel.expired && (
            <span className="mt-0.5 block text-xs font-medium text-amber-600">
              El token expiró — renuévalo antes de publicar.
            </span>
          )}
        </span>

        {/* The switch is decorative: the whole card is the control, so the
            click target is the size of the card and not of a 40px track. */}
        <span
          className={[
            'mt-1 flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition',
            isSelected && !disabled ? 'bg-indigo-600' : 'bg-slate-300',
          ].join(' ')}
        >
          <span
            className={[
              'h-4 w-4 rounded-full bg-white shadow transition',
              isSelected && !disabled ? 'translate-x-4' : 'translate-x-0',
            ].join(' ')}
          />
        </span>
      </button>
    );
  };

  const footer = (
    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
      <span className="text-xs text-slate-500">
        {selected.length === 0
          ? 'Selecciona al menos una red.'
          : `Se publicará en ${selected.length} red${selected.length === 1 ? '' : 'es'}.`}
      </span>

      <span className="flex justify-end gap-2">
        <Button label="Cancelar" severity="secondary" outlined onClick={onHide} disabled={publishing} />
        <Button
          label="Publicar Ahora"
          icon="pi pi-send"
          onClick={handlePublish}
          loading={publishing}
          disabled={!canPublish}
        />
      </span>
    </div>
  );

  return (
    <Dialog
      header="Compartir en Redes"
      visible={visible}
      onHide={onHide}
      className={dialogClass('lg')}
      pt={DIALOG_PT}
      footer={footer}
      draggable={false}
    >
      {loading ? (
        <div className="flex h-56 items-center justify-center">
          <div className="h-8 w-8 animate-spin rounded-full border-4 border-indigo-600 border-t-transparent" />
        </div>
      ) : (
        <div className="grid gap-5 md:grid-cols-5">
          {/* Thumbnail of what is about to go out. */}
          <div className="md:col-span-2">
            <div className="overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
              {previewUrl ? (
                <img
                  src={previewUrl}
                  alt={file?.alt_text || file?.name}
                  className="max-h-56 w-full object-contain"
                />
              ) : (
                <div className="flex h-40 items-center justify-center text-slate-400">
                  <i className="pi pi-image text-3xl" />
                </div>
              )}
            </div>

            <p className="mt-2 truncate text-xs font-medium text-slate-700" title={file?.name}>
              {file?.name}
            </p>
            <p className="text-[11px] text-slate-500">
              {file?.dimensions ? `${file.dimensions} · ` : ''}
              {file?.human_size}
            </p>

            {/*
              The exposure is stated plainly. Publishing hands the image to a
              public network, and the picture cannot be unshared from the
              timelines that already saw it.
            */}
            <p className="mt-3 rounded-lg bg-amber-50 p-2 text-[11px] leading-relaxed text-amber-800">
              <i className="pi pi-info-circle mr-1" />
              Al publicar, el POS genera un enlace temporal para que Meta descargue la imagen. La
              publicación sale a una red pública y no se puede deshacer desde aquí.
            </p>
          </div>

          {/* Caption and channels. */}
          <div className="space-y-4 md:col-span-3">
            <div>
              <label className="mb-1 block text-xs font-semibold text-slate-600">Texto de la publicación</label>
              <InputTextarea
                value={caption}
                onChange={(e) => setCaption(e.target.value)}
                rows={6}
                autoResize
                placeholder="Escribe el mensaje que acompañará a la imagen…"
                className={`w-full ${overLimit ? 'p-invalid' : ''}`}
              />

              <div className="mt-1 flex items-center justify-between text-[11px]">
                <span className="text-slate-500">
                  {limitingChannel
                    ? `Límite de ${limitingChannel.label}, la red más estricta de tu selección.`
                    : 'El límite se ajusta a la red más estricta que selecciones.'}
                </span>
                <span className={overLimit ? 'font-semibold text-red-600' : 'text-slate-500'}>
                  {caption.length} / {effectiveLimit}
                </span>
              </div>
            </div>

            <div>
              <p className="mb-2 text-xs font-semibold text-slate-600">Canales</p>
              <div className="space-y-2">{channels.map(channelCard)}</div>
            </div>

            {history.length > 0 && (
              <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                <p className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                  Publicaciones anteriores de esta imagen
                </p>
                <ul className="space-y-1.5">
                  {history.map((post) => (
                    <li key={post.id} className="flex items-center justify-between gap-2 text-xs">
                      <span className="flex min-w-0 items-center gap-2">
                        <i
                          className={`${(CHANNEL_STYLES[post.provider] ?? FALLBACK_STYLE).icon} ${
                            (CHANNEL_STYLES[post.provider] ?? FALLBACK_STYLE).accent
                          }`}
                        />
                        <span className="truncate text-slate-600">
                          {formatDateTime(post.published_at || post.created_at)}
                        </span>
                      </span>
                      <Tag
                        value={post.status_label ?? post.status}
                        severity={STATUS_SEVERITY[post.status] ?? 'secondary'}
                        className="text-[10px]"
                      />
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        </div>
      )}
    </Dialog>
  );
}
