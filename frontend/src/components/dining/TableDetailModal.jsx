import { useState, useEffect, useMemo, useCallback } from 'react';
import { Dialog } from 'primereact/dialog';
import { InputText } from 'primereact/inputtext';
import { Button } from 'primereact/button';
import { toast } from 'sonner';
import api from '../../api/axios';
import TableCancellationModal from './TableCancellationModal';
import { useAuth } from '../../context/AuthContext';
import { fmtCurrency, fmtElapsed } from './tableStatus';

/**
 * Detalle de una mesa ocupada: consumo acumulado y alta inmediata de productos.
 *
 * Cada clic en el catalogo dispara POST /tables/{id}/items al instante — el
 * endpoint es transaccional, de modo que la comanda queda firme antes de que el
 * mesero levante el dedo. La respuesta trae la sesion recalculada, asi que la
 * cuenta y el total se refrescan sin una segunda peticion.
 *
 * Las partidas ya comandadas se corrigen desde la propia cuenta con los mismos
 * controles del POS (+ / - / papelera). Toda reduccion viaja al servidor, que
 * devuelve el stock y deja la huella en la auditoria: aqui no se edita nada en
 * memoria, porque la cuenta viva es la del servidor.
 *
 * Esos controles solo se pintan para admin y manager, que es a quienes el
 * backend autoriza a retirar consumo ya registrado. El mesero sigue agregando
 * comanda con normalidad.
 */
