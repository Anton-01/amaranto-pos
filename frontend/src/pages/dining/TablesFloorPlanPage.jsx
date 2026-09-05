import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { Dialog } from 'primereact/dialog';
import { OverlayPanel } from 'primereact/overlaypanel';
import { InputNumber } from 'primereact/inputnumber';
import { InputText } from 'primereact/inputtext';
import { Dropdown } from 'primereact/dropdown';
import { Button } from 'primereact/button';
import { toast } from 'sonner';
import api from '../../api/axios';
import { RESOURCE, fetcherOf, staleTimeOf, invalidateAfterSale } from '../../api/resources';
import useCachedResource from '../../hooks/useCachedResource';
import CheckoutModal from '../../components/pos/CheckoutModal';
import PrintConfirmationModal from '../../components/pos/PrintConfirmationModal';
import TableDetailModal from '../../components/dining/TableDetailModal';
import TableCancellationModal from '../../components/dining/TableCancellationModal';
import { tableStatusMeta, fmtCurrency, fmtElapsed } from '../../components/dining/tableStatus';
import useCronosAgent from '../../hooks/useCronosAgent';

/** Referencias estables para el estado aun sin cargar: evitan romper useMemo. */
const EMPTY = Object.freeze([]);
const EMPTY_SUMMARY = Object.freeze({ total: 0, available: 0, occupied: 0, reserved: 0 });
const DEFAULT_TAX_RATE = 0.16;

/** Valor sentinela de la opcion "sin filtro" del selector de zonas. */
const ALL_ZONES = '__todas__';

/**
 * Plano de mesas (Floor Plan).
 *
 * Es una vista compartida entre varios meseros, por lo que se refresca al
 * recuperar el foco de la ventana y tras cada operacion, en lugar de sondear en
 * bucle: la casa evita el polling por diseno.
 *
 * El shell (sidebar + header) lo aporta la ruta padre `PersistentShell`, que
 * NO se desmonta al alternar con el POS (Fase 11).
 */
