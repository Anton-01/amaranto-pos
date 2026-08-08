import { useState, useEffect, useCallback } from 'react';
import { InputText } from 'primereact/inputtext';
import { InputNumber } from 'primereact/inputnumber';
import { Dropdown } from 'primereact/dropdown';
import { TabView, TabPanel } from 'primereact/tabview';
import { Button } from 'primereact/button';
import { toast } from 'sonner';
import api from '../../api/axios';
import AppLayout from '../../components/layout/AppLayout';
import PrinterSetupPanel from '../../components/settings/PrinterSetupPanel';
import EmailNotificationsPanel from '../../components/settings/EmailNotificationsPanel';
import useCronosAgent from '../../hooks/useCronosAgent';
import { useAuth } from '../../context/AuthContext';

const timezoneOptions = [
  { label: 'Ciudad de México (UTC-6)', value: 'America/Mexico_City' },
  { label: 'Cancún (UTC-5)', value: 'America/Cancun' },
  { label: 'Tijuana (UTC-8)', value: 'America/Tijuana' },
  { label: 'Hermosillo (UTC-7)', value: 'America/Hermosillo' },
  { label: 'Bogotá (UTC-5)', value: 'America/Bogota' },
  { label: 'Buenos Aires (UTC-3)', value: 'America/Argentina/Buenos_Aires' },
];

const currencyOptions = [
  { label: 'Peso Mexicano (MXN)', value: 'MXN', symbol: '$' },
  { label: 'Dólar Estadounidense (USD)', value: 'USD', symbol: '$' },
  { label: 'Euro (EUR)', value: 'EUR', symbol: '€' },
  { label: 'Peso Colombiano (COP)', value: 'COP', symbol: '$' },
];

