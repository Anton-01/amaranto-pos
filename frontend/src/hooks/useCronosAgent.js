import { useState, useCallback } from 'react';

const PRINTER_KEY = 'cronos_active_printer';
const AGENT_TOKEN_KEY = 'cronos_agent_token';
const AGENT_BASE_URL = 'http://127.0.0.1:9100';

// Estados posibles del agente local. 'unknown' es el estado inicial en un
// navegador limpio: NO significa "desconectado", significa "aun sin verificar".
export const AGENT_STATUS = {
  UNKNOWN: 'unknown',
  ONLINE: 'online',
  OFFLINE: 'offline',
};

// Lectura dinamica en CADA peticion: el header X-Cronos-Agent-Token
// siempre viaja con el valor vigente de localStorage, sin recargar la app
function getApiToken() {
  try {
    const raw = localStorage.getItem(AGENT_TOKEN_KEY);
    return raw || '';
  } catch {
    return '';
  }
}

function agentFetch(path, options = {}) {
  const token = getApiToken();
  const headers = {
    'Content-Type': 'application/json',
    ...(token ? { 'X-Cronos-Agent-Token': token } : {}),
    ...options.headers,
  };

  return fetch(`${AGENT_BASE_URL}${path}`, {
    ...options,
    headers,
  });
}

/**
 * Hook de integracion con Cronos POS Agent (http://127.0.0.1:9100).
 *
 * IMPORTANTE: este hook NO dispara ninguna peticion automatica al montarse.
 * Toda comunicacion con el agente es explicita (deteccion manual, impresion
 * bajo demanda o consulta manual de la cola). Esto elimina el parpadeo y las
 * llamadas en bucle que se producian al entrar al Historico de Ventas o a la
 * pantalla de Configuracion.
 */
export default function useCronosAgent() {
  const [status, setStatus] = useState(AGENT_STATUS.UNKNOWN);
  const [agentVersion, setAgentVersion] = useState('');
  const [detecting, setDetecting] = useState(false);
  const [printerName, setPrinterName] = useState(() => {
    try {
      return localStorage.getItem(PRINTER_KEY) || '';
    } catch {
      return '';
    }
  });

  /**
   * Deteccion MANUAL del agente local: GET /api/health.
   * Retorna { ok, version, error } sin lanzar excepciones.
   */
  const detectAgent = useCallback(async () => {
    setDetecting(true);
    try {
      const res = await agentFetch('/api/health');
      if (!res.ok) throw new Error(`HTTP ${res.status}`);

      const body = await res.json().catch(() => ({}));
      const version = body.version || body.agent_version || body.data?.version || '';

      setAgentVersion(version);
      setStatus(AGENT_STATUS.ONLINE);
      return { ok: true, version, data: body };
    } catch (err) {
      setAgentVersion('');
      setStatus(AGENT_STATUS.OFFLINE);
      return { ok: false, error: err.message || String(err) };
    } finally {
      setDetecting(false);
    }
  }, []);

  const savePrinterName = useCallback((name) => {
    setPrinterName(name);
    try {
      if (name) {
        localStorage.setItem(PRINTER_KEY, name);
      } else {
        localStorage.removeItem(PRINTER_KEY);
      }
    } catch {
      // localStorage no disponible (modo privado): la seleccion vive en memoria
    }
  }, []);

  const saveAgentToken = useCallback((token) => {
    const value = (token || '').trim();
    try {
      if (value) {
        localStorage.setItem(AGENT_TOKEN_KEY, value);
      } else {
        localStorage.removeItem(AGENT_TOKEN_KEY);
      }
    } catch {
      // Sin persistencia: el token se pedira de nuevo en la proxima sesion
    }
  }, []);

  const getAgentToken = useCallback(() => getApiToken(), []);

  const getAvailablePrinters = useCallback(async () => {
    const res = await agentFetch('/api/printers');
    if (!res.ok) throw new Error(`Cronos Agent respondió con estado ${res.status}`);
    const data = await res.json();
    return data.printers || data;
  }, []);

  const printTicket = useCallback(async (targetPrinter, base64Data) => {
    const printer = targetPrinter || printerName;
    if (!printer) throw new Error('No hay impresora configurada.');

    const res = await agentFetch('/api/print', {
      method: 'POST',
      body: JSON.stringify({
        printer_name: printer,
        printer_data: base64Data,
      }),
    });

    if (!res.ok) {
      const body = await res.json().catch(() => ({}));
      throw new Error(body.message || `Error de impresión: HTTP ${res.status}`);
    }

    return res.json();
  }, [printerName]);

  /** Consulta MANUAL de la cola de impresion (sin polling). */
  const getPrinterQueue = useCallback(async (targetPrinter) => {
    const printer = targetPrinter || printerName;
    if (!printer) return { jobs_count: 0, status: 'ok' };

    const res = await agentFetch(`/api/printers/queue?printer_name=${encodeURIComponent(printer)}`);
    if (!res.ok) throw new Error(`Error al consultar cola: HTTP ${res.status}`);
    return res.json();
  }, [printerName]);

  const printRaw = useCallback(async (base64Data) => {
    await printTicket(printerName, base64Data);
  }, [printTicket, printerName]);

  const printPDFDocument = useCallback(async (targetPrinter, base64Pdf) => {
    const printer = targetPrinter || printerName;
    if (!printer) throw new Error('No hay impresora configurada para impresión PDF.');

    const res = await agentFetch('/api/print/pdf', {
      method: 'POST',
      body: JSON.stringify({
        printer_name: printer,
        printer_data: base64Pdf,
      }),
    });

    if (!res.ok) {
      const body = await res.json().catch(() => ({}));
      throw new Error(body.message || body.error || `Error de impresión PDF: HTTP ${res.status}`);
    }

    return res.json();
  }, [printerName]);

  return {
    status,
    agentVersion,
    detecting,
    detected: status === AGENT_STATUS.ONLINE,
    connected: status === AGENT_STATUS.ONLINE,
    isConfigured: Boolean(printerName),
    printerName,
    detectAgent,
    savePrinterName,
    saveAgentToken,
    getAgentToken,
    getAvailablePrinters,
    printTicket,
    printRaw,
    printPDFDocument,
    getPrinterQueue,
  };
}
