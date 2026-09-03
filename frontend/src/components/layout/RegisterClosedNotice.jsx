import { Button } from 'primereact/button';
import { useNavigate } from 'react-router-dom';

/**
 * The gate both header modals show when no drawer is open.
 *
 * Shared rather than written twice so the two modals cannot drift into telling
 * the operator two different things about the same condition.
 *
 * The wording branches on whose register is missing: a cashier with no drawer
 * of their own is told to open one and handed the shortcut to do it, while a
 * supervisor is told the shift has not started — an instruction they cannot act
 * on themselves would only be noise on their screen.
 */
export default function RegisterClosedNotice({ status, onNavigate }) {
  const navigate = useNavigate();
  const canOpenOwn = status?.has_own_register === false;

  const goToPos = () => {
    onNavigate?.();
    navigate('/pos');
  };

  return (
    <div className="flex flex-col items-center gap-3 px-4 py-10 text-center">
      <div className="flex h-14 w-14 items-center justify-center rounded-full bg-amber-50">
        <i className="pi pi-lock text-2xl text-amber-500" />
      </div>

      <div>
        <p className="text-base font-semibold text-slate-900">No hay caja abierta</p>
        <p className="mx-auto mt-1 max-w-sm text-sm text-slate-500">
          {canOpenOwn
            ? 'Para consultar la información del día primero debes abrir una caja desde el Punto de Venta.'
            : 'El turno aún no ha iniciado. Cuando se abra una caja, la información del día estará disponible aquí.'}
        </p>
      </div>

      {canOpenOwn && (
        <Button
          label="Abrir caja"
          icon="pi pi-inbox"
          size="small"
          onClick={goToPos}
          className="mt-1"
        />
      )}
    </div>
  );
}
