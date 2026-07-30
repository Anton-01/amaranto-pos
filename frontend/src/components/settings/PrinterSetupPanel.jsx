import { useState } from 'react';
import { Dropdown } from 'primereact/dropdown';
import { InputText } from 'primereact/inputtext';
import { Button } from 'primereact/button';
import { toast } from 'sonner';
import PrinterQueueMonitor from '../pos/PrinterQueueMonitor';
import { AGENT_STATUS } from '../../hooks/useCronosAgent';

const PRINTER_KEY = 'cronos_active_printer';
const AGENT_TOKEN_KEY = 'cronos_agent_token';

function readStorage(key) {
  try {
    return localStorage.getItem(key) || '';
  } catch {
    return '';
  }
}

/**
 * Panel de configuracion de impresora local.
 *
 * Flujo 100% manual y pensado para navegadores limpios: al entrar no se
 * dispara ninguna peticion al agente. El estado inicial es "sin verificar"
 * (nunca "desconectado") y el usuario inicia todo con "Detectar Agente Local".
 */
export default function PrinterSetupPanel({ cronosAgent }) {
  const [printers, setPrinters] = useState([]);
  const [selectedPrinter, setSelectedPrinter] = useState(() => readStorage(PRINTER_KEY));
  const [agentToken, setAgentToken] = useState(() => readStorage(AGENT_TOKEN_KEY));
  const [showToken, setShowToken] = useState(false);
  const [showTokenHelp, setShowTokenHelp] = useState(false);
  const [scanning, setScanning] = useState(false);
  const [printersError, setPrintersError] = useState('');

  const status = cronosAgent?.status ?? AGENT_STATUS.UNKNOWN;
  const detecting = cronosAgent?.detecting ?? false;
  const agentVersion = cronosAgent?.agentVersion || '';
  const isOnline = status === AGENT_STATUS.ONLINE;
  const isOffline = status === AGENT_STATUS.OFFLINE;
  const savedPrinter = cronosAgent?.printerName || '';
  const hasStoredSetup = Boolean(savedPrinter || readStorage(AGENT_TOKEN_KEY));

  const loadPrinters = async ({ silent = false } = {}) => {
    setScanning(true);
    setPrintersError('');
    try {
      const list = await cronosAgent.getAvailablePrinters();
      const printerOptions = (Array.isArray(list) ? list : []).map(name => ({
        label: typeof name === 'string' ? name : name.name || String(name),
        value: typeof name === 'string' ? name : name.name || String(name),
      }));
      setPrinters(printerOptions);

      if (printerOptions.length === 0) {
        toast.info('El agente respondio, pero no reporto impresoras instaladas.');
      } else if (!silent) {
        toast.success(`${printerOptions.length} impresora(s) detectada(s).`);
      }
      return printerOptions;
    } catch (err) {
      setPrinters([]);
      setPrintersError(
        'No se pudo obtener la lista de impresoras. Si el agente exige autenticacion, pega el Token de Seguridad y vuelve a intentarlo.'
      );
      if (!silent) {
        toast.error(`Error al listar impresoras: ${err.message || err}`);
      }
      return [];
    } finally {
      setScanning(false);
    }
  };

  // Deteccion manual: unico disparador de trafico hacia el agente al entrar
  const handleDetectAgent = async () => {
    const result = await cronosAgent.detectAgent();

    if (!result.ok) {
      setPrinters([]);
      setPrintersError('');
      toast.error('Agente No Detectado', {
        description: 'Verifica que cronos-pos-agent.exe este en ejecucion en esta computadora.',
      });
      return;
    }

    toast.success(`Agente detectado${result.version ? ` v${result.version}` : ''}.`);
    await loadPrinters({ silent: true });
  };

  const handleSaveToken = async () => {
    const token = agentToken.trim();
    cronosAgent.saveAgentToken(token);
    setAgentToken(token);

    if (!token) {
      toast.info('Token del agente eliminado.');
      return;
    }

    toast.success('Token guardado. Actualizando lista de impresoras...');
    await loadPrinters({ silent: true });
  };

  const handleSavePrinter = () => {
    if (!selectedPrinter) {
      toast.warning('Selecciona una impresora antes de guardar.');
      return;
    }
    cronosAgent.savePrinterName(selectedPrinter);
    toast.success(`Impresora "${selectedPrinter}" configurada como predeterminada.`);
  };

  const handleClear = () => {
    setSelectedPrinter('');
    cronosAgent.savePrinterName('');
    toast.info('Configuracion de impresora eliminada. Se usara la impresora predeterminada del sistema.');
  };

  const statusBadge = () => {
    if (isOnline) {
      return (
        <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
          🟢 Agente Detectado{agentVersion ? ` v${agentVersion}` : ''}
        </span>
      );
    }
    if (isOffline) {
      return (
        <span className="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-700 ring-1 ring-rose-200">
          🔴 Agente No Detectado
        </span>
      );
    }
    return (
      <span className="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
        ⚪ Agente sin verificar
      </span>
    );
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
          <div className="ml-auto">{statusBadge()}</div>
        </div>
      </div>

      <div className="p-6 space-y-5">
        {/* Paso 1 — Deteccion manual del agente */}
        <div className="rounded-xl border border-indigo-100 bg-indigo-50/60 px-4 py-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="min-w-0">
              <p className="text-sm font-semibold text-slate-900">Paso 1 — Conecta esta terminal con el agente</p>
              <p className="mt-0.5 text-xs text-slate-600">
                Cronos POS Agent se ejecuta en esta computadora y escucha en <strong className="font-mono">http://127.0.0.1:9100</strong>.
                Pulsa el boton para verificar su estado; no se realiza ninguna consulta automatica.
              </p>
            </div>
            <Button
              label={detecting ? 'Detectando...' : 'Detectar Agente Local'}
              icon="pi pi-bolt"
              onClick={handleDetectAgent}
              loading={detecting}
              disabled={detecting}
              className="cursor-pointer shrink-0 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
              pt={{ root: { className: 'border-0' } }}
            />
          </div>

          {status === AGENT_STATUS.UNKNOWN && !hasStoredSetup && (
            <p className="mt-3 text-xs text-slate-500">
              Esta terminal aun no tiene configuracion de impresora guardada. Comienza detectando el agente local.
            </p>
          )}

          {isOffline && (
            <div className="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-xs text-rose-700">
              <p className="font-semibold">🔴 Agente No Detectado</p>
              <ul className="mt-1 list-disc space-y-0.5 pl-4">
                <li>Revisa si <strong className="font-mono">cronos-pos-agent.exe</strong> esta en ejecucion en esta computadora.</li>
                <li>Busca el icono de Cronos junto al reloj de la barra de tareas.</li>
                <li>Confirma que ningun firewall bloquee el puerto <strong className="font-mono">9100</strong>.</li>
              </ul>
            </div>
          )}
        </div>

        {/* Paso 2 — Impresoras y token (solo tras detectar o con config previa) */}
        {(isOnline || hasStoredSetup) && (
          <>
            <div className="flex items-end gap-3">
              <div className="flex-1">
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Impresoras Detectadas</label>
                <Dropdown
                  value={selectedPrinter}
                  options={printers}
                  onChange={(e) => setSelectedPrinter(e.value)}
                  placeholder={printers.length === 0
                    ? 'Detecta el agente para listar las impresoras...'
                    : 'Selecciona una impresora'}
                  disabled={printers.length === 0}
                  className="w-full text-sm"
                  pt={{ root: { className: 'w-full' } }}
                  filter={printers.length > 5}
                  filterPlaceholder="Buscar impresora..."
                />
              </div>
              <Button
                label={scanning ? 'Actualizando...' : 'Actualizar Lista'}
                icon="pi pi-refresh"
                onClick={() => loadPrinters()}
                loading={scanning}
                disabled={scanning}
                className="cursor-pointer shrink-0 rounded-lg border border-indigo-200 bg-white px-4 py-2.5 text-sm font-semibold text-indigo-700 hover:bg-indigo-50 disabled:opacity-50"
                pt={{ root: { className: 'border border-indigo-200' } }}
              />
            </div>

            {printersError && (
              <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">
                <i className="pi pi-exclamation-triangle mr-1.5" />
                {printersError}
              </div>
            )}

            {selectedPrinter && (
              <div className="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                <div className="flex items-center justify-between">
                  <div>
                    <span className="text-xs font-medium uppercase tracking-wider text-slate-500">Impresora Seleccionada</span>
                    <p className="mt-0.5 font-mono text-sm font-semibold text-slate-900">{selectedPrinter}</p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Button
                      label="Guardar Impresora"
                      icon="pi pi-check"
                      onClick={handleSavePrinter}
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

            {savedPrinter && savedPrinter !== selectedPrinter && (
              <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                <i className="pi pi-check-circle mr-2" />
                Impresora predeterminada guardada: <strong className="font-mono">{savedPrinter}</strong>
              </div>
            )}

            {/* Token de seguridad del agente */}
            <div>
              <label htmlFor="cronos-agent-token" className="mb-1.5 block text-sm font-medium text-slate-700">
                Token de Seguridad del Agente
              </label>
              <div className="flex items-center gap-2">
                <InputText
                  id="cronos-agent-token"
                  type={showToken ? 'text' : 'password'}
                  value={agentToken}
                  onChange={(e) => setAgentToken(e.target.value)}
                  placeholder="Pega aqui el token de seguridad del agente..."
                  autoComplete="off"
                  className="w-full font-mono text-sm"
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
                <Button
                  type="button"
                  label="Guardar Token"
                  icon="pi pi-save"
                  onClick={handleSaveToken}
                  className="cursor-pointer shrink-0 rounded-lg bg-slate-700 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-600"
                  pt={{ root: { className: 'border-0' } }}
                />
              </div>

              <button
                type="button"
                onClick={() => setShowTokenHelp((v) => !v)}
                className="mt-2 cursor-pointer text-xs font-medium text-indigo-600 underline underline-offset-2 hover:text-indigo-500"
              >
                ¿Donde encuentro mi token?
              </button>

              {showTokenHelp && (
                <div className="mt-2 rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3 text-xs text-indigo-900">
                  <p className="font-semibold">Haz clic derecho en el icono de Cronos junto al reloj para copiar tu token.</p>
                  <ul className="mt-1 list-disc space-y-0.5 pl-4">
                    <li>Ubica el icono de Cronos POS Agent en la bandeja del sistema (junto al reloj de Windows).</li>
                    <li>Clic derecho → <strong>Copiar Token de Seguridad</strong>.</li>
                    <li>Pega el valor en este campo y pulsa <strong>Guardar Token</strong>.</li>
                    <li>Alternativamente, el mismo valor esta en <strong className="font-mono">config.json</strong> dentro de la carpeta de instalacion del agente.</li>
                  </ul>
                </div>
              )}
            </div>

            {/* Consulta manual de la cola de impresion */}
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Cola de Impresion</label>
              <PrinterQueueMonitor cronosAgent={cronosAgent} />
            </div>
          </>
        )}

        <div className="space-y-1 rounded-lg bg-slate-50 px-4 py-3 text-xs text-slate-500">
          <p><strong>Requisitos:</strong></p>
          <ul className="list-disc space-y-0.5 pl-4">
            <li>Cronos POS Agent debe estar instalado y ejecutandose en esta computadora (puerto 9100).</li>
            <li>El Token de Seguridad debe coincidir con el del agente instalado en esta terminal.</li>
            <li>La impresora termica debe estar conectada via USB o configurada en el sistema operativo.</li>
            <li>La configuracion se almacena localmente en este navegador (no se comparte entre equipos).</li>
          </ul>
        </div>
      </div>
    </div>
  );
}
