import { useState, useEffect, useCallback } from 'react';
import { InputText } from 'primereact/inputtext';
import { Button } from 'primereact/button';
import { Tag } from 'primereact/tag';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { Dialog } from 'primereact/dialog';
import { toast } from 'sonner';
import api from '../../api/axios';
import AppLayout from '../../components/layout/AppLayout';
import TicketPreview from '../../components/pos/TicketPreview';
import { STACK_TABLE, STACK_CLASS, HIDE_BELOW } from '../../lib/responsive';

const emptyForm = {
  business_name: '',
  rfc: '',
  address: '',
  phone: '',
  header_message: '',
  footer_message: '',
};

export default function TicketConfigPage() {
  const [configs, setConfigs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [formData, setFormData] = useState({ ...emptyForm });
  const [saving, setSaving] = useState(false);
  const [previewConfig, setPreviewConfig] = useState(null);

  const fetchData = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api.get('/ticket-configs');
      setConfigs(res.data.data);
    } catch {
      toast.error('Error al cargar configuraciones de ticket.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchData(); }, [fetchData]);

  const set = (field, value) => setFormData(prev => ({ ...prev, [field]: value }));

  const openNewVersion = () => {
    const active = configs.find(c => c.is_active);
    if (active) {
      setFormData({
        business_name: active.business_name || '',
        rfc: active.rfc || '',
        address: active.address || '',
        phone: active.phone || '',
        header_message: active.header_message || '',
        footer_message: active.footer_message || '',
      });
    } else {
      setFormData({ ...emptyForm });
    }
    setShowForm(true);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setSaving(true);
    try {
      await api.post('/ticket-configs', formData);
      toast.success('Nueva version de ticket creada.');
      setShowForm(false);
      fetchData();
    } catch (err) {
      const data = err.response?.data;
      const fieldErrors = data?.errors;
      if (fieldErrors) {
        Object.values(fieldErrors).flat().forEach(e => toast.error(e));
      } else {
        toast.error(data?.message || 'Error al guardar configuracion.');
      }
    } finally {
      setSaving(false);
    }
  };

  const versionTemplate = (row) => (
    <div className="flex items-center gap-2">
      <span className="font-mono font-semibold text-slate-900">v{row.version}</span>
      {row.is_active && <Tag value="ACTIVA" severity="success" className="text-xs" />}
    </div>
  );

  const dateTemplate = (row) => {
    const d = new Date(row.created_at);
    return d.toLocaleString('es-MX', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  };

  const actionsTemplate = (row) => (
    <button
      onClick={() => setPreviewConfig(row)}
      className="text-indigo-600 hover:text-indigo-800 text-sm font-medium"
    >
      Ver Ticket
    </button>
  );

  const livePreviewConfig = {
    ...formData,
    version: (configs[0]?.version ?? 0) + 1,
  };

  return (
    <AppLayout>
      <div className="mb-5 flex flex-col gap-3 sm:mb-6 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Diseno de Ticket</h1>
          <p className="text-sm text-slate-500">Cada cambio genera una nueva version inmutable (Append-Only).</p>
        </div>
        <Button
          label="Nueva Version"
          onClick={openNewVersion}
          className="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
          pt={{ root: { className: 'border-0' } }}
        />
      </div>

      <div className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <DataTable
          value={configs}
          loading={loading}
          emptyMessage="No hay configuraciones de ticket."
          sortField="version"
          sortOrder={-1}
          stripedRows
          paginator
          rows={10}
          {...STACK_TABLE}
          pt={{ root: { className: `text-sm ${STACK_CLASS}` }, thead: { className: 'bg-slate-50' } }}
        >
          <Column field="version" header="Version" body={versionTemplate} sortable style={{ width: '15%' }} />
          <Column field="business_name" header="Razon Social" sortable style={{ width: '25%' }} />
          <Column field="rfc" header="RFC" className={HIDE_BELOW.md} style={{ width: '15%' }} />
          <Column field="phone" header="Telefono" className={HIDE_BELOW.md} style={{ width: '12%' }} />
          <Column field="created_at" header="Fecha Creacion" body={dateTemplate} sortable style={{ width: '20%' }} />
          {/* Named so the stacked card line is labelled rather than anonymous. */}
          <Column header="Acciones" body={actionsTemplate} style={{ width: '10%' }} />
        </DataTable>
      </div>

      {/* New version form dialog */}
      <Dialog
        visible={showForm}
        onHide={() => !saving && setShowForm(false)}
        closable={!saving}
        modal
        header={null}
        className="w-full max-w-4xl"
        pt={{
          mask: { className: 'backdrop-blur-sm bg-black/30' },
          root: { className: 'rounded-2xl border-0 shadow-2xl' },
          content: { className: 'p-0' },
        }}
      >
        <form onSubmit={handleSubmit} className="p-6">
          <div className="mb-5">
            <h3 className="text-lg font-semibold text-slate-900">Nueva Version de Ticket</h3>
            <p className="text-xs text-slate-500">La version anterior se desactivara automaticamente.</p>
          </div>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {/* Form fields */}
            <div className="space-y-4">
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Razon Social *</label>
                <InputText
                  value={formData.business_name}
                  onChange={(e) => set('business_name', e.target.value)}
                  disabled={saving}
                  className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="mb-1.5 block text-sm font-medium text-slate-700">RFC *</label>
                  <InputText
                    value={formData.rfc}
                    onChange={(e) => set('rfc', e.target.value)}
                    disabled={saving}
                    className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                    pt={{ root: { className: 'w-full' } }}
                  />
                </div>
                <div>
                  <label className="mb-1.5 block text-sm font-medium text-slate-700">Telefono *</label>
                  <InputText
                    value={formData.phone}
                    onChange={(e) => set('phone', e.target.value)}
                    disabled={saving}
                    className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                    pt={{ root: { className: 'w-full' } }}
                  />
                </div>
              </div>
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Direccion *</label>
                <InputText
                  value={formData.address}
                  onChange={(e) => set('address', e.target.value)}
                  disabled={saving}
                  className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Mensaje de Cabecera</label>
                <InputText
                  value={formData.header_message}
                  onChange={(e) => set('header_message', e.target.value)}
                  placeholder="Ej: Gracias por su preferencia"
                  disabled={saving}
                  className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Mensaje de Pie</label>
                <InputText
                  value={formData.footer_message}
                  onChange={(e) => set('footer_message', e.target.value)}
                  placeholder="Ej: No se aceptan devoluciones"
                  disabled={saving}
                  className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>

              <div className="flex gap-3 pt-2">
                <Button
                  type="button"
                  label="Cancelar"
                  onClick={() => setShowForm(false)}
                  disabled={saving}
                  className="flex-1 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                  pt={{ root: { className: 'border border-slate-200' } }}
                />
                <Button
                  type="submit"
                  label={saving ? 'Guardando...' : 'Crear Version'}
                  disabled={saving}
                  loading={saving}
                  className="flex-1 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                  pt={{ root: { className: 'border-0' } }}
                />
              </div>
            </div>

            {/* Live preview */}
            <div>
              <p className="mb-3 text-center text-xs font-semibold uppercase tracking-wider text-slate-500">
                Vista Previa en Tiempo Real
              </p>
              <TicketPreview
                order={{
                  items: [
                    { product_name: 'Hamburguesa Clasica', quantity: 2, sale_price: 89.00, discount: 0 },
                    { product_name: 'Papas Grandes', quantity: 1, sale_price: 45.00, discount: 10, promotion_name: 'Promo Combo' },
                  ],
                  subtotal: 213.00,
                  iva_total: 34.08,
                  total: 247.08,
                  payment_method: 'efectivo',
                  created_at: new Date().toISOString(),
                }}
                ticketConfig={livePreviewConfig}
                customLegend=""
              />
            </div>
          </div>
        </form>
      </Dialog>

      {/* Version preview dialog */}
      <Dialog
        visible={!!previewConfig}
        onHide={() => setPreviewConfig(null)}
        modal
        header={null}
        className="w-full max-w-md"
        pt={{
          mask: { className: 'backdrop-blur-sm bg-black/30' },
          root: { className: 'rounded-2xl border-0 shadow-2xl' },
          content: { className: 'p-0' },
        }}
      >
        {previewConfig && (
          <div className="p-6">
            <h3 className="mb-4 text-center text-sm font-semibold uppercase tracking-wider text-slate-500">
              Ticket Version {previewConfig.version} {previewConfig.is_active ? '(Activa)' : '(Inactiva)'}
            </h3>
            <TicketPreview
              order={{
                items: [
                  { product_name: 'Producto ejemplo', quantity: 1, sale_price: 100.00, discount: 0 },
                ],
                subtotal: 100.00,
                iva_total: 16.00,
                total: 116.00,
                payment_method: 'efectivo',
                created_at: new Date().toISOString(),
              }}
              ticketConfig={previewConfig}
              customLegend=""
            />
            <button
              onClick={() => setPreviewConfig(null)}
              className="mt-4 w-full rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 transition-colors"
            >
              Cerrar
            </button>
          </div>
        )}
      </Dialog>
    </AppLayout>
  );
}
