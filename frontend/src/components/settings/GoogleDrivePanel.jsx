import { useCallback, useEffect, useState } from 'react';
import { InputText } from 'primereact/inputtext';
import { Password } from 'primereact/password';
import { Chips } from 'primereact/chips';
import { Button } from 'primereact/button';
import { Tag } from 'primereact/tag';
import { toast } from 'sonner';
import mediaApi from '../../api/media';
import { formatDateTime } from '../../lib/mediaPreview';

/**
 * Google Drive connection panel.
 *
 * THE CONNECTION IS AN OAUTH 2.0 USER GRANT, not a service account. The three
 * fields below — Client ID, Client Secret, Refresh Token — let the POS act AS
 * the owner of the Drive, so uploaded files belong to that person and consume
 * the Google One plan they already pay for. The previous service account owned
 * no storage of its own and Google answered `403 storageQuotaExceeded` on every
 * upload, with no fix available on a personal account.
 *
 * THE ONE UI RULE THAT MATTERS HERE: the two secrets are write-only. The API
 * never returns them, so their inputs always load empty, and an empty input on
 * save means "keep the value you already have". Rotating requires deliberately
 * pasting a new value — which is what stops an administrator from wiping a
 * working connection while editing the folder id or the reader list.
 *
 * The status strip states what is MISSING rather than a bare configured
 * yes/no, because the common failure is a half-configured row: a valid token
 * with no root folder authenticates perfectly and then fails at the first
 * upload, with an error that points nowhere near this screen.
 */
