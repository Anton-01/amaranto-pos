import { useState, useEffect } from 'react';
import { Dropdown } from 'primereact/dropdown';
import { Button } from 'primereact/button';
import { Tag } from 'primereact/tag';
import { toast } from 'sonner';

const PRINTER_KEY = 'cronos_active_printer';

export default function PrinterSetupPanel({ qzPrinter }) {
  const [printers, setPrinters] = useState([]);
  const [selectedPrinter, setSelectedPrinter] = useState(() => localStorage.getItem(PRINTER_KEY) || '');
  const [scanning, setScanning] = useState(false);
  const [connecting, setConnecting] = useState(false);

  const isConnected = qzPrinter?.connected ?? false;

  useEffect(() => {
    if (!isConnected && qzPrinter?.connect) {
      setConnecting(true);
      qzPrinter.connect().finally(() => setConnecting(false));
    }
  }, []);

  const handleScan = async () => {
    setScanning(true);
    try {
      if (!isConnected && qzPrinter?.connect) {
        await qzPrinter.connect();
      }
      const list = await qzPrinter.listPrinters();
      const printerOptions = (Array.isArray(list) ? list : []).map(name => ({
        label: name,
        value: name,
      }));
      setPrinters(printerOptions);
      if (printerOptions.length === 0) {
        toast.info('No se encontraron impresoras locales.');
      } else {
        toast.success(`${printerOptions.length} impresora(s) detectada(s).`);
      }
    } catch {
      toast.error('Error al escanear impresoras. Verifica que QZ Tray este ejecutandose.');
    } finally {
      setScanning(false);
    }
  };

  const handleSave = () => {
    if (!selectedPrinter) {
      toast.warning('Selecciona una impresora antes de guardar.');
      return;
    }
    localStorage.setItem(PRINTER_KEY, selectedPrinter);
    qzPrinter?.savePrinterName?.(selectedPrinter);
    toast.success(`Impresora "${selectedPrinter}" configurada como predeterminada.`);
  };

  const handleClear = () => {
    setSelectedPrinter('');
    localStorage.removeItem(PRINTER_KEY);
    qzPrinter?.savePrinterName?.('');
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
            <p className="text-xs text-slate-500">Selecciona la impresora termica conectada a esta terminal via QZ Tray.</p>
          </div>
          <div className="ml-auto">
            {isConnected ? (
              <Tag value="QZ Tray Conectado" severity="success" className="text-xs" />
            ) : connecting ? (
              <Tag value="Conectando..." severity="warning" className="text-xs" />
            ) : (
              <Tag value="QZ Tray Desconectado" severity="danger" className="text-xs" />
            )}
          </div>
        </div>
      </div>

      <div className="p-6 space-y-5">
        {!isConnected && !connecting && (
          <div className="rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
            <i className="pi pi-exclamation-triangle mr-2" />
            QZ Tray no esta conectado. Asegurate de que la aplicacion QZ Tray este ejecutandose en esta computadora.
          </div>
        )}

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
            disabled={scanning || (!isConnected && !qzPrinter?.connect)}
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
              <div className="flex gap-2">
                <Button
                  label="Guardar Configuracion"
                  icon="pi pi-check"
                  onClick={handleSave}
                  className="cursor-pointer rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500"
                  pt={{ root: { className: 'border-0' } }}
                />
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
          </div>
        )}

        {localStorage.getItem(PRINTER_KEY) && !selectedPrinter && (
          <div className="rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800">
            <i className="pi pi-check-circle mr-2" />
            Impresora predeterminada guardada: <strong className="font-mono">{localStorage.getItem(PRINTER_KEY)}</strong>
          </div>
        )}

        <div className="rounded-lg bg-slate-50 px-4 py-3 text-xs text-slate-500 space-y-1">
          <p><strong>Requisitos:</strong></p>
          <ul className="list-disc pl-4 space-y-0.5">
            <li>QZ Tray debe estar instalado y ejecutandose en esta computadora.</li>
            <li>La impresora termica debe estar conectada via USB o configurada en el sistema operativo.</li>
            <li>La configuracion se almacena localmente en este navegador.</li>
          </ul>
        </div>
      </div>
    </div>
  );
}