export default function SystemSettingsPage() {
  const cronosAgent = useCronosAgent();
  const { user } = useAuth();
  // The mailing endpoints are admin-only (they expose provider credentials),
  // so managers never get a tab whose requests would come back 403.
  const isAdmin = user?.roles?.includes('admin');
  const [settings, setSettings] = useState({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const [timezone, setTimezone] = useState('America/Mexico_City');
  const [currency, setCurrency] = useState('MXN');
  const [currencySymbol, setCurrencySymbol] = useState('$');
  const [taxRate, setTaxRate] = useState(16);
  const [investmentPct, setInvestmentPct] = useState(70);

  const [businessName, setBusinessName] = useState('');
  const [rfc, setRfc] = useState('');
  const [address, setAddress] = useState('');
  const [city, setCity] = useState('');
  const [phone, setPhone] = useState('');

  const fetchSettings = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get('/admin/settings');
      const data = res.data.data;
      setSettings(data);

      if (data.timezone) setTimezone(data.timezone.timezone);
      if (data.currency) { setCurrency(data.currency.code); setCurrencySymbol(data.currency.symbol); }
      if (data.tax_rate) setTaxRate(Math.round(data.tax_rate.rate * 100));
      if (data.investment_split) setInvestmentPct(data.investment_split.investment_pct);
      if (data.fiscal_data) {
        setBusinessName(data.fiscal_data.business_name || '');
        setRfc(data.fiscal_data.rfc || '');
        setAddress(data.fiscal_data.address || '');
        setCity(data.fiscal_data.city || '');
        setPhone(data.fiscal_data.phone || '');
      }
    } catch {
      toast.error('Error al cargar configuración.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchSettings(); }, [fetchSettings]);

  const handleSave = async () => {
    setSaving(true);
    try {
      const tz = timezoneOptions.find(t => t.value === timezone);
      const cur = currencyOptions.find(c => c.value === currency);

      await api.put('/admin/settings', {
        settings: [
          { key: 'timezone', value: { timezone, label: tz?.label || timezone } },
          { key: 'currency', value: { code: currency, symbol: cur?.symbol || currencySymbol, label: cur?.label || currency } },
          { key: 'tax_rate', value: { rate: taxRate / 100, label: `IVA ${taxRate}%` } },
          { key: 'investment_split', value: { investment_pct: investmentPct, profit_pct: 100 - investmentPct } },
          { key: 'fiscal_data', value: { business_name: businessName, rfc, address, city, phone } },
        ],
      });
      toast.success('Configuración guardada correctamente.');
    } catch {
      toast.error('Error al guardar configuración.');
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <AppLayout>
        <div className="flex h-64 items-center justify-center">
          <div className="h-8 w-8 animate-spin rounded-full border-4 border-indigo-600 border-t-transparent" />
        </div>
      </AppLayout>
    );
  }

  return (
    <AppLayout>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Configuracion del Sistema</h1>
          <p className="text-sm text-slate-500">Parametros globales del negocio.</p>
        </div>
        <Button
          label={saving ? 'Guardando...' : 'Guardar Cambios'}
          onClick={handleSave}
          loading={saving}
          disabled={saving}
          className="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 cursor-pointer"
          pt={{ root: { className: 'border-0' } }}
        />
      </div>

      <div className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <TabView pt={{ nav: { className: 'border-b border-slate-200' } }}>
          <TabPanel header="Region" pt={{ headerAction: { className: 'text-sm' } }}>
            <div className="max-w-lg space-y-5 p-6">
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Zona Horaria</label>
                <Dropdown
                  value={timezone}
                  options={timezoneOptions}
                  onChange={(e) => setTimezone(e.value)}
                  className="w-full text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Moneda Base</label>
                <Dropdown
                  value={currency}
                  options={currencyOptions}
                  onChange={(e) => {
                    setCurrency(e.value);
                    const c = currencyOptions.find(c => c.value === e.value);
                    if (c) setCurrencySymbol(c.symbol);
                  }}
                  className="w-full text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>
            </div>
          </TabPanel>

          <TabPanel header="Datos Fiscales" pt={{ headerAction: { className: 'text-sm' } }}>
            <div className="max-w-lg space-y-5 p-6">
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Razon Social</label>
                <InputText value={businessName} onChange={(e) => setBusinessName(e.target.value)}
                  className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm" pt={{ root: { className: 'w-full' } }} />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label className="mb-1.5 block text-sm font-medium text-slate-700">RFC</label>
                  <InputText value={rfc} onChange={(e) => setRfc(e.target.value)}
                    className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm font-mono" pt={{ root: { className: 'w-full' } }} />
                </div>
                <div>
                  <label className="mb-1.5 block text-sm font-medium text-slate-700">Telefono</label>
                  <InputText value={phone} onChange={(e) => setPhone(e.target.value)}
                    className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm" pt={{ root: { className: 'w-full' } }} />
                </div>
              </div>
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Direccion</label>
                <InputText value={address} onChange={(e) => setAddress(e.target.value)}
                  className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm" pt={{ root: { className: 'w-full' } }} />
              </div>
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Ciudad</label>
                <InputText value={city} onChange={(e) => setCity(e.target.value)}
                  className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm" pt={{ root: { className: 'w-full' } }} />
              </div>
            </div>
          </TabPanel>

          <TabPanel header="Impuestos & Finanzas" pt={{ headerAction: { className: 'text-sm' } }}>
            <div className="max-w-lg space-y-5 p-6">
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">IVA Aplicable (%)</label>
                <InputNumber
                  value={taxRate}
                  onValueChange={(e) => setTaxRate(e.value ?? 16)}
                  suffix="%"
                  min={0} max={100}
                  className="w-full"
                  inputClassName="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
                <p className="mt-1 text-xs text-slate-400">Se aplica automáticamente en cada venta.</p>
              </div>
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Split de Inversión (%)</label>
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <InputNumber
                      value={investmentPct}
                      onValueChange={(e) => setInvestmentPct(e.value ?? 70)}
                      suffix="% Inversión"
                      min={0} max={100}
                      className="w-full"
                      inputClassName="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                      pt={{ root: { className: 'w-full' } }}
                    />
                  </div>
                  <div>
                    <InputNumber
                      value={100 - investmentPct}
                      disabled
                      suffix="% Utilidad"
                      className="w-full"
                      inputClassName="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm bg-slate-50"
                      pt={{ root: { className: 'w-full' } }}
                    />
                  </div>
                </div>
              </div>
            </div>
          </TabPanel>

          {isAdmin && (
            <TabPanel header="Notificaciones / Emails" pt={{ headerAction: { className: 'text-sm' } }}>
              <EmailNotificationsPanel />
            </TabPanel>
          )}
        </TabView>
      </div>

      <div className="mt-6">
        <PrinterSetupPanel cronosAgent={cronosAgent} />
      </div>
    </AppLayout>
  );
}
