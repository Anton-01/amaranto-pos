import { useState, useEffect, useRef, useCallback } from 'react';
import api from '../api/axios';

const PRINTER_KEY = 'cronos_active_printer';
const AGENT_BASE_URL = 'http://127.0.0.1:9100';

function getApiToken() {
  try {
    const raw = localStorage.getItem('cronos_agent_token');
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

export default function useCronosAgent() {
  const [connected, setConnected] = useState(false);
  const [printerName, setPrinterName] = useState(() => localStorage.getItem(PRINTER_KEY) || '');
  const checkingRef = useRef(false);

  const checkConnection = useCallback(async () => {
    if (checkingRef.current) return;
    checkingRef.current = true;
    try {
      const res = await agentFetch('/api/printers');
      setConnected(res.ok);
    } catch {
      setConnected(false);
    } finally {
      checkingRef.current = false;
    }
  }, []);

  useEffect(() => {
    checkConnection();
  }, [checkConnection]);

  const savePrinterName = useCallback((name) => {
    setPrinterName(name);
    if (name) {
      localStorage.setItem(PRINTER_KEY, name);
    } else {
      localStorage.removeItem(PRINTER_KEY);
    }
  }, []);

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
        data: base64Data,
      }),
    });

    if (!res.ok) {
      const body = await res.json().catch(() => ({}));
      throw new Error(body.message || `Error de impresión: HTTP ${res.status}`);
    }

    return res.json();
  }, [printerName]);

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
        data: base64Pdf,
      }),
    });

    if (!res.ok) {
      const body = await res.json().catch(() => ({}));
      throw new Error(body.message || body.error || `Error de impresión PDF: HTTP ${res.status}`);
    }

    return res.json();
  }, [printerName]);

  return {
    connected,
    printerName,
    savePrinterName,
    getAvailablePrinters,
    printTicket,
    printRaw,
    printPDFDocument,
    getPrinterQueue,
    checkConnection,
  };
}
