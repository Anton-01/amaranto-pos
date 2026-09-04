import { useCallback, useEffect, useState } from 'react';
import { InputText } from 'primereact/inputtext';
import { InputTextarea } from 'primereact/inputtextarea';
import { Chips } from 'primereact/chips';
import { Button } from 'primereact/button';
import { Tag } from 'primereact/tag';
import { toast } from 'sonner';
import mediaApi from '../../api/media';
import { formatDateTime } from '../../lib/mediaPreview';

/**
 * Google Drive connection panel.
 *
 * THE ONE UI RULE THAT MATTERS HERE: the service account JSON is write-only.
 * The API never returns it, so the textarea always loads empty, and an empty
 * textarea on save means "keep the credential you already have". Rotating
 * requires deliberately pasting a new document — which is what stops an
 * administrator from wiping a working connection while editing the reader list.
 *
 * The status strip states what is MISSING rather than a bare configured
 * yes/no, because the common failure is a half-configured row: a valid key with
 * no root folder authenticates perfectly and then fails at the first upload,
 * with an error that points nowhere near this screen.
 */
export default function GoogleDrivePanel() {
  const [credential, setCredential] = useState(null);
  const [meta, setMeta] = useState({
    is_configured: false,
    missing: [],
    scope: '',
    supports_external_shared_folders: false,
  });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState(false);
  const [testResult, setTestResult] = useState(null);
  const [fieldErrors, setFieldErrors] = useState({});

  const [form, setForm] = useState({
    label: 'Google Drive',
    service_account_json: '',
    root_folder_id: '',
    authorized_emails: [],
  });

  const fetchCredential = useCallback(async () => {
    setLoading(true);
    try {
      const res = await mediaApi.driveCredentials();
      setCredential(res.data);
      setMeta(res.metadata);

      if (res.data) {
        setForm({
          label: res.data.label ?? 'Google Drive',
          // Always empty: see the class docblock.
          service_account_json: '',
          root_folder_id: res.data.root_folder_id ?? '',
          authorized_emails: res.data.authorized_emails ?? [],
        });
      }
    } catch {
      toast.error('Error al cargar la configuración de Google Drive.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchCredential(); }, [fetchCredential]);

  const handleSave = async () => {
    setSaving(true);
    setFieldErrors({});
    try {
      const result = await mediaApi.saveDriveCredentials(form);
      toast.success(result.metadata?.message);
      setTestResult(null);
      fetchCredential();
    } catch (err) {
      const errors = err.response?.data?.errors;
      if (errors) {
        setFieldErrors(Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v[0]])));
        toast.warning('Verifica los campos marcados.');
      } else {
        toast.error(err.response?.data?.message || 'No se pudieron guardar las credenciales.');
      }
    } finally {
      setSaving(false);
    }
  };

  /**
   * Runs the health check on what is TYPED, not on what is stored, so a
   * credential can be validated before being persisted.
   */
  const handleTest = async () => {
    setTesting(true);
    setTestResult(null);
    try {
      const result = await mediaApi.testDriveConnection({
        credential_id: credential?.id ?? undefined,
        service_account_json: form.service_account_json || undefined,
        root_folder_id: form.root_folder_id || undefined,
        authorized_emails: form.authorized_emails,
      });
      setTestResult({ success: true, checks: result.data.checks, message: result.metadata.message });
      toast.success('Conexión verificada', { description: result.metadata.message });
      fetchCredential();
    } catch (err) {
      const data = err.response?.data;
      setTestResult({
        success: false,
        checks: data?.data?.checks ?? {},
        // Google's own words, passed through: an "insufficientFilePermissions"
        // and a "File not found" call for different fixes.
        message: data?.message ?? 'La prueba de conexión falló.',
      });
      toast.error('La prueba falló', { description: data?.message });
      fetchCredential();
    } finally {
      setTesting(false);
    }
  };

  const checkRow = (label, passed) => (
    <div className="flex items-center gap-2 py-1">
      <i className={`pi ${passed ? 'pi-check-circle text-emerald-600' : 'pi-times-circle text-rose-500'} text-sm`} />
      <span className={`text-xs ${passed ? 'text-slate-700' : 'text-slate-500'}`}>{label}</span>
    </div>
  );

  if (loading) {
    return (
      <div className="flex h-40 items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-4 border-indigo-600 border-t-transparent" />
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <div>
        <h3 className="text-base font-semibold text-slate-900">Conexión con Google Drive</h3>
        <p className="text-sm text-slate-500">
          Credenciales de la Service Account que almacena la biblioteca de medios.
        </p>
      </div>

      {/* Live condition of the connection. */}
      <div
        className={`rounded-lg border p-3 ${
          meta.is_configured ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50'
        }`}
      >
        <div className="flex flex-wrap items-center gap-2">
          <Tag
            value={meta.is_configured ? 'Configurado' : 'Incompleto'}
            severity={meta.is_configured ? 'success' : 'warning'}
            className="text-xs"
          />
          {credential?.client_email && (
            <span className="font-mono text-[11px] text-slate-600">{credential.client_email}</span>
          )}
          {credential?.last_tested_at && (
            <span className="text-[11px] text-slate-500">
              Última prueba: {formatDateTime(credential.last_tested_at)} ·{' '}
              {credential.last_test_status === 'success' ? 'exitosa' : 'fallida'}
            </span>
          )}
        </div>

        {meta.missing?.length > 0 && (
          <p className="mt-2 text-xs text-amber-900">
            Falta por configurar: <strong>{meta.missing.join(', ')}</strong>.
          </p>
        )}

        {credential?.last_test_status === 'failed' && credential?.last_test_message && (
          <p className="mt-2 text-xs text-rose-700">{credential.last_test_message}</p>
        )}
      </div>

      <div className="rounded-lg border border-sky-200 bg-sky-50 p-3">
        <p className="text-xs leading-relaxed text-sky-900">
          Las credenciales se guardan <strong>cifradas</strong> en la base de datos con la llave de la
          aplicación, y nunca vuelven al navegador. El alcance solicitado a Google es{' '}
          <code className="font-mono text-[10px]">{meta.scope}</code>
          {meta.supports_external_shared_folders
            ? ': la cuenta de servicio alcanza únicamente lo que alguien le compartió expresamente a '
              + 'su correo — la carpeta raíz de la biblioteca y lo que cuelga de ella.'
            : ': el token solo puede tocar los archivos que este POS creó, así que NO podrá ver una '
              + 'carpeta raíz creada por una persona y compartida con la cuenta de servicio.'}
        </p>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <div className="lg:col-span-2">
          <label className="mb-1 block text-xs font-semibold text-slate-600">
            JSON de la Service Account
            {credential?.has_private_key && (
              <span className="ml-2 font-normal text-emerald-600">· ya hay una llave cargada</span>
            )}
          </label>
          <InputTextarea
            value={form.service_account_json}
            onChange={(e) => setForm({ ...form, service_account_json: e.target.value })}
            rows={6}
            className="w-full font-mono text-xs"
            placeholder={
              credential?.has_private_key
                ? 'Déjalo vacío para conservar la llave actual. Pega un JSON nuevo solo si quieres rotarla.'
                : 'Pega aquí el archivo JSON descargado de Google Cloud Console.'
            }
          />
          {fieldErrors.service_account_json && (
            <p className="mt-1 text-xs text-rose-600">{fieldErrors.service_account_json}</p>
          )}
        </div>

        <div>
          <label className="mb-1 block text-xs font-semibold text-slate-600">Nombre de la conexión</label>
          <InputText
            value={form.label}
            onChange={(e) => setForm({ ...form, label: e.target.value })}
            className="w-full"
          />
        </div>

        <div>
          <label className="mb-1 block text-xs font-semibold text-slate-600">ID de la carpeta raíz</label>
          <InputText
            value={form.root_folder_id}
            onChange={(e) => setForm({ ...form, root_folder_id: e.target.value })}
            className="w-full font-mono text-sm"
            placeholder="1AbC2dEfGh3IjKlMnOp"
          />
          <p className="mt-1 text-[11px] text-slate-400">
            El tramo que sigue a <code>/folders/</code> en la URL de Drive. La carpeta debe vivir dentro de
            una <strong>Unidad compartida</strong> y la cuenta de servicio debe ser miembro de esa unidad
            como <strong>Administrador de contenido</strong>. Una carpeta de &quot;Mi unidad&quot;, aunque
            esté compartida como Editor, hace que Google rechace toda subida con
            <code> 403 storageQuotaExceeded</code>: la cuenta de servicio no tiene almacenamiento propio.
          </p>
          {fieldErrors.root_folder_id && (
            <p className="mt-1 text-xs text-rose-600">{fieldErrors.root_folder_id}</p>
          )}
        </div>

        <div className="lg:col-span-2">
          <label className="mb-1 block text-xs font-semibold text-slate-600">
            Cuentas autorizadas de lectura <span className="font-normal text-slate-400">(opcional)</span>
          </label>
          <Chips
            value={form.authorized_emails}
            onChange={(e) => setForm({ ...form, authorized_emails: e.value ?? [] })}
            separator=","
            className="w-full"
            placeholder="conta@empresa.com"
          />
          <p className="mt-1 text-[11px] text-slate-400">
            Cada archivo subido recibe permiso de lectura para estas cuentas de Google, y solo para ellas.
            Vacío significa que únicamente la cuenta de servicio puede abrir los archivos.
          </p>
        </div>
      </div>

      {/* Health check result, itemized. A bare pass/fail would not say WHICH
          of the five steps broke, and each one has a different fix. */}
      {testResult && (
        <div
          className={`rounded-lg border p-3 ${
            testResult.success ? 'border-emerald-200 bg-emerald-50' : 'border-rose-200 bg-rose-50'
          }`}
        >
          <p className={`text-sm font-semibold ${testResult.success ? 'text-emerald-900' : 'text-rose-900'}`}>
            {testResult.success ? 'Conexión verificada' : 'La prueba de conexión falló'}
          </p>
          <div className="mt-2">
            {checkRow('Credenciales completas', testResult.checks?.credentials_complete)}
            {checkRow('Token emitido por Google', testResult.checks?.token_minted)}
            {checkRow('Carpeta raíz alcanzable', testResult.checks?.root_folder_reachable)}
            {checkRow('Carpeta raíz con permiso de escritura', testResult.checks?.root_folder_writable)}
            {/* The row that a passing connection used to hide: a My Drive
                folder satisfies every check above and still cannot receive a
                single byte, because the bytes would be billed to a service
                account that owns no quota. */}
            {checkRow('Carpeta raíz dentro de una Unidad compartida', testResult.checks?.root_folder_in_shared_drive)}
          </div>
          <p className={`mt-2 text-xs ${testResult.success ? 'text-emerald-800' : 'text-rose-800'}`}>
            {testResult.message}
          </p>
        </div>
      )}

      <div className="flex flex-col-reverse gap-2 border-t border-slate-100 pt-4 sm:flex-row sm:justify-end">
        <Button
          label="Probar conexión"
          icon="pi pi-bolt"
          outlined
          onClick={handleTest}
          loading={testing}
          className="w-full sm:w-auto"
        />
        <Button
          label="Guardar credenciales"
          icon="pi pi-lock"
          onClick={handleSave}
          loading={saving}
          className="w-full sm:w-auto"
        />
      </div>
    </div>
  );
}
