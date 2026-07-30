import { useState } from 'react';
import { Tag } from 'primereact/tag';
import { Button } from 'primereact/button';
import { toast } from 'sonner';

/**
 * Consulta MANUAL del estado de la cola de impresion.
 *
 * No existe polling ni useEffect: la unica forma de consultar la cola es
 * pulsando "Consultar Estado de Cola". Asi se elimina el trafico en bucle
 * hacia el agente local y el parpadeo de la interfaz.
 */
export default function PrinterQueueMonitor({ cronosAgent }) {
  const [queueData, setQueueData] = useState(null);
  const [checking, setChecking] = useState(false);
  const [error, setError] = useState('');
  const [lastCheckedAt, setLastCheckedAt] = useState(null);

  const printerName = cronosAgent?.printerName || '';

  const handleCheckQueue = async () => {
    if (!printerName) {
      toast.warning('Selecciona y guarda una impresora antes de consultar la cola.');
      return;
    }

    setChecking(true);
    setError('');
    try {
      const data = await cronosAgent.getPrinterQueue(printerName);
      setQueueData(data);
      setLastCheckedAt(new Date());
    } catch (err) {
      setQueueData(null);
      setError(err.message || 'No se pudo consultar la cola de impresion.');
      toast.error('No se pudo consultar la cola. Verifica que el agente este en ejecucion.');
    } finally {
      setChecking(false);
    }
  };

  const jobsCount = queueData?.jobs_count ?? 0;
  const hasStuckJobs = jobsCount > 0;

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center gap-2">
        <Button
          label={checking ? 'Consultando...' : 'Consultar Estado de Cola'}
          icon="pi pi-search"
          onClick={handleCheckQueue}
          loading={checking}
          disabled={checking || !printerName}
          className="cursor-pointer rounded-lg bg-slate-700 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-600 disabled:opacity-50"
          pt={{ root: { className: 'border-0' } }}
        />
        {lastCheckedAt && (
          <span className="text-xs text-slate-400">
            Ultima consulta: {lastCheckedAt.toLocaleTimeString('es-MX')}
          </span>
        )}
      </div>

      {!printerName && (
        <div className="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
          <i className="pi pi-exclamation-triangle text-xs text-amber-600" />
          <span className="text-xs text-amber-700">Sin impresora configurada</span>
        </div>
      )}

      {error && (
        <div className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
          {error}
        </div>
      )}

      {queueData && !error && (
        <div className={`flex items-center gap-3 rounded-lg border px-3 py-2 ${
          hasStuckJobs ? 'bg-amber-50 border-amber-200' : 'bg-emerald-50 border-emerald-200'
        }`}>
          <div className={`h-2.5 w-2.5 rounded-full ${hasStuckJobs ? 'bg-amber-500' : 'bg-emerald-500'}`} />
          <div className="min-w-0 flex-1">
            <p className="truncate text-xs font-medium text-slate-700">{printerName}</p>
            <div className="mt-0.5">
              <Tag
                value={hasStuckJobs
                  ? `${jobsCount} trabajo${jobsCount !== 1 ? 's' : ''} retenido${jobsCount !== 1 ? 's' : ''}`
                  : 'Cola OK'}
                severity={hasStuckJobs ? 'warning' : 'success'}
                className="text-[10px]"
              />
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
