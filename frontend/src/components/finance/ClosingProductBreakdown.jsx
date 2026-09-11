const fmt = (v) => `$${Number(v ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;

/**
 * Que se vendio durante el turno que cerro este arqueo.
 *
 * UNA SOLA IMPLEMENTACION PARA LOS DOS DETALLES. "Cierres de Caja" y
 * "Auditoria de Cierres" abren la misma radiografia desde dos pantallas
 * distintas; tener dos tablas paralelas es como se llega a que una muestre el
 * costo y la otra no despues del siguiente cambio.
 *
 * NULL NO ES LO MISMO QUE VACIO. `product_breakdown` es nullable porque los
 * cierres anteriores a la columna nacieron sin lista, y el registro es
 * inmutable: no se pueden rellenar. Un arqueo viejo dice "no incluye desglose";
 * uno nuevo sin ventas dice "no se vendio nada". Colapsar ambos casos en una
 * tabla vacia haria parecer que el turno no vendio.
 */
export default function ClosingProductBreakdown({ products }) {
  if (products == null) {
    return (
      <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-xs text-slate-500">
        Este cierre es anterior al desglose por producto, por lo que no incluye la lista de
        articulos vendidos. El registro es inmutable y no puede completarse.
      </div>
    );
  }

  if (products.length === 0) {
    return (
      <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-xs text-slate-500">
        No se vendio ningun producto durante este turno.
      </div>
    );
  }

  const pieces = products.reduce((sum, p) => sum + Number(p.quantity_sold ?? 0), 0);
  const revenue = products.reduce((sum, p) => sum + Number(p.revenue ?? 0), 0);
  const cost = products.reduce((sum, p) => sum + Number(p.cost ?? 0), 0);

  return (
    <div className="overflow-hidden rounded-lg border border-slate-200">
      {/* Scroll propio: un turno largo puede vender cuarenta articulos, y la
          lista no debe empujar el resto del arqueo fuera de la pantalla. */}
      <div className="max-h-64 overflow-y-auto">
        <table className="w-full text-sm">
          <thead className="sticky top-0 bg-slate-50">
            <tr className="border-b border-slate-200">
              <th className="px-3 py-2 text-left text-xs font-semibold text-slate-600">Producto</th>
              <th className="px-3 py-2 text-center text-xs font-semibold text-slate-600">Piezas</th>
              <th className="px-3 py-2 text-right text-xs font-semibold text-slate-600">Ingreso</th>
              <th className="px-3 py-2 text-right text-xs font-semibold text-slate-600">Costo</th>
            </tr>
          </thead>
          <tbody>
            {products.map((p, i) => (
              <tr key={p.product_id ?? `deleted-${i}`} className="border-b border-slate-100 last:border-0">
                <td className="px-3 py-2 text-slate-900">{p.name}</td>
                <td className="px-3 py-2 text-center">
                  <span className="inline-flex h-6 min-w-6 items-center justify-center rounded-full bg-indigo-100 px-2 text-xs font-semibold text-indigo-700">
                    {p.quantity_sold}
                  </span>
                </td>
                <td className="px-3 py-2 text-right font-medium tabular-nums text-slate-900">{fmt(p.revenue)}</td>
                <td className="px-3 py-2 text-right tabular-nums text-slate-600">
                  {/* El costo de un producto ya eliminado no se pudo capturar:
                      imprimir $0.00 ahi diria "no costo nada". */}
                  {p.cost == null ? <span className="text-slate-400">N/D</span> : fmt(p.cost)}
                </td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="border-t-2 border-slate-200 bg-slate-50 font-semibold">
              <td className="px-3 py-2 text-slate-700">{products.length} producto(s)</td>
              <td className="px-3 py-2 text-center tabular-nums text-slate-900">{pieces}</td>
              <td className="px-3 py-2 text-right tabular-nums text-slate-900">{fmt(revenue)}</td>
              <td className="px-3 py-2 text-right tabular-nums text-slate-900">{fmt(cost)}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  );
}
