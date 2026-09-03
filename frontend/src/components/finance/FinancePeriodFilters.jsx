import { useCallback, useEffect, useState } from 'react';
import { Calendar } from 'primereact/calendar';
import { Dropdown } from 'primereact/dropdown';
import { Button } from 'primereact/button';
import api from '../../api/axios';
import { PERIOD_PRESETS, matchesPreset } from './financePeriods';

/**
 * Period selector plus the collapsible Advanced Filters block.
 *
 * WHY THE ADVANCED BLOCK IS COLLAPSED BY DEFAULT AND SUMMARIZED WHEN CLOSED.
 * A filter that is active but out of sight is how somebody reads a figure for
 * the whole business when it only covers one cashier. Closing the block hides
 * the controls, never the fact that they are set: the chip row below the header
 * keeps naming every active filter until it is removed.
 */
export default function FinancePeriodFilters({ value, onChange, onExport, exporting, deductionsComparable }) {
  const [open, setOpen] = useState(false);
  const [catalogs, setCatalogs] = useState({ payment_methods: [], operators: [], cash_registers: [] });

  useEffect(() => {
    api
      .get('/analytics/catalogs')
      .then((res) => setCatalogs(res.data.data))
      .catch(() => setCatalogs({ payment_methods: [], operators: [], cash_registers: [] }));
  }, []);

  const patch = useCallback((changes) => onChange({ ...value, ...changes }), [onChange, value]);

  const activePreset = PERIOD_PRESETS.find((p) => matchesPreset(value.range, p));

  const registerOptions = catalogs.cash_registers.map((r) => ({
    value: r.id,
    label: `${r.operator_name ?? '—'} · ${new Date(r.opened_at).toLocaleDateString('es-MX', {
      day: '2-digit', month: 'short',
    })} ${r.closed_at ? '' : '(abierta)'}`,
  }));

  // Chips describe the state, so a collapsed block can never hide an active
  // filter. Each carries its own removal.
  const chips = [
    value.payment_method_id && {
      key: 'payment_method_id',
      label: `Método: ${catalogs.payment_methods.find((m) => m.id === value.payment_method_id)?.name ?? '—'}`,
    },
    value.user_id && {
      key: 'user_id',
      label: `Operador: ${catalogs.operators.find((o) => o.id === value.user_id)?.name ?? '—'}`,
    },
    value.cash_register_id && {
      key: 'cash_register_id',
      label: `Caja: ${registerOptions.find((r) => r.value === value.cash_register_id)?.label ?? '—'}`,
    },
  ].filter(Boolean);

  return (
    <div className="mb-6 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:p-5">
      <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div className="flex flex-wrap gap-1.5">
          {PERIOD_PRESETS.map((preset) => (
            <button
              key={preset.key}
              type="button"
              onClick={() => patch({ range: preset.range() })}
              aria-pressed={activePreset?.key === preset.key}
              className={`cursor-pointer rounded-lg px-3 py-2 text-xs font-semibold transition-colors ${
                activePreset?.key === preset.key
                  ? 'bg-indigo-600 text-white'
                  : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
              }`}
            >
              {preset.label}
            </button>
          ))}
        </div>

        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
          <Calendar
            value={value.range}
            onChange={(e) => patch({ range: e.value })}
            selectionMode="range"
            readOnlyInput
            dateFormat="dd/mm/yy"
            placeholder="Rango personalizado"
            className="w-full sm:w-auto"
            pt={{
              root: { className: 'w-full sm:w-auto' },
              input: { root: { className: 'w-full rounded-lg border-slate-200 px-3 py-2 text-sm sm:w-56' } },
            }}
          />

          <Button
            icon={open ? 'pi pi-filter-slash' : 'pi pi-filter'}
            label="Filtros avanzados"
            size="small"
            outlined={!chips.length}
            severity={chips.length ? 'info' : undefined}
            badge={chips.length ? String(chips.length) : undefined}
            onClick={() => setOpen((prev) => !prev)}
            aria-expanded={open}
            className="w-full sm:w-auto"
          />

          <Button
            icon="pi pi-file-excel"
            label="Exportar Excel"
            size="small"
            severity="success"
            loading={exporting}
            onClick={onExport}
            className="w-full sm:w-auto"
          />
        </div>
      </div>

      {chips.length > 0 && (
        <div className="mt-3 flex flex-wrap items-center gap-1.5">
          {chips.map((chip) => (
            <button
              key={chip.key}
              type="button"
              onClick={() => patch({ [chip.key]: null })}
              className="flex cursor-pointer items-center gap-1 rounded-full bg-indigo-50 px-2.5 py-1 text-[11px] font-medium text-indigo-700 transition-colors hover:bg-indigo-100"
            >
              {chip.label}
              <i className="pi pi-times text-[9px]" />
            </button>
          ))}
          <button
            type="button"
            onClick={() => patch({ payment_method_id: null, user_id: null, cash_register_id: null })}
            className="cursor-pointer px-2 text-[11px] font-semibold text-slate-500 hover:text-slate-700"
          >
            Limpiar todo
          </button>
        </div>
      )}

      {open && (
        <div className="mt-4 grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-3">
          <div>
            <label className="mb-1 block text-xs font-semibold text-slate-600">Método de pago</label>
            <Dropdown
              value={value.payment_method_id}
              options={catalogs.payment_methods.map((m) => ({ value: m.id, label: m.name }))}
              optionLabel="label"
              optionValue="value"
              onChange={(e) => patch({ payment_method_id: e.value })}
              placeholder="Todos"
              showClear
              className="w-full"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-semibold text-slate-600">Operador</label>
            <Dropdown
              value={value.user_id}
              options={catalogs.operators.map((o) => ({ value: o.id, label: o.name }))}
              optionLabel="label"
              optionValue="value"
              onChange={(e) => patch({ user_id: e.value })}
              placeholder="Todos"
              showClear
              filter
              className="w-full"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-semibold text-slate-600">Caja específica</label>
            <Dropdown
              value={value.cash_register_id}
              options={registerOptions}
              optionLabel="label"
              optionValue="value"
              onChange={(e) => patch({ cash_register_id: e.value })}
              placeholder="Todas"
              showClear
              filter
              className="w-full"
            />
          </div>
        </div>
      )}

      {/*
        The honest caveat. Petty cash and stock purchases have no payment method
        and belong to no single register, so those filters cannot reach them.
        With one active, the income is a slice and the deductions are the whole
        business — their difference is not a number anybody should act on, and
        saying so is the only responsible thing a financial panel can do.
      */}
      {deductionsComparable === false && (
        <div className="mt-3 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3">
          <i className="pi pi-exclamation-triangle mt-0.5 text-sm text-amber-600" />
          <p className="text-[11px] leading-relaxed text-amber-900">
            Los filtros de <strong>método de pago</strong> y <strong>caja</strong> no aplican a la caja chica
            ni a las compras de stock: esos movimientos no pertenecen a un método de pago ni a una caja.
            Los ingresos están filtrados y las deducciones no, así que el <strong>remanente del fondo</strong>{' '}
            no es comparable en esta vista. Quita esos filtros para una lectura contable del remanente.
          </p>
        </div>
      )}
    </div>
  );
}
