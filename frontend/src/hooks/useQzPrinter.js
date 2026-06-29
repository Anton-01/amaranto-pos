import { useState, useEffect, useRef, useCallback } from 'react';
import qz from 'qz-tray';
import api from '../api/axios';

const PRINTER_KEY = 'cronos_active_printer';

export default function useQzPrinter() {
  const [connected, setConnected] = useState(false);
  const [printerName, setPrinterName] = useState(() => localStorage.getItem(PRINTER_KEY) || '');
  const connectingRef = useRef(false);

  useEffect(() => {
    qz.security.setCertificatePromise((resolve, reject) => {
      api.get('/printer/certificate')
        .then(res => resolve(res.data))
        .catch(reject);
    });

    qz.security.setSignaturePromise((toSign) => {
      return (resolve, reject) => {
        api.post('/printer/sign', { requestString: toSign })
          .then(res => resolve(res.data))
          .catch(reject);
      };
    });
  }, []);

  const connect = useCallback(async () => {
    if (qz.websocket.isActive() || connectingRef.current) {
      setConnected(qz.websocket.isActive());
      return;
    }

    connectingRef.current = true;
    try {
      await qz.websocket.connect();
      setConnected(true);
    } catch {
      setConnected(false);
    } finally {
      connectingRef.current = false;
    }
  }, []);

  const disconnect = useCallback(async () => {
    if (qz.websocket.isActive()) {
      try {
        await qz.websocket.disconnect();
      } catch {}
    }
    setConnected(false);
  }, []);

  useEffect(() => {
    connect();
    return () => { disconnect(); };
  }, [connect, disconnect]);

  const savePrinterName = useCallback((name) => {
    setPrinterName(name);
    localStorage.setItem(PRINTER_KEY, name);
  }, []);

  const listPrinters = useCallback(async () => {
    if (!qz.websocket.isActive()) await connect();
    return qz.printers.find();
  }, [connect]);

  const printRaw = useCallback(async (base64Data) => {
    if (!qz.websocket.isActive()) await connect();

    const target = printerName || await qz.printers.getDefault();

    const config = qz.configs.create(target);
    const data = [{ type: 'raw', format: 'base64', data: base64Data }];

    await qz.print(config, data);
  }, [connect, printerName]);

  return {
    connected,
    printerName,
    savePrinterName,
    listPrinters,
    printRaw,
    connect,
    disconnect,
  };
}