export default function GoogleDrivePanel() {
  const [credential, setCredential] = useState(null);
  const [meta, setMeta] = useState({
    is_configured: false,
    missing: [],
    scope: '',
    supports_manual_folders: false,
  });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState(false);
  const [testResult, setTestResult] = useState(null);
  const [fieldErrors, setFieldErrors] = useState({});

  const [form, setForm] = useState({
    label: 'Google Drive',
    client_id: '',
    client_secret: '',
    refresh_token: '',
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
          // Not a secret: it identifies the OAuth application and is useless
          // without the pair it belongs to, so it round-trips normally.
          client_id: res.data.client_id ?? '',
          // Always empty: see the component docblock.
          client_secret: '',
          refresh_token: '',
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
   * credential can be validated before being persisted. The secrets are sent
   * only when they carry a new value; otherwise the server falls back to the
   * stored ones, exactly as saving does.
   */
  const handleTest = async () => {
    setTesting(true);
    setTestResult(null);
    try {
      const result = await mediaApi.testDriveConnection({
        credential_id: credential?.id ?? undefined,
        client_id: form.client_id || undefined,
        client_secret: form.client_secret || undefined,
        refresh_token: form.refresh_token || undefined,
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
        // Google's own words, passed through: an "invalid_grant" and a
        // "File not found" call for different fixes.
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

  /**
   * Placeholder of a write-only field: it has to say that leaving the box empty
   * PRESERVES the stored secret, because an empty input that means "keep" and
   * an empty input that means "erase" look identical.
   */
  const secretPlaceholder = (stored, fresh) => (stored
    ? 'Déjalo vacío para conservar el valor actual. Pega uno nuevo solo si quieres rotarlo.'
    : fresh);

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
          Credenciales de OAuth 2.0 con las que la biblioteca de medios escribe en Google Drive.
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
          {credential?.account_email && (
            <span className="font-mono text-[11px] text-slate-600">{credential.account_email}</span>
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
          El Client Secret y el Refresh Token se guardan <strong>cifrados</strong> en la base de datos con
          la llave de la aplicación, y nunca vuelven al navegador. El POS actúa <strong>como la cuenta de
          Google que autorizó el token</strong>, así que los archivos pertenecen a esa cuenta y consumen su
          plan de almacenamiento. El alcance solicitado a Google es{' '}
          <code className="font-mono text-[10px]">{meta.scope}</code>
          {meta.supports_manual_folders
            ? ': alcanza las carpetas de esa cuenta, incluida una carpeta raíz creada a mano.'
            : ': el token solo puede tocar los archivos que este POS creó, así que NO podrá ver una '
              + 'carpeta raíz creada a mano por una persona.'}
        </p>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <div>
          <label className="mb-1 block text-xs font-semibold text-slate-600">Client ID</label>
          <InputText
            value={form.client_id}
            onChange={(e) => setForm({ ...form, client_id: e.target.value })}
            className="w-full font-mono text-xs"
            placeholder="123456789012-abc....apps.googleusercontent.com"
          />
          {fieldErrors.client_id && (
            <p className="mt-1 text-xs text-rose-600">{fieldErrors.client_id}</p>
          )}
        </div>

        <div>
          <label className="mb-1 block text-xs font-semibold text-slate-600">
            Client Secret
            {credential?.has_client_secret && (
              <span className="ml-2 font-normal text-emerald-600">· ya hay uno guardado</span>
            )}
          </label>
          <Password
            value={form.client_secret}
            onChange={(e) => setForm({ ...form, client_secret: e.target.value })}
            feedback={false}
            toggleMask
            inputClassName="w-full font-mono text-xs"
            className="w-full"
            placeholder={secretPlaceholder(credential?.has_client_secret, 'GOCSPX-...')}
          />
          {fieldErrors.client_secret && (
            <p className="mt-1 text-xs text-rose-600">{fieldErrors.client_secret}</p>
          )}
        </div>

        <div className="lg:col-span-2">
          <label className="mb-1 block text-xs font-semibold text-slate-600">
            Refresh Token
            {credential?.has_refresh_token && (
              <span className="ml-2 font-normal text-emerald-600">· ya hay uno guardado</span>
            )}
          </label>
          <Password
            value={form.refresh_token}
            onChange={(e) => setForm({ ...form, refresh_token: e.target.value })}
            feedback={false}
            toggleMask
            inputClassName="w-full font-mono text-xs"
            className="w-full"
            placeholder={secretPlaceholder(credential?.has_refresh_token, '1//0g...')}
          />
          <p className="mt-1 text-[11px] text-slate-400">
            Genéralo con el mismo Client ID de arriba y con la cuenta de Google <strong>dueña de la carpeta
            raíz</strong>, pidiendo <code>access_type=offline</code> y <code>prompt=consent</code>. Si la
            aplicación de OAuth sigue en modo <em>Testing</em> en Google Cloud, el token caduca a los 7 días:
            publícala para que deje de expirar.
          </p>
          {fieldErrors.refresh_token && (
            <p className="mt-1 text-xs text-rose-600">{fieldErrors.refresh_token}</p>
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
            El tramo que sigue a <code>/folders/</code> en la URL de Drive. Lo más simple es que la carpeta
            viva en el Drive de la misma cuenta que autorizó el Refresh Token: si pertenece a otra persona,
            los archivos consumirán la cuota de esa persona y hará falta permiso de Editor.
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
            Vacío significa que únicamente la cuenta que autorizó la conexión puede abrir los archivos.
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
            {checkRow('Token de acceso renovado con el Refresh Token', testResult.checks?.token_minted)}
            {/* A refresh token carries no readable identity, so this row is the
                only place an administrator learns WHICH Google account the POS
                speaks as — a mismatch there is the usual cause of a 404 on a
                folder id that is perfectly valid for somebody else. */}
            {checkRow(
              testResult.checks?.account_email
                ? `Cuenta autorizada: ${testResult.checks.account_email}`
                : 'Cuenta autorizada identificada',
              testResult.checks?.account_identified,
            )}
            {checkRow('Carpeta raíz alcanzable', testResult.checks?.root_folder_reachable)}
            {checkRow('Carpeta raíz con permiso de escritura', testResult.checks?.root_folder_writable)}
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
