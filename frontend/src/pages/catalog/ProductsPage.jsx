import { useState, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { InputText } from 'primereact/inputtext';
import { InputNumber } from 'primereact/inputnumber';
import { MultiSelect } from 'primereact/multiselect';
import { Dropdown } from 'primereact/dropdown';
import { Tag } from 'primereact/tag';
import { Button } from 'primereact/button';
import { FilterMatchMode } from 'primereact/api';
import { toast } from 'sonner';
import api from '../../api/axios';
import AppLayout from '../../components/layout/AppLayout';
import DeleteDialog from '../../components/catalog/DeleteDialog';

const statusOptions = [
  { label: 'Activo', value: true },
  { label: 'Inactivo', value: false },
];

export default function ProductsPage() {
  const navigate = useNavigate();
  const [products, setProducts] = useState([]);
  const [categories, setCategories] = useState([]);
  const [loading, setLoading] = useState(true);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleting, setDeleting] = useState(false);

  const [filters, setFilters] = useState({
    global: { value: null, matchMode: FilterMatchMode.CONTAINS },
    name: { value: null, matchMode: FilterMatchMode.CONTAINS },
    'category.name': { value: null, matchMode: FilterMatchMode.IN },
    sale_price: { value: null, matchMode: FilterMatchMode.BETWEEN },
    is_active: { value: null, matchMode: FilterMatchMode.EQUALS },
  });

  const fetchData = useCallback(async () => {
    setLoading(true);
    try {
      const [prodRes, catRes] = await Promise.all([
        api.get('/products'),
        api.get('/categories'),
      ]);
      setProducts(prodRes.data.data);
      setCategories(catRes.data.data);
    } catch {
      toast.error('Error al cargar datos.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchData(); }, [fetchData]);

  const handleDelete = async (reason) => {
    setDeleting(true);
    try {
      await api.delete(`/products/${deleteTarget.id}`, { data: { deletion_reason: reason } });
      toast.success('Producto eliminado.');
      setDeleteTarget(null);
      fetchData();
    } catch (err) {
      toast.error(err.response?.data?.message || 'Error al eliminar.');
    } finally {
      setDeleting(false);
    }
  };

  const priceTemplate = (row, field) => {
    const val = parseFloat(row[field]);
    return `$${val.toLocaleString('es-MX', { minimumFractionDigits: 2 })}`;
  };

  const stockTemplate = (row) => {
    if (row.track_stock === false) {
      return <Tag value="ILIMITADO" severity="info" className="text-xs" />;
    }
    const isLow = row.current_stock <= row.minimum_stock;
    return (
      <span className={isLow ? 'font-semibold text-rose-600' : 'text-slate-900'}>
        {row.current_stock}
        {isLow && <span className="ml-1 text-xs">bajo</span>}
      </span>
    );
  };

  const statusTemplate = (row) => (
    <Tag
      value={row.is_active ? 'Activo' : 'Inactivo'}
      severity={row.is_active ? 'success' : 'danger'}
      className="text-xs"
    />
  );

  const categoryTemplate = (row) => (
    <span className="rounded-md bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">
      {row.category?.name}
    </span>
  );

  const actionsTemplate = (row) => (
    <div className="flex gap-1">
      <Button
        icon="pi pi-pencil"
        severity="info"
        text
        rounded
        onClick={() => navigate(`/products/${row.id}/edit`)}
        className="cursor-pointer !h-8 !w-8"
        tooltip="Editar"
        tooltipOptions={{ position: 'top' }}
      />
      <Button
        icon="pi pi-trash"
        severity="danger"
        text
        rounded
        onClick={() => setDeleteTarget(row)}
        className="cursor-pointer !h-8 !w-8"
        tooltip="Eliminar"
        tooltipOptions={{ position: 'top' }}
      />
    </div>
  );

  const nameFilterTemplate = (options) => (
    <InputText
      value={options.value || ''}
      onChange={(e) => options.filterApplyCallback(e.target.value)}
      placeholder="Buscar nombre..."
      className="w-full rounded border-slate-200 px-2 py-1.5 text-xs"
      pt={{ root: { className: 'w-full' } }}
    />
  );

  const categoryFilterTemplate = (options) => {
    const catOptions = categories.map(c => ({ label: c.name, value: c.name }));
    return (
      <MultiSelect
        value={options.value}
        options={catOptions}
        onChange={(e) => options.filterApplyCallback(e.value)}
        placeholder="Filtrar..."
        className="w-full text-xs"
        pt={{ root: { className: 'w-full' } }}
      />
    );
  };

  const statusFilterTemplate = (options) => (
    <Dropdown
      value={options.value}
      options={statusOptions}
      onChange={(e) => options.filterApplyCallback(e.value)}
      placeholder="Todos"
      className="w-full text-xs"
      pt={{ root: { className: 'w-full' } }}
    />
  );

  const priceFilterTemplate = (options) => (
    <div className="flex gap-1">
      <InputNumber
        value={options.value?.[0] ?? null}
        onValueChange={(e) => {
          const val = [e.value, options.value?.[1] ?? null];
          options.filterApplyCallback(val);
        }}
        placeholder="Min"
        mode="currency"
        currency="MXN"
        locale="es-MX"
        maxFractionDigits={2}
        className="w-24 text-xs"
        inputClassName="w-full px-1 py-1 text-xs"
      />
      <InputNumber
        value={options.value?.[1] ?? null}
        onValueChange={(e) => {
          const val = [options.value?.[0] ?? null, e.value];
          options.filterApplyCallback(val);
        }}
        placeholder="Max"
        mode="currency"
        currency="MXN"
        locale="es-MX"
        maxFractionDigits={2}
        className="w-24 text-xs"
        inputClassName="w-full px-1 py-1 text-xs"
      />
    </div>
  );

  return (
    <AppLayout>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Productos</h1>
          <p className="text-sm text-slate-500">{products.length} producto(s) en el catalogo.</p>
        </div>
        <Button
          label="Nuevo Producto"
          onClick={() => navigate('/products/create')}
          className="rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
          pt={{ root: { className: 'border-0' } }}
        />
      </div>

      <div className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
        <DataTable
          value={products}
          loading={loading}
          filters={filters}
          onFilter={(e) => setFilters(e.filters)}
          filterDisplay="row"
          emptyMessage="No se encontraron productos."
          sortField="name"
          sortOrder={1}
          stripedRows
          paginator
          rows={20}
          rowsPerPageOptions={[10, 20, 50]}
          pt={{
            root: { className: 'text-sm' },
            thead: { className: 'bg-slate-50' },
          }}
        >
          <Column
            field="sku"
            header="SKU"
            sortable
            className="font-mono text-xs"
            style={{ width: '10%' }}
          />
          <Column
            field="name"
            header="Nombre"
            sortable
            filter
            filterElement={nameFilterTemplate}
            showFilterMenu={false}
            style={{ width: '22%' }}
          />
          <Column
            field="parent_sku"
            header="Grupo"
            sortable
            className="font-mono text-xs text-slate-500"
            style={{ width: '10%' }}
          />
          <Column
            field="category.name"
            header="Categoria"
            body={categoryTemplate}
            filter
            filterElement={categoryFilterTemplate}
            showFilterMenu={false}
            style={{ width: '14%' }}
          />
          <Column
            field="cost_price"
            header="Costo"
            body={(row) => priceTemplate(row, 'cost_price')}
            sortable
            className="text-right"
            style={{ width: '10%' }}
          />
          <Column
            field="sale_price"
            header="Precio Venta"
            body={(row) => priceTemplate(row, 'sale_price')}
            sortable
            filter
            filterElement={priceFilterTemplate}
            showFilterMenu={false}
            className="text-right"
            style={{ width: '12%' }}
          />
          <Column
            field="current_stock"
            header="Stock"
            body={stockTemplate}
            sortable
            className="text-center"
            style={{ width: '8%' }}
          />
          <Column
            field="is_active"
            header="Estatus"
            body={statusTemplate}
            filter
            filterElement={statusFilterTemplate}
            showFilterMenu={false}
            style={{ width: '8%' }}
          />
          <Column
            header="Acciones"
            body={actionsTemplate}
            style={{ width: '10%' }}
          />
        </DataTable>
      </div>

      <DeleteDialog
        visible={!!deleteTarget}
        onHide={() => setDeleteTarget(null)}
        onConfirm={handleDelete}
        loading={deleting}
        itemName={deleteTarget?.name || 'producto'}
      />
    </AppLayout>
  );
}
