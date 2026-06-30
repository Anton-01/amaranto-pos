import { useState, useEffect, useRef } from 'react';
import { Tag } from 'primereact/tag';
import { Button } from 'primereact/button';

const POLL_INTERVAL = 9000;

export default function PrinterQueueMonitor({ cronosAgent }) {
  const [queueData, setQueueData] = useState(null);
  const [retrying, setRetrying] = useState(false);
  const intervalRef = useRef(null);

  const isConnected = cronosAgent?.connected ?? false;
  const printerName = cronosAgent?.printerName || '';

  useEffect(() => {
    if (!isConnected || !printerName) {
      setQueueData(null);
      return;
    }

    const poll = async () => {
      try {
        const data = await cronosAgent.getPrinterQueue(printerName);
        setQueueData(data);
      } catch {
        setQueueData(null);
      }
    };

    poll();
    intervalRef.current = setInterval(poll, POLL_INTERVAL);

    return () => {
      if (intervalRef.current) clearInterval(intervalRef.current);
    };
  }, [isConnected, printerName, cronosAgent]);

  const handleRetry = async () => {
    if (!cronosAgent?.printTicket || !printerName) return;
    setRetrying(true);
    try {
      await cronosAgent.getPrinterQueue(printerName);
      const data = await cronosAgent.getPrinterQueue(printerName);
      setQueueData(data);
    } catch {
      // silently fail
    } finally {
      setRetrying(false);
    }
  };

  if (!isConnected) {
    return (
      <div className="flex items-center gap-2 rounded-lg bg-slate-50 border border-slate-200 px-3 py-2">
        <div className="h-2 w-2 rounded-full bg-slate-400" />
        <span className="text-xs text-slate-500">Cronos Agent desconectado</span>
      </div>
    );
  }

  if (!printerName) {
    return (
      <div className="flex items-center gap-2 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2">
        <i className="pi pi-exclamation-triangle text-xs text-amber-600" />
        <span className="text-xs text-amber-700">Sin impresora configurada</span>
      </div>
    );
  }

  const jobsCount = queueData?.jobs_count ?? 0;
  const hasStuckJobs = jobsCount > 0;
  const status = hasStuckJobs ? 'warning' : 'ok';

  return (
    <div className={`flex items-center gap-3 rounded-lg border px-3 py-2 ${
      hasStuckJobs
        ? 'bg-amber-50 border-amber-200'
        : 'bg-emerald-50 border-emerald-200'
    }`}>
      <div className={`h-2.5 w-2.5 rounded-full ${
        hasStuckJobs ? 'bg-amber-500 animate-pulse' : 'bg-emerald-500'
      }`} />

      <div className="flex-1 min-w-0">
        <p className="text-xs font-medium text-slate-700 truncate">
          {printerName}
        </p>
        <div className="flex items-center gap-2 mt-0.5">
          <Tag
            value={status === 'ok' ? 'Cola OK' : `${jobsCount} trabajo${jobsCount !== 1 ? 's' : ''} retenido${jobsCount !== 1 ? 's' : ''}`}
            severity={status === 'ok' ? 'success' : 'warning'}
            className="text-[10px]"
          />
        </div>
      </div>

      {hasStuckJobs && (
        <Button
          icon="pi pi-refresh"
          onClick={handleRetry}
          loading={retrying}
          disabled={retrying}
          className="cursor-pointer !h-7 !w-7 shrink-0"
          severity="warning"
          text
          rounded
          tooltip="Revisar cola de impresión"
          tooltipOptions={{ position: 'top' }}
        />
      )}
    </div>
  );
}
