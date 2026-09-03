import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Dialog } from 'primereact/dialog';
import { Button } from 'primereact/button';
import api from '../../api/axios';
import useOpenRegisterStatus from '../../hooks/useOpenRegisterStatus';
import RegisterClosedNotice from './RegisterClosedNotice';

const money = (n) =>
  `$${Number(n || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/**
 * Quick read of how the day's money splits between the investment fund and
 * profit, at whatever percentages are configured.
 *
 * DELIBERATELY THIN. The Panel Financiero already breaks the day down register
 * by register, by payment method and by arqueo; repeating any of that here
 * would make this a second, smaller version of a screen that already exists —
 * and a second place to keep in sync. This answers one question ("of today's
 * money, how much is fund and how much is profit?") and hands off to the panel
 * for everything else.
 *
 * The percentages are read from the payload rather than hard-coded at 70/30,
 * because the split is a configurable global setting.
 */
export default function DaySplitModal({ visible, onHide }) {
  const navigate = useNavigate();
  const { status, checking } = useOpenRegisterStatus(visible);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [failed, setFailed] = useState(false);

  const hasOpenRegister = status?.has_open_register === true;

  const fetchBreakdown = useCallback(async () => {
    setLoading(true);
    setFailed(false);
    try {
      const res = await api.get('/analytics/current-day');
      setData(res.data.data);
    } catch {
      setFailed(true);
      setData(null);
    } finally {
      setLoading(false);
    }
  }, []);

  /*
   * The figures are fetched only once the gate has actually answered yes. That
   * ordering is the requirement: no query for a day that has not started.
   */
  useEffect(() => {
    if (visible && hasOpenRegister) fetchBreakdown();
  }, [visible, hasOpenRegister, fetchBreakdown]);

  const goToFinance = () => {
    onHide();
    navigate('/finance');
  };

  const totals = data?.totals;
  const split = data?.split;

  return (
    <Dialog
      visible={visible}
      onHide={onHide}
      modal
      header={null}
      dismissableMask
      className="w-[calc(100vw-1.5rem)] max-w-lg sm:w-[90vw] lg:w-[32rem]"
      pt={{
        mask: { className: 'backdrop-blur-sm bg-black/30 p-3 sm:p-4' },
        root: { className: 'rounded-2xl border-0 shadow-2xl max-h-[90vh]' },
        content: { className: 'p-0 overflow-y-auto' },
      }}
    >
      <div className="p-4 sm:p-6">
        <div className="mb-5 flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50">
            <svg className="h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24" strokeWidth={1.75} stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 6a7.5 7.5 0 1 0 7.5 7.5h-7.5V6Z" />
              <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 10.5H21A7.5 7.5 0 0 0 13.5 3v7.5Z" />
            </svg>
          </div>
          <div>
            <h3 className="text-lg font-semibold text-slate-900">Reparto del Día</h3>
            <p className="text-xs text-slate-500">
              {new Date().toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}
            </p>
          </div>
        </div>

        {checking || (hasOpenRegister && loading) ? (
          <div className="flex h-40 items-center justify-center">
            <div className="h-6 w-6 animate-spin rounded-full border-[3px] border-indigo-600 border-t-transparent" />
          </div>
        ) : status === null ? (
          <p className="py-8 text-center text-sm text-slate-400">
            No se pudo verificar el estado de la caja. Intenta de nuevo.
          </p>
        ) : !hasOpenRegister ? (
          <RegisterClosedNotice status={status} onNavigate={onHide} />
        ) : failed || !totals ? (
          <p className="py-8 text-center text-sm text-slate-400">No se pudieron cargar los datos.</p>
        ) : (
          <div className="space-y-4">
            {/* The base the split is computed from. Shown because a fund and a
                profit figure with no visible origin invite the question
                "percentage of what?" on every read. */}
            <div className="rounded-xl bg-slate-50 p-3 sm:p-4">
              <p className="text-[11px] font-medium text-slate-500">Ingreso Neto del día (sin IVA)</p>
              <p className="mt-1 truncate text-2xl font-bold tabular-nums text-slate-900">{money(totals.net)}</p>
              <p className="mt-0.5 text-[11px] text-slate-400">
                {totals.orders} orden{totals.orders === 1 ? '' : 'es'} · base del reparto
              </p>
            </div>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div className="min-w-0 rounded-xl bg-blue-50 p-3 sm:p-4">
                <p className="text-[11px] font-medium text-blue-600">
                  Fondo de Inversión · {split.investment_pct}%
                </p>
                <p className="mt-1 truncate text-xl font-bold tabular-nums text-blue-900">
                  {money(totals.investment_fund)}
                </p>
              </div>
              <div className="min-w-0 rounded-xl bg-emerald-50 p-3 sm:p-4">
                <p className="text-[11px] font-medium text-emerald-600">
                  Utilidad Real · {split.profit_pct}%
                </p>
                <p className="mt-1 truncate text-xl font-bold tabular-nums text-emerald-900">
                  {money(totals.net_profit)}
                </p>
              </div>
            </div>

            {/* Money already counted in an arqueo versus money still inside an
                open drawer. One blended figure would read as if everything had
                been verified. */}
            <div className="rounded-xl border border-slate-200 p-3 sm:p-4">
              <div className="flex items-center justify-between gap-3">
                <span className="flex items-center gap-1.5 text-xs text-slate-600">
                  <i className="pi pi-lock text-[11px] text-slate-400" /> Ya liquidado en arqueo
                </span>
                <span className="text-sm font-semibold tabular-nums text-slate-900">
                  {money(totals.settled_net)}
                </span>
              </div>
              <div className="mt-2 flex items-center justify-between gap-3 border-t border-slate-100 pt-2">
                <span className="flex items-center gap-1.5 text-xs text-amber-700">
                  <i className="pi pi-clock text-[11px]" /> En curso (cajas abiertas)
                </span>
                <span className="text-sm font-semibold tabular-nums text-amber-700">
                  {money(totals.in_progress_net)}
                </span>
              </div>
            </div>

            <Button
              label="Ver detalle en el Panel Financiero"
              icon="pi pi-arrow-right"
              iconPos="right"
              onClick={goToFinance}
              className="w-full"
            />
          </div>
        )}
      </div>
    </Dialog>
  );
}
