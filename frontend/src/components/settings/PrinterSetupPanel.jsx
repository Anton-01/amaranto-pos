import { useState, useEffect } from 'react';
import { Dropdown } from 'primereact/dropdown';
import { InputText } from 'primereact/inputtext';
import { Button } from 'primereact/button';
import { Tag } from 'primereact/tag';
import { toast } from 'sonner';
import PrinterQueueMonitor from '../pos/PrinterQueueMonitor';

const PRINTER_KEY = 'cronos_active_printer';
const AGENT_TOKEN_KEY = 'cronos_agent_token';

export default function PrinterSetupPanel({ cronosAgent }) {
  const [printers, setPrinters] = useState([]);
  const [selectedPrinter, setSelectedPrinter] = useState(() => localStorage.getItem(PRINTER_KEY) || '');
  const [agentToken, setAgentToken] = useState(() => localStorage.getItem(AGENT_TOKEN_KEY) || '');
  const [showToken, setShowToken] = useState(false);
  const [scanning, setScanning] = useState(false);

  const isConnected = cronosAgent?.connected ?? false;

  useEffect(() => {
    if (!isConnected && cronosAgent?.checkConnection) {
      cronosAgent.checkConnection();
    }
  }, []);

  const handleScan = async () => {
    setScanning(true);
    try {
      const list = await cronosAgent.getAvailablePrinters();
      const printerOptions = (Array.isArray(list) ? list : []).map(name => ({
        label: typeof name === 'string' ? name : name.name || String(name),
        value: typeof name === 'string' ? name : name.name || String(name),
      }));
      setPrinters(printerOptions);
      if (printerOptions.length === 0) {
        toast.info('No se encontraron impresoras locales.');
      } else {
        toast.success(`${printerOptions.length} impresora(s) detectada(s).`);
      }
    } catch {
      toast.error('Error al escanear impresoras. Verifica que Cronos Agent este ejecutandose en http://127.0.0.1:9100 y que el Token del Agente sea correcto.');
    } finally {
      setScanning(false);
    }
  };

  const handleSave = () => {
    const token = agentToken.trim();

    if (!token && !selectedPrinter && !localStorage.getItem(AGENT_TOKEN_KEY)) {
      toast.warning('Ingresa el token del agente o selecciona una impresora antes de guardar.');
      return;
    }

    // Persistir token (o limpiarlo si el campo quedo vacio)
    if (token) {
      localStorage.setItem(AGENT_TOKEN_KEY, token);
    } else {
      localStorage.removeItem(AGENT_TOKEN_KEY);
    }
    setAgentToken(token);

    // Persistir impresora seleccionada (si hay una)
    if (selectedPrinter) {
      localStorage.setItem(PRINTER_KEY, selectedPrinter);
      cronosAgent?.savePrinterName?.(selectedPrinter);
    }

    // Re-verificar conexion con el agente usando el token recien guardado
    cronosAgent?.checkConnection?.();

    if (token && selectedPrinter) {
      toast.success(`Configuracion guardada: token del agente e impresora "${selectedPrinter}".`);
    } else if (token) {
      toast.success('Token del agente guardado. Ya puedes escanear impresoras.');
    } else if (selectedPrinter) {
      toast.success(`Impresora "${selectedPrinter}" configurada como predeterminada.`);
    } else {
      toast.info('Token del agente eliminado.');
    }
  };

  const handleClear = () => {
    setSelectedPrinter('');
    localStorage.removeItem(PRINTER_KEY);
    cronosAgent?.savePrinterName?.('');
    toast.info('Configuracion de impresora eliminada. Se usara la impresora predeterminada del sistema.');
  };

  return (
    <div className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      <div className="border-b border-slate-200 px-6 py-4">
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-50">
            <i className="pi pi-print text-lg text-indigo-600" />
          </div>
          <div>
            <h2 className="text-lg font-semibold text-slate-900">Configuracion de Impresora Local</h2>
            <p className="text-xs text-slate-500">Selecciona la impresora termica conectada a esta terminal via Cronos POS Agent.</p>
          </div>
          <div className="ml-auto">
            {isConnected ? (
              <Tag value="Cronos Agent Conectado" severity="success" className="text-xs" />
            ) : (
              <Tag value="Cronos Agent Desconectado" severity="danger" className="text-xs" />
            )}
          </div>
        </div>
      </div>

      <div className="p-6 space-y-5">
        {!isConnected && (
          <div className="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
            <i className="pi pi-exclamation-triangle mr-2" />
            Cronos POS Agent no esta conectado. Asegurate de que el agente este ejecutandose en <strong>http://127.0.0.1:9100</strong> y de que el Token del Agente este configurado y sea valido.
          </div>
        )}

        <div>
          <label htmlFor="cronos-agent-token" className="mb-1.5 block text-sm font-medium text-slate-700">Token del Agente Cronos</label>
          <div className="flex items-center gap-2">
            <InputText
              id="cronos-agent-token"
              type={showToken ? 'text' : 'password'}
              value={agentToken}
              onChange={(e) => setAgentToken(e.target.value)}
              placeholder="Pega aqui el token de autenticacion del agente..."
              autoComplete="off"
              className="w-full text-sm font-mono"
            />
            <Button
              type="button"
              icon={showToken ? 'pi pi-eye-slash' : 'pi pi-eye'}
              onClick={() => setShowToken((v) => !v)}
              severity="secondary"
              text
              rounded
              className="cursor-pointer shrink-0 !h-9 !w-9"
              tooltip={showToken ? 'Ocultar token' : 'Mostrar token'}
              tooltipOptions={{ position: 'top' }}
            />
          </div>
          <p className="mt-1.5 text-xs text-slate-500">
            Puedes encontrar este token dentro del archivo <strong className="font-mono">config.json</strong> en la carpeta de instalacion del agente.
          </p>
        </div>

        <div className="flex items-end gap-3">
          <div className="flex-1">
            <label className="mb-1.5 block text-sm font-medium text-slate-700">Impresoras Detectadas</label>
            <Dropdown
              value={selectedPrinter}
              options={printers}
              onChange={(e) => setSelectedPrinter(e.value)}
              placeholder={printers.length === 0 ? 'Presiona "Escanear" para detectar impresoras...' : 'Selecciona una impresora'}
              disabled={printers.length === 0}
              className="w-full text-sm"
              pt={{ root: { className: 'w-full' } }}
              filter={printers.length > 5}
              filterPlaceholder="Buscar impresora..."
            />
          </div>
          <Button
            label={scanning ? 'Escaneando...' : 'Escanear Impresoras Locales'}
            icon="pi pi-refresh"
            onClick={handleScan}
            loading={scanning}
            disabled={scanning || !isConnected}
            className="cursor-pointer shrink-0 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
            pt={{ root: { className: 'border-0' } }}
          />
        </div>

        {selectedPrinter && (
          <div className="rounded-lg bg-slate-50 border border-slate-200 px-4 py-3">
            <div className="flex items-center justify-between">
              <div>
                <span className="text-xs font-medium text-slate-500 uppercase tracking-wider">Impresora Seleccionada</span>
                <p className="mt-0.5 text-sm font-semibold text-slate-900 font-mono">{selectedPrinter}</p>
              </div>
              <Button
                icon="pi pi-times"
                onClick={handleClear}
                severity="secondary"
                text
                rounded
                className="cursor-pointer !h-9 !w-9"
                tooltip="Limpiar seleccion"
                tooltipOptions={{ position: 'top' }}
              />
            </div>
          </div>
        )}

        <div className="flex justify-end">
          <Button
            label="Guardar Configuracion"
            icon="pi pi-check"
            onClick={handleSave}
            className="cursor-pointer rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500"
            pt={{ root: { className: 'border-0' } }}
          />
        </div>

        {localStorage.getItem(PRINTER_KEY) && !selectedPrinter && (
          <div className="rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">
            <i className="pi pi-check-circle mr-2" />
            Impresora predeterminada guardada: <strong className="font-mono">{localStorage.getItem(PRINTER_KEY)}</strong>
          </div>
        )}

        {isConnected && cronosAgent?.printerName && (
          <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">Monitor de Cola de Impresion</label>
            <PrinterQueueMonitor cronosAgent={cronosAgent} />
          </div>
        )}

        <div className="rounded-lg bg-slate-50 px-4 py-3 text-xs text-slate-500 space-y-1">
          <p><strong>Requisitos:</strong></p>
          <ul className="list-disc pl-4 space-y-0.5">
            <li>Cronos POS Agent debe estar instalado y ejecutandose en esta computadora (puerto 9100).</li>
            <li>El Token del Agente debe coincidir con el valor de <strong className="font-mono">config.json</strong> del agente instalado.</li>
            <li>La impresora termica debe estar conectada via USB o configurada en el sistema operativo.</li>
            <li>La configuracion se almacena localmente en este navegador.</li>
          </ul>
        </div>
      </div>
    </div>
  );
}
