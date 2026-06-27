import { useState, useEffect, useCallback } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { InputText } from 'primereact/inputtext';
import { InputNumber } from 'primereact/inputnumber';
import { InputSwitch } from 'primereact/inputswitch';
import { Dropdown } from 'primereact/dropdown';
import { FileUpload } from 'primereact/fileupload';
import { Button } from 'primereact/button';
import { toast } from 'sonner';
import api from '../../api/axios';
import AppLayout from '../../components/layout/AppLayout';

const emptyForm = {
  sku: '',
  parent_sku: '',
  name: '',
  category_id: null,
  cost_price: null,
  sale_price: null,
  current_stock: 0,
  minimum_stock: 0,
  maximum_stock: 0,
  is_active: true,
  track_stock: true,
};

export default function ProductFormPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const isEditing = !!id;

  const [formData, setFormData] = useState({ ...emptyForm });
  const [categories, setCategories] = useState([]);
  const [saving, setSaving] = useState(false);
  const [loadingData, setLoadingData] = useState(true);
  const [variationCount, setVariationCount] = useState(0);
  const [fieldErrors, setFieldErrors] = useState({});
  const [imageUrl, setImageUrl] = useState(null);
  const [uploadingImage, setUploadingImage] = useState(false);

  const fetchCategories = useCallback(async () => {
    try {
      const res = await api.get('/categories', { params: { is_active: true } });
      setCategories(res.data.data.map(c => ({ label: c.name, value: c.id })));
    } catch {
      toast.error('Error al cargar categorias.');
    }
  }, []);

  const fetchProduct = useCallback(async () => {
    if (!id) return;
    try {
      const res = await api.get(`/products/${id}`);
      const p = res.data.data;
      setFormData({
        sku: p.sku,
        parent_sku: p.parent_sku || '',
        name: p.name,
        category_id: p.category_id,
        cost_price: parseFloat(p.cost_price),
        sale_price: parseFloat(p.sale_price),
        current_stock: p.current_stock,
        minimum_stock: p.minimum_stock,
        maximum_stock: p.maximum_stock,
        is_active: p.is_active,
        track_stock: p.track_stock !== undefined ? p.track_stock : true,
      });
      setImageUrl(p.image_url || null);
    } catch {
      toast.error('Error al cargar producto.');
      navigate('/products');
    }
  }, [id, navigate]);

  useEffect(() => {
    const load = async () => {
      setLoadingData(true);
      await Promise.all([fetchCategories(), fetchProduct()]);
      setLoadingData(false);
    };
    load();
  }, [fetchCategories, fetchProduct]);

  const set = (field, value) => setFormData(prev => ({ ...prev, [field]: value }));

  const validate = () => {
    if (!formData.sku.trim()) return 'El SKU es obligatorio.';
    if (!isEditing && !formData.sku.startsWith('AMR-')) return 'El SKU debe iniciar con el prefijo AMR-.';
    if (!formData.name.trim()) return 'El nombre es obligatorio.';
    if (!formData.category_id) return 'Selecciona una categoria.';
    if (formData.cost_price == null || formData.cost_price < 0) return 'El costo debe ser mayor o igual a 0.';
    if (formData.sale_price == null || formData.sale_price < 0) return 'El precio de venta debe ser mayor o igual a 0.';
    return null;
  };

  const submitProduct = async () => {
    const payload = {
      ...formData,
      parent_sku: formData.parent_sku.trim() || null,
    };

    if (isEditing) {
      const { sku, ...editPayload } = payload;
      await api.put(`/products/${id}`, editPayload);
    } else {
      await api.post('/products', payload);
    }
  };

  const handleSave = async (e) => {
    e.preventDefault();
    const err = validate();
    if (err) { toast.error(err); return; }

    setSaving(true);
    setFieldErrors({});
    try {
      await submitProduct();
      toast.success(isEditing ? 'Producto actualizado.' : 'Producto creado.');
      navigate('/products');
    } catch (error) {
      const errors = error.response?.data?.errors;
      if (errors) {
        setFieldErrors(Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v[0]])));
        toast.warning('Verifica los campos marcados.');
      } else {
        toast.error(error.response?.data?.message || 'Error al guardar.');
      }
    } finally {
      setSaving(false);
    }
  };

  const handleSaveAndVariation = async () => {
    const err = validate();
    if (err) { toast.error(err); return; }

    setSaving(true);
    try {
      await submitProduct();
      const count = variationCount + 1;
      setVariationCount(count);
      toast.success(`Variacion #${count} guardada. Captura la siguiente.`);

      const frozenParentSku = formData.parent_sku.trim() || formData.sku.trim();
      setFormData(prev => ({
        ...prev,
        sku: '',
        cost_price: null,
        sale_price: null,
        parent_sku: frozenParentSku,
      }));
    } catch (error) {
      const msg = error.response?.data?.message || 'Error al guardar.';
      const fieldErrors = error.response?.data?.errors;
      if (fieldErrors) {
        Object.values(fieldErrors).flat().forEach(e => toast.error(e));
      } else {
        toast.error(msg);
      }
    } finally {
      setSaving(false);
    }
  };

  if (loadingData) {
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
      <div className="mb-6">
        <button
          onClick={() => navigate('/products')}
          className="mb-3 flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700 transition-colors"
        >
          <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={1.5} stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
          </svg>
          Volver a productos
        </button>
        <h1 className="text-2xl font-bold text-slate-900">
          {isEditing ? 'Editar Producto' : 'Nuevo Producto'}
        </h1>
        {variationCount > 0 && (
          <p className="mt-1 text-sm text-emerald-600 font-medium">
            {variationCount} variacion(es) guardada(s) en esta sesion
          </p>
        )}
      </div>

      <form onSubmit={handleSave} className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {/* Identificacion */}
        <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
          <h2 className="mb-4 text-base font-semibold text-slate-900">Identificacion</h2>

          <div className="space-y-4">
            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">SKU *</label>
              {isEditing ? (
                <InputText
                  value={formData.sku}
                  readOnly
                  className="w-full rounded-lg border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-mono cursor-not-allowed"
                  pt={{ root: { className: 'w-full' } }}
                />
              ) : (
                <div className="flex">
                  <span className="inline-flex items-center rounded-l-lg border border-r-0 border-slate-200 bg-slate-100 px-3 text-sm font-mono font-semibold text-slate-600">AMR-</span>
                  <InputText
                    value={formData.sku.startsWith('AMR-') ? formData.sku.slice(4) : formData.sku}
                    onChange={(e) => set('sku', 'AMR-' + e.target.value.toUpperCase().replace(/^AMR-/i, ''))}
                    placeholder="Ej: 102"
                    disabled={saving}
                    className="w-full rounded-r-lg rounded-l-none border-slate-200 px-3 py-2.5 text-sm font-mono"
                    pt={{ root: { className: 'w-full' } }}
                  />
                </div>
              )}
              {isEditing && <p className="mt-1 text-xs text-slate-400">El SKU no es editable una vez dado de alta.</p>}
              {fieldErrors.sku && <p className="mt-1 text-xs text-rose-500">{fieldErrors.sku}</p>}
            </div>

            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Grupo de Variaciones (parent_sku)</label>
              <InputText
                value={formData.parent_sku}
                onChange={(e) => set('parent_sku', e.target.value.toUpperCase())}
                placeholder="Ej: REFRESCO (agrupa variaciones)"
                disabled={saving}
                className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm font-mono"
                pt={{ root: { className: 'w-full' } }}
              />
              <p className="mt-1 text-xs text-slate-400">
                Productos con el mismo parent_sku se agrupan en el punto de venta.
              </p>
            </div>

            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Nombre *</label>
              <InputText
                value={formData.name}
                onChange={(e) => set('name', e.target.value)}
                placeholder="Ej: Refresco Cola 500ml"
                disabled={saving}
                className="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                pt={{ root: { className: 'w-full' } }}
              />
            </div>

            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Categoria *</label>
              <Dropdown
                value={formData.category_id}
                options={categories}
                onChange={(e) => set('category_id', e.value)}
                placeholder="Seleccionar categoria"
                disabled={saving}
                className="w-full rounded-lg border-slate-200 text-sm"
                pt={{ root: { className: 'w-full' } }}
              />
            </div>

            <div>
              <label className="mb-1.5 block text-sm font-medium text-slate-700">Imagen del Producto</label>
              {imageUrl && (
                <div className="mb-2 flex items-center gap-3">
                  <img src={imageUrl} alt="Producto" className="h-16 w-16 rounded-lg object-cover ring-1 ring-slate-200" />
                  <button
                    type="button"
                    onClick={async () => {
                      if (!id) return;
                      try {
                        await api.delete(`/products/${id}/image`);
                        setImageUrl(null);
                        toast.success('Imagen eliminada.');
                      } catch { toast.error('Error al eliminar imagen.'); }
                    }}
                    className="text-xs text-rose-600 hover:text-rose-800 cursor-pointer"
                  >
                    Eliminar imagen
                  </button>
                </div>
              )}
              {isEditing && (
                <FileUpload
                  mode="basic"
                  accept="image/jpeg,image/png"
                  maxFileSize={2097152}
                  chooseLabel={uploadingImage ? 'Subiendo...' : 'Seleccionar imagen'}
                  disabled={uploadingImage || saving}
                  auto
                  customUpload
                  uploadHandler={async (e) => {
                    setUploadingImage(true);
                    try {
                      const fd = new FormData();
                      fd.append('image', e.files[0]);
                      const res = await api.post(`/products/${id}/image`, fd, { headers: { 'Content-Type': 'multipart/form-data' } });
                      setImageUrl(res.data.data.image_url);
                      toast.success('Imagen subida correctamente.');
                    } catch (err) {
                      toast.error(err.response?.data?.message || 'Error al subir imagen.');
                    } finally {
                      setUploadingImage(false);
                      e.options.clear();
                    }
                  }}
                  className="text-sm"
                />
              )}
              {!isEditing && <p className="text-xs text-slate-400">Guarda el producto primero para subir una imagen.</p>}
            </div>

            <div className="flex items-center gap-2">
              <input
                type="checkbox"
                id="prod_active"
                checked={formData.is_active}
                onChange={(e) => set('is_active', e.target.checked)}
                disabled={saving}
                className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
              />
              <label htmlFor="prod_active" className="text-sm text-slate-700">Producto activo</label>
            </div>
          </div>
        </div>

        {/* Precios y Stock */}
        <div className="space-y-6">
          <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <h2 className="mb-4 text-base font-semibold text-slate-900">Precios</h2>
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Costo *</label>
                <InputNumber
                  value={formData.cost_price}
                  onValueChange={(e) => set('cost_price', e.value)}
                  mode="currency"
                  currency="MXN"
                  locale="es-MX"
                  minFractionDigits={2}
                  maxFractionDigits={2}
                  min={0}
                  disabled={saving}
                  className="w-full"
                  inputClassName="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Precio Venta *</label>
                <InputNumber
                  value={formData.sale_price}
                  onValueChange={(e) => set('sale_price', e.value)}
                  mode="currency"
                  currency="MXN"
                  locale="es-MX"
                  minFractionDigits={2}
                  maxFractionDigits={2}
                  min={0}
                  disabled={saving}
                  className="w-full"
                  inputClassName="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>
            </div>
            {formData.cost_price != null && formData.sale_price != null && formData.sale_price > 0 && (
              <div className="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                Margen: {(((formData.sale_price - formData.cost_price) / formData.sale_price) * 100).toFixed(1)}%
              </div>
            )}
          </div>

          <div className="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-base font-semibold text-slate-900">Inventario</h2>
              <div className="flex items-center gap-3">
                <span className="text-sm font-medium text-slate-600">¿Llevar control de Stock?</span>
                <InputSwitch
                  checked={formData.track_stock}
                  onChange={(e) => set('track_stock', e.value)}
                  disabled={saving}
                  className="cursor-pointer"
                />
              </div>
            </div>

            {!formData.track_stock && (
              <div className="mb-4 rounded-lg bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-700">
                <strong>Modo Paquete/Combo:</strong> Este producto no controlará existencias físicas. Útil para combos, servicios o productos sin inventario.
              </div>
            )}

            <div className={`grid grid-cols-3 gap-4 transition-all duration-300 ${!formData.track_stock ? 'pointer-events-none opacity-30' : 'opacity-100'}`}>
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Stock Inicial</label>
                <InputNumber
                  value={formData.current_stock}
                  onValueChange={(e) => set('current_stock', e.value ?? 0)}
                  min={0}
                  disabled={saving || !formData.track_stock}
                  className="w-full"
                  inputClassName="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Stock Mínimo</label>
                <InputNumber
                  value={formData.minimum_stock}
                  onValueChange={(e) => set('minimum_stock', e.value ?? 0)}
                  min={0}
                  disabled={saving || !formData.track_stock}
                  className="w-full"
                  inputClassName="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>
              <div>
                <label className="mb-1.5 block text-sm font-medium text-slate-700">Stock Máximo</label>
                <InputNumber
                  value={formData.maximum_stock}
                  onValueChange={(e) => set('maximum_stock', e.value ?? 0)}
                  min={0}
                  disabled={saving || !formData.track_stock}
                  className="w-full"
                  inputClassName="w-full rounded-lg border-slate-200 px-3 py-2.5 text-sm"
                  pt={{ root: { className: 'w-full' } }}
                />
              </div>
            </div>
          </div>
        </div>

        {/* Action buttons */}
        <div className="lg:col-span-2 flex flex-wrap items-center gap-3">
          <Button
            type="button"
            label="Cancelar"
            onClick={() => navigate('/products')}
            disabled={saving}
            className="rounded-lg border border-slate-200 bg-white px-5 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
            pt={{ root: { className: 'border border-slate-200' } }}
          />
          <Button
            type="submit"
            label={saving ? 'Guardando...' : (isEditing ? 'Actualizar Producto' : 'Guardar Producto')}
            disabled={saving}
            loading={saving}
            className="rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
            pt={{ root: { className: 'border-0' } }}
          />
          {!isEditing && (
            <Button
              type="button"
              label={saving ? 'Guardando...' : 'Guardar y Crear Variacion'}
              onClick={handleSaveAndVariation}
              disabled={saving}
              loading={saving}
              className="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 disabled:opacity-50"
              pt={{ root: { className: 'border-0' } }}
            />
          )}
        </div>
      </form>
    </AppLayout>
  );
}