export default function TablesFloorPlanPage() {
  const cronosAgent = useCronosAgent();

  const [zoneFilter, setZoneFilter] = useState(null);

  const [openTarget, setOpenTarget] = useState(null);
  const [guests, setGuests] = useState(null);
  const [notes, setNotes] = useState('');
  const [opening, setOpening] = useState(false);

  const [detailTable, setDetailTable] = useState(null);
  const [chargeSession, setChargeSession] = useState(null);
  const [pendingPrint, setPendingPrint] = useState(null);

  /*
   * Per-card options menu. One single overlay is shared by the whole grid and
   * anchored to whichever kebab button was pressed: mounting an overlay per
   * card would put dozens of hidden popups in the DOM of a busy floor plan.
   */
  const cardMenuRef = useRef(null);
  const [menuTable, setMenuTable] = useState(null);
  const [cancelTarget, setCancelTarget] = useState(null);

  /*
   * El plano sale de la cache compartida (Fase 11). Al volver desde el POS, la
   * ventana de frescura de 10 s hace que la vista se pinte en el primer frame
   * con las mesas ya conocidas y la revalidacion ocurra en segundo plano: el
   * salon nunca aparece vacio ni con un spinner intermedio.
   */
  const tablesQuery = useCachedResource(RESOURCE.DINING_TABLES, fetcherOf(RESOURCE.DINING_TABLES), {
    staleTime: staleTimeOf(RESOURCE.DINING_TABLES),
  });
  const taxRateQuery = useCachedResource(RESOURCE.TAX_RATE, fetcherOf(RESOURCE.TAX_RATE), {
    staleTime: staleTimeOf(RESOURCE.TAX_RATE),
  });

  const tables = tablesQuery.data?.tables ?? EMPTY;
  const summary = tablesQuery.data?.summary ?? EMPTY_SUMMARY;
  const zones = tablesQuery.data?.zones ?? EMPTY;
  const taxRate = taxRateQuery.data ?? DEFAULT_TAX_RATE;
  const loading = tablesQuery.isLoading;

  /** Revalidacion forzada: se usa tras cada operacion sobre una mesa. */
  const fetchTables = useCallback(() => tablesQuery.refresh(), [tablesQuery]);

  useEffect(() => {
    if (tablesQuery.error) {
      toast.error('No se pudo actualizar el plano de mesas.', {
        description: 'Se muestra el ultimo estado conocido del salon.',
      });
    }
  }, [tablesQuery.error]);

  // El salon cambia bajo los pies: al volver a la pestana, revalidar.
  useEffect(() => {
    const onFocus = () => fetchTables();
    window.addEventListener('focus', onFocus);
    return () => window.removeEventListener('focus', onFocus);
  }, [fetchTables]);

  const visibleTables = useMemo(
    () => (zoneFilter ? tables.filter(t => t.zone === zoneFilter) : tables),
    [tables, zoneFilter]
  );

  /**
   * Opens the card options menu anchored to the kebab that was pressed.
   *
   * The click must not bubble: the card itself is a button that would open the
   * table right behind the menu.
   */
  const openCardMenu = (event, table) => {
    event.stopPropagation();
    const switching = menuTable !== null && menuTable.id !== table.id;
    setMenuTable(table);
    if (switching) {
      cardMenuRef.current?.show(event, event.currentTarget);
    } else {
      cardMenuRef.current?.toggle(event, event.currentTarget);
    }
  };

  const handleTableClick = (table) => {
    if (table.status === 'occupied') {
      setDetailTable(table);
      return;
    }
    if (table.status === 'reserved') {
      toast.info('Mesa reservada', { description: 'Libera la reserva desde el catalogo de mesas para poder abrirla.' });
      return;
    }
    setGuests(table.capacity);
    setNotes('');
    setOpenTarget(table);
  };

  const handleOpenTable = async () => {
    setOpening(true);
    try {
      await api.post(`/tables/${openTarget.id}/open`, {
        guests: guests ?? null,
        notes: notes || null,
      });
      toast.success(`${openTarget.name} abierta.`);
      const opened = openTarget;
      setOpenTarget(null);
      await fetchTables();
      // Encadena directo al detalle: el mesero abre para tomar la orden.
      setDetailTable({ ...opened, status: 'occupied' });
    } catch (err) {
      const data = err.response?.data;
      if (data?.code === 'ERR_TABLE_ALREADY_OPEN') {
        toast.error('La mesa ya fue abierta por otro usuario.');
        setOpenTarget(null);
        fetchTables();
      } else {
        toast.error(data?.message || 'Error al abrir la mesa.');
      }
    } finally {
      setOpening(false);
    }
  };

  /** El cobro reutiliza el CheckoutModal en modo mesa. */
  const handleCharge = (session) => {
    if (!session || !detailTable) return;
    setChargeSession({
      table_id: detailTable.id,
      table_name: detailTable.name,
      waiter_name: session.waiter?.name ?? null,
      items: session.items ?? [],
    });
    setDetailTable(null);
  };

  // El CheckoutModal razona en items de carrito: se traduce la cuenta del
  // servidor a esa forma. line_gross = final + descuento, por construccion.
  const chargeCart = useMemo(() => {
    if (!chargeSession) return [];
    return chargeSession.items.map(item => {
      const lineGross = parseFloat(item.final_price_at_sale) + parseFloat(item.discount_amount_at_sale ?? 0);
      return {
        product_id: item.product_id,
        product_name: item.product_name,
        product_sku: item.product_sku,
        sale_price: item.quantity > 0 ? lineGross / item.quantity : 0,
        quantity: item.quantity,
        promotion_id: item.promotion?.id ?? null,
        promotion_name: item.promotion?.name ?? null,
        discount: parseFloat(item.discount_amount_at_sale ?? 0),
      };
    });
  }, [chargeSession]);

  const statusChips = [
    { label: 'Disponibles', value: summary.available, cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    { label: 'Ocupadas', value: summary.occupied, cls: 'bg-rose-50 text-rose-700 ring-rose-200' },
    { label: 'Reservadas', value: summary.reserved, cls: 'bg-amber-50 text-amber-700 ring-amber-200' },
  ];

  return (
    <>
      <div className="mb-4 flex flex-col gap-3 sm:mb-5 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">Plano de Mesas</h1>
          <p className="text-sm text-slate-500">
            {summary.total} mesa{summary.total !== 1 ? 's' : ''} en servicio · toca una mesa para abrirla o ver su cuenta
          </p>
        </div>
        <div className="flex items-center gap-2 overflow-x-auto pb-1 sm:flex-wrap sm:overflow-visible sm:pb-0">
          {statusChips.map(chip => (
            <span key={chip.label} className={`shrink-0 whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-semibold ring-1 ${chip.cls}`}>
              {chip.value} {chip.label}
            </span>
          ))}
          {zones.length > 0 && (
            /*
             * El filtro arranca sin zona seleccionada (`zoneFilter = null`) y
             * PrimeReact interpreta null como "sin valor": sin `placeholder` el
             * control se pintaba COMPLETAMENTE VACIO, sin pista de para que
             * sirve. El placeholder describe el estado real —no hay filtro, se
             * ven todas las zonas— y `ALL_ZONES` da a la opcion de limpieza un
             * valor propio para que tambien se muestre al elegirla.
             */
            <Dropdown
              value={zoneFilter}
              options={[
                { label: 'Todas las zonas', value: ALL_ZONES },
                ...zones.map(z => ({ label: z, value: z })),
              ]}
              onChange={(e) => setZoneFilter(e.value === ALL_ZONES ? null : e.value)}
              placeholder="Todas las zonas"
              aria-label="Filtrar mesas por zona"
              className="w-full text-sm sm:w-48"
              pt={{ root: { className: 'w-full sm:w-48' } }}
            />
          )}
          <Button
            type="button"
            icon="pi pi-refresh"
            onClick={fetchTables}
            tooltip="Actualizar plano"
            tooltipOptions={{ position: 'bottom' }}
            className="cursor-pointer rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-600 hover:bg-slate-50"
            pt={{ root: { className: 'border border-slate-200' } }}
          />
        </div>
      </div>

      {loading ? (
        <p className="py-16 text-center text-sm text-slate-400">Cargando plano de mesas...</p>
      ) : visibleTables.length === 0 ? (
        <div className="rounded-xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200">
          <p className="text-sm text-slate-500">
            No hay mesas dadas de alta. Crealas desde <span className="font-semibold">Administración → Mesas</span>.
          </p>
        </div>
      ) : (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
          {visibleTables.map((table) => {
            const meta = tableStatusMeta(table.status);
            const session = table.active_session;
            return (
              /*
               * The card is a container, not a button: the options menu is a
               * button of its own and a button cannot be nested inside another
               * one. The whole surface is still one tap target — the inner
               * button fills the card — while the kebab keeps its own hit area.
               */
              <div
                key={table.id}
                className={`relative min-h-[104px] rounded-xl border-2 shadow-sm transition-all hover:shadow-md ${meta.card}`}
              >
                <button
                  type="button"
                  onClick={() => handleTableClick(table)}
                  className="block h-full w-full cursor-pointer rounded-xl p-3 text-left sm:p-4"
                >
                  <div className={`flex items-start justify-between gap-2 ${session ? 'pr-6' : ''}`}>
                    <div className="min-w-0">
                      <p className="truncate text-base font-bold text-slate-900">{table.name}</p>
                      <p className="text-xs text-slate-500">
                        {table.capacity} lugar{table.capacity !== 1 ? 'es' : ''}
                        {table.zone ? ` · ${table.zone}` : ''}
                      </p>
                    </div>
                    {/* No status dot in the corner anymore: that corner belongs
                        to the options menu. The colour cue moves into the badge
                        below, where it reads exactly the same at a glance. */}
                  </div>

                  <span className={`mt-3 inline-flex items-center gap-1.5 rounded px-2 py-0.5 text-[11px] font-semibold ${meta.badge}`}>
                    <span className={`h-2 w-2 shrink-0 rounded-full ${meta.dot}`} />
                    {meta.label}
                  </span>

                  {session ? (
                    <div className="mt-3 border-t border-white/70 pt-2">
                      <p className="truncate text-xs text-slate-600">
                        {session.waiter?.name ?? 'Sin mesero'}
                        {session.guests ? ` · ${session.guests} pax` : ''}
                      </p>
                      <div className="mt-1 flex items-end justify-between gap-2">
                        <span className="text-xs text-slate-500">{fmtElapsed(session.elapsed_minutes)}</span>
                        <span className={`text-base font-bold tabular-nums ${meta.accent}`}>
                          {fmtCurrency(session.total)}
                        </span>
                      </div>
                      <p className="mt-0.5 text-[11px] text-slate-500">
                        {session.items_count} producto{session.items_count !== 1 ? 's' : ''}
                      </p>
                    </div>
                  ) : (
                    <div className="mt-3 border-t border-white/70 pt-2">
                      <p className="text-xs text-slate-400">Sin cuenta abierta</p>
                    </div>
                  )}
                </button>

                {/* Only a table with a live account has anything to void, so a
                    free table shows no menu instead of an empty one. */}
                {session && (
                  <button
                    type="button"
                    onClick={(e) => openCardMenu(e, table)}
                    aria-label={`Opciones de ${table.name}`}
                    aria-haspopup="menu"
                    title="Opciones"
                    className="absolute right-1 top-1 flex h-8 w-8 cursor-pointer items-center justify-center rounded-lg text-slate-500 transition-colors hover:bg-white/80 hover:text-slate-700 sm:right-1.5 sm:top-1.5"
                  >
                    <i className="pi pi-ellipsis-v text-sm" />
                  </button>
                )}
              </div>
            );
          })}
        </div>
      )}

      {/* Card options menu. Shared by every card and re-anchored on each open. */}
      <OverlayPanel
        ref={cardMenuRef}
        onHide={() => setMenuTable(null)}
        className="!rounded-xl !border-slate-200 !p-0 !shadow-xl !shadow-slate-200/50"
        pt={{ content: { className: '!p-1.5' } }}
      >
        <div role="menu" className="w-48">
          <button
            type="button"
            role="menuitem"
            onClick={() => {
              cardMenuRef.current?.hide();
              setCancelTarget(menuTable);
            }}
            className="flex w-full cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-medium text-rose-600 hover:bg-rose-50"
          >
            <i className="pi pi-times-circle text-sm" />
            Cancelar Mesa
          </button>
        </div>
      </OverlayPanel>

      {/* Apertura de mesa */}
      <Dialog
        visible={openTarget !== null}
        onHide={() => !opening && setOpenTarget(null)}
        modal
        header={null}
        /*
         * DESKTOP SIZING. Full-bleed on a phone, and from `lg` up it is capped
         * at half the viewport (never past `2xl`) so the floor plan behind it
         * stays readable on a laptop or a wide monitor.
         */
        className="w-full max-w-md lg:w-1/2 lg:max-w-2xl"
        pt={{
          mask: { className: 'backdrop-blur-sm bg-black/30' },
          root: { className: 'rounded-2xl border-0 shadow-2xl' },
          content: { className: 'p-0' },
        }}
      >
        <div className="p-4 sm:p-6">
          <h3 className="text-lg font-semibold text-slate-900">Abrir {openTarget?.name}</h3>
          <p className="mt-0.5 text-xs text-slate-500">
            Se generara la cuenta de la mesa a tu nombre.
          </p>

          <div className="mt-5 space-y-4">
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Comensales</label>
              <InputNumber
                value={guests}
                onValueChange={(e) => setGuests(e.value)}
                min={1}
                max={500}
                showButtons
                disabled={opening}
                className="w-full"
                inputClassName="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                pt={{ root: { className: 'w-full' } }}
              />
            </div>
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">
                Nota <span className="text-xs text-slate-400">(opcional)</span>
              </label>
              <InputText
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                maxLength={500}
                placeholder="Cumpleaños, alergias, cliente frecuente..."
                disabled={opening}
                className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                pt={{ root: { className: 'w-full' } }}
              />
            </div>
          </div>

          <div className="mt-6 flex gap-3">
            <Button
              type="button"
              label="Cancelar"
              onClick={() => setOpenTarget(null)}
              disabled={opening}
              className="flex-1 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
              pt={{ root: { className: 'border border-slate-200' } }}
            />
            <Button
              type="button"
              label={opening ? 'Abriendo...' : 'Abrir Mesa'}
              onClick={handleOpenTable}
              loading={opening}
              disabled={opening}
              className="flex-1 cursor-pointer rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
              pt={{ root: { className: 'border-0' } }}
            />
          </div>
        </div>
      </Dialog>

      <TableDetailModal
        visible={detailTable !== null}
        table={detailTable}
        onHide={() => { setDetailTable(null); fetchTables(); }}
        onCharge={handleCharge}
        onSessionChange={fetchTables}
      />

      <CheckoutModal
        visible={chargeSession !== null}
        onHide={() => { setChargeSession(null); fetchTables(); }}
        cart={chargeCart}
        taxRate={taxRate}
        tableSession={chargeSession}
        onSuccess={(order, meta) => {
          setChargeSession(null);
          // El cobro libero la mesa y movio stock: el catalogo que vera el POS
          // al volver ya no es valido, aunque su ventana siga vigente.
          invalidateAfterSale();
          fetchTables();
          setPendingPrint({
            order,
            printerData: meta?.printerData || null,
            ticketConfig: meta?.ticketConfig || null,
          });
        }}
      />

      {/* Voiding a table now starts from its card, not from the detail view. */}
      <TableCancellationModal
        visible={cancelTarget !== null}
        table={cancelTarget}
        session={cancelTarget?.active_session}
        onHide={() => setCancelTarget(null)}
        onCanceled={() => {
          setCancelTarget(null);
          fetchTables();
        }}
      />

      <PrintConfirmationModal
        visible={pendingPrint !== null}
        order={pendingPrint?.order}
        ticketConfig={pendingPrint?.ticketConfig}
        printerData={pendingPrint?.printerData}
        taxRate={taxRate}
        cronosAgent={cronosAgent}
        onClose={() => setPendingPrint(null)}
      />
    </>
  );
}