export default function TableDetailModal({ visible, table, onHide, onCharge, onSessionChange }) {
  const { user } = useAuth();
  const canEditItems = ['admin', 'manager'].some(role => user?.roles?.includes(role));

  const [session, setSession] = useState(null);
  const [loading, setLoading] = useState(false);
  const [products, setProducts] = useState([]);
  const [search, setSearch] = useState('');
  const [addingId, setAddingId] = useState(null);
  const [mutatingItemId, setMutatingItemId] = useState(null);
  const [showCancellation, setShowCancellation] = useState(false);

  const tableId = table?.id;

  const fetchDetail = useCallback(async () => {
    if (!tableId) return;
    setLoading(true);
    try {
      const res = await api.get(`/tables/${tableId}`);
      setSession(res.data.data.active_session);
    } catch {
      toast.error('Error al cargar el detalle de la mesa.');
    } finally {
      setLoading(false);
    }
  }, [tableId]);

  useEffect(() => {
    if (!visible) return;
    setSearch('');
    fetchDetail();
    api.get('/products/grouped')
      .then(res => setProducts(res.data.data.flatMap(g => g.items)))
      .catch(() => setProducts([]));
  }, [visible, fetchDetail]);

  const filteredProducts = useMemo(() => {
    const term = search.trim().toLowerCase();
    const base = term
      ? products.filter(p => p.name.toLowerCase().includes(term) || p.sku.toLowerCase().includes(term))
      : products;
    return base.slice(0, 24);
  }, [products, search]);

  const addItem = async (product) => {
    setAddingId(product.id);
    try {
      const res = await api.post(`/tables/${tableId}/items`, {
        items: [{ product_id: product.id, quantity: 1, promotion_id: null }],
      });
      setSession(res.data.data);
      onSessionChange?.();
      toast.success(`${product.name} agregado a la cuenta.`);
    } catch (err) {
      const data = err.response?.data;
      if (data?.code === 'ERR_POS_INSUFFICIENT_STOCK') {
        toast.error('Sin existencias', { description: data.message });
      } else if (data?.code === 'ERR_TABLE_NO_OPEN_SESSION') {
        toast.error('La mesa ya no tiene cuenta abierta.');
        onHide();
      } else {
        toast.error(data?.message || 'Error al agregar el producto.');
      }
    } finally {
      setAddingId(null);
    }
  };

  /**
   * Single funnel for every line mutation.
   *
   * The server owns the arithmetic and the stock, so the response replaces the
   * whole session instead of patching it locally — that keeps quantity, price
   * and totals consistent even when a promotion makes the line non-linear.
   */
  const mutateItem = async (item, request, successMessage) => {
    setMutatingItemId(item.id);
    try {
      const res = await request();
      setSession(res.data.data);
      onSessionChange?.();
      toast.success(successMessage);
    } catch (err) {
      const data = err.response?.data;
      if (data?.code === 'ERR_POS_INSUFFICIENT_STOCK') {
        toast.error('Sin existencias', { description: data.message });
      } else if (data?.code === 'ERR_TABLE_NO_OPEN_SESSION') {
        toast.error('La mesa ya no tiene cuenta abierta.');
        onHide();
      } else if (data?.code === 'ERR_TABLE_ITEM_NOT_FOUND') {
        toast.info('Esa partida ya no está en la cuenta.');
        fetchDetail();
      } else if (data?.code === 'ERR_AUTH_FORBIDDEN_ROLE') {
        toast.error('Solo un administrador o gerente puede modificar partidas ya comandadas.');
      } else {
        toast.error(data?.message || 'No se pudo actualizar la partida.');
      }
    } finally {
      setMutatingItemId(null);
    }
  };

  const changeQuantity = (item, nextQuantity) => {
    // Reaching zero is a removal, and removals go through DELETE so the audit
    // trail records them as such instead of as a quantity edit.
    if (nextQuantity < 1) {
      return removeItem(item);
    }

    return mutateItem(
      item,
      () => api.put(`/tables/${tableId}/items/${item.id}`, { quantity: nextQuantity }),
      nextQuantity > item.quantity ? 'Cantidad aumentada.' : 'Cantidad reducida. Queda registrado en la auditoría.'
    );
  };

  const removeItem = (item) => mutateItem(
    item,
    () => api.delete(`/tables/${tableId}/items/${item.id}`),
    `${item.product_name} retirado de la cuenta. Queda registrado en la auditoría.`
  );

  const items = session?.items ?? [];

  return (
    <Dialog
      visible={visible}
      onHide={onHide}
      modal
      header={null}
      className="w-full max-w-4xl"
      pt={{
        mask: { className: 'backdrop-blur-sm bg-black/30' },
        root: { className: 'rounded-2xl border-0 shadow-2xl' },
        content: { className: 'p-0' },
      }}
    >
      <div className="border-b border-slate-200 px-6 py-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 className="text-lg font-semibold text-slate-900">{table?.name}</h3>
            <p className="mt-0.5 text-xs text-slate-500">
              {session?.waiter?.name ? `Atiende ${session.waiter.name}` : 'Sin mesero asignado'}
              {session?.guests ? ` · ${session.guests} comensal${session.guests !== 1 ? 'es' : ''}` : ''}
              {session?.opened_at ? ` · Abierta hace ${fmtElapsed(
                Math.round((Date.now() - new Date(session.opened_at).getTime()) / 60000)
              )}` : ''}
            </p>
          </div>
          <div className="text-right">
            <p className="text-xs font-medium text-slate-500">Consumo acumulado</p>
            <p className="text-2xl font-bold tabular-nums text-slate-900">{fmtCurrency(session?.total)}</p>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-6 p-6 lg:grid-cols-2">
        {/* Catalogo: un clic = un consumo registrado */}
        <div>
          <label className="mb-1.5 block text-sm font-medium text-slate-700">Agregar consumo</label>
          <InputText
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Buscar producto por nombre o SKU..."
            className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
            pt={{ root: { className: 'w-full' } }}
          />

          <div className="mt-3 grid max-h-96 grid-cols-2 gap-2 overflow-y-auto pr-1">
            {filteredProducts.map((product) => {
              const unavailable = product.track_stock && product.current_stock <= 0;
              return (
                <button
                  key={product.id}
                  onClick={() => addItem(product)}
                  disabled={unavailable || addingId != null}
                  className="cursor-pointer rounded-lg border border-slate-200 p-2.5 text-left transition-all hover:border-indigo-300 hover:shadow-md disabled:cursor-not-allowed disabled:opacity-40"
                >
                  <p className="truncate text-sm font-medium text-slate-900">{product.name}</p>
                  <p className="font-mono text-xs text-slate-500">{product.sku}</p>
                  <div className="mt-1.5 flex items-center justify-between">
                    <span className="text-sm font-bold text-indigo-600">{fmtCurrency(product.sale_price)}</span>
                    {addingId === product.id && <i className="pi pi-spin pi-spinner text-xs text-indigo-500" />}
                  </div>
                </button>
              );
            })}
            {filteredProducts.length === 0 && (
              <p className="col-span-2 py-8 text-center text-sm text-slate-400">Sin productos que coincidan.</p>
            )}
          </div>
        </div>

        {/* Cuenta viva, en orden de comanda */}
        <div className="flex flex-col">
          <div className="mb-1.5 flex items-center justify-between">
            <label className="text-sm font-medium text-slate-700">Cuenta de la mesa</label>
            <span className="text-xs text-slate-400">{items.length} partida{items.length !== 1 ? 's' : ''}</span>
          </div>

          <div className="max-h-72 flex-1 overflow-y-auto rounded-lg border border-slate-200">
            {loading ? (
              <p className="p-6 text-center text-sm text-slate-400">Cargando cuenta...</p>
            ) : items.length === 0 ? (
              <p className="p-6 text-center text-sm text-slate-400">
                La mesa aun no tiene consumos. Agrega el primer producto.
              </p>
            ) : (
              <ul className="divide-y divide-slate-100">
                {items.map((item) => {
                  const busy = mutatingItemId === item.id;
                  const locked = mutatingItemId !== null;

                  return (
                    <li key={item.id} className="p-3">
                      <div className="flex items-start justify-between gap-2">
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-medium text-slate-900">{item.product_name}</p>
                          <p className="text-xs text-slate-500">
                            {item.added_at
                              ? new Date(item.added_at).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' })
                              : '—'}
                            {item.promotion && (
                              <span className="ml-1 text-emerald-600">· {item.promotion.name}</span>
                            )}
                          </p>
                        </div>
                        {canEditItems && (
                          <button
                            type="button"
                            onClick={() => removeItem(item)}
                            disabled={locked}
                            title="Eliminar partida de la cuenta"
                            className="shrink-0 cursor-pointer text-slate-400 transition-colors hover:text-rose-500 disabled:cursor-not-allowed disabled:opacity-40"
                          >
                            <i className="pi pi-trash text-sm" />
                          </button>
                        )}
                      </div>

                      <div className="mt-2 flex items-center justify-between">
                        {canEditItems ? (
                          <div className="flex items-center gap-1">
                            <button
                              type="button"
                              onClick={() => changeQuantity(item, item.quantity - 1)}
                              disabled={locked}
                              title={item.quantity <= 1 ? 'Retirar la partida' : 'Reducir cantidad'}
                              className="flex h-6 w-6 cursor-pointer items-center justify-center rounded bg-slate-100 text-sm font-bold text-slate-600 hover:bg-slate-200 disabled:cursor-not-allowed disabled:opacity-40"
                            >
                              -
                            </button>
                            <span className="w-8 text-center text-sm font-medium tabular-nums">
                              {busy ? <i className="pi pi-spin pi-spinner text-xs text-indigo-500" /> : item.quantity}
                            </span>
                            <button
                              type="button"
                              onClick={() => changeQuantity(item, item.quantity + 1)}
                              disabled={locked}
                              title="Aumentar cantidad"
                              className="flex h-6 w-6 cursor-pointer items-center justify-center rounded bg-slate-100 text-sm font-bold text-slate-600 hover:bg-slate-200 disabled:cursor-not-allowed disabled:opacity-40"
                            >
                              +
                            </button>
                          </div>
                        ) : (
                          /* Read-only for waiters: the quantity still reads the
                             same, only the controls that withdraw it are gone. */
                          <span className="text-sm font-medium text-slate-600 tabular-nums">
                            Cantidad: {item.quantity}
                          </span>
                        )}
                        <span className="text-sm font-semibold text-slate-900">
                          {fmtCurrency(item.final_price_at_sale)}
                        </span>
                      </div>
                    </li>
                  );
                })}
              </ul>
            )}
          </div>

          <div className="mt-3 rounded-lg bg-slate-50 p-3 text-sm">
            <div className="flex justify-between text-slate-600">
              <span>Subtotal</span><span>{fmtCurrency(session?.subtotal)}</span>
            </div>
            <div className="flex justify-between text-slate-600">
              <span>IVA</span><span>{fmtCurrency(session?.iva_total)}</span>
            </div>
            <div className="mt-2 flex justify-between border-t border-slate-200 pt-2 text-lg font-bold text-slate-900">
              <span>Total</span><span>{fmtCurrency(session?.total)}</span>
            </div>
          </div>

          <div className="mt-4 flex gap-3">
            <Button
              type="button"
              label="Cerrar"
              onClick={onHide}
              className="flex-1 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
              pt={{ root: { className: 'border border-slate-200' } }}
            />
            <Button
              type="button"
              label="Cobrar Cuenta"
              onClick={() => onCharge(session)}
              disabled={items.length === 0}
              className="flex-1 cursor-pointer rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50"
              pt={{ root: { className: 'border-0' } }}
            />
          </div>

          {/* Destructive path, kept visually apart from the charge action so a
              mis-tap cannot void an account that was about to be paid. */}
          <Button
            type="button"
            label="Cancelar Mesa"
            icon="pi pi-times-circle"
            onClick={() => setShowCancellation(true)}
            disabled={!session}
            className="mt-2 w-full cursor-pointer rounded-lg border border-rose-200 bg-white px-4 py-2.5 text-sm font-semibold text-rose-600 hover:bg-rose-50 disabled:opacity-50"
            pt={{ root: { className: 'border border-rose-200' } }}
          />
        </div>
      </div>

      <TableCancellationModal
        visible={showCancellation}
        table={table}
        session={session}
        onHide={() => setShowCancellation(false)}
        onCanceled={() => {
          // The table is free again: close both dialogs and let the floor plan
          // reload from the server rather than guessing the new state.
          setShowCancellation(false);
          onSessionChange?.();
          onHide();
        }}
      />
    </Dialog>
  );
}
