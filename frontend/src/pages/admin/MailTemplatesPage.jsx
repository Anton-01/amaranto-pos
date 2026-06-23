import { useState, useEffect } from 'react';
import { ProgressSpinner } from 'primereact/progressspinner';
import { SelectButton } from 'primereact/selectbutton';
import { ListBox } from 'primereact/listbox';
import AppLayout from '../../components/layout/AppLayout';
import api from '../../api/axios';

const viewportOptions = [
  { label: 'Desktop', value: 'desktop', icon: 'pi pi-desktop' },
  { label: 'Mobile', value: 'mobile', icon: 'pi pi-mobile' },
];

export default function MailTemplatesPage() {
  const [templates, setTemplates] = useState([]);
  const [selected, setSelected] = useState(null);
  const [viewport, setViewport] = useState('desktop');
  const [html, setHtml] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    api.get('/admin/mail-templates').then((res) => {
      setTemplates(res.data.data);
      if (res.data.data.length > 0) {
        setSelected(res.data.data[0]);
      }
    });
  }, []);

  useEffect(() => {
    if (!selected) return;
    setLoading(true);
    setError(null);
    setHtml('');

    api
      .get(`/admin/mail-templates/${selected.slug}/render`, { responseType: 'text' })
      .then((res) => setHtml(typeof res.data === 'string' ? res.data : String(res.data)))
      .catch(() => setError('No se pudo cargar la vista previa de esta plantilla.'))
      .finally(() => setLoading(false));
  }, [selected]);

  const viewportItemTemplate = (option) => (
    <span className="flex items-center gap-1.5 text-xs">
      <i className={option.icon} />
      {option.label}
    </span>
  );

  return (
    <AppLayout>
      <div className="flex h-[calc(100vh-4rem)] gap-0 overflow-hidden">
        {/* Left panel */}
        <div className="w-80 shrink-0 border-r border-slate-200 bg-white">
          <div className="border-b border-slate-100 px-5 py-4">
            <h2 className="text-sm font-semibold text-slate-900">Plantillas de Correo</h2>
            <p className="mt-0.5 text-xs text-slate-500">Vista previa de los correos del sistema</p>
          </div>
          <div className="p-3">
            <ListBox
              value={selected}
              onChange={(e) => e.value && setSelected(e.value)}
              options={templates}
              optionLabel="name"
              dataKey="slug"
              className="border-none"
              itemTemplate={(option) => (
                <div className="flex items-center gap-3 py-1">
                  <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-indigo-50">
                    <i
                      className={`pi text-sm text-indigo-600 ${
                        option.slug === 'password-reset'
                          ? 'pi-key'
                          : option.slug === 'low-stock'
                            ? 'pi-exclamation-triangle'
                            : option.slug === 'petty-cash'
                              ? 'pi-wallet'
                              : 'pi-lock'
                      }`}
                    />
                  </div>
                  <span className="text-[13px] font-medium text-slate-700">{option.name}</span>
                </div>
              )}
            />
          </div>
        </div>

        {/* Right panel */}
        <div className="flex flex-1 flex-col bg-slate-100">
          {/* Toolbar */}
          <div className="flex items-center justify-between border-b border-slate-200 bg-white px-5 py-3">
            <div className="flex items-center gap-2">
              <span className="text-sm font-medium text-slate-700">
                {selected?.name || 'Selecciona una plantilla'}
              </span>
              {selected && (
                <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-slate-500">
                  {selected.slug}
                </span>
              )}
            </div>
            <SelectButton
              value={viewport}
              onChange={(e) => e.value && setViewport(e.value)}
              options={viewportOptions}
              optionLabel="label"
              optionValue="value"
              itemTemplate={viewportItemTemplate}
              className="text-xs"
            />
          </div>

          {/* Viewer */}
          <div className="flex flex-1 items-start justify-center overflow-auto p-6">
            {!selected && (
              <div className="flex h-96 items-center justify-center">
                <p className="text-sm text-slate-400">Selecciona una plantilla del panel izquierdo</p>
              </div>
            )}

            {selected && loading && (
              <div className="flex h-96 flex-col items-center justify-center gap-3">
                <ProgressSpinner
                  style={{ width: '40px', height: '40px' }}
                  strokeWidth="4"
                  animationDuration=".8s"
                />
                <p className="text-sm text-slate-500">Renderizando plantilla...</p>
              </div>
            )}

            {selected && error && (
              <div className="mx-auto max-w-md rounded-xl border border-rose-200 bg-rose-50 px-6 py-8 text-center">
                <i className="pi pi-times-circle mb-3 text-3xl text-rose-400" />
                <p className="text-sm font-medium text-rose-700">{error}</p>
                <button
                  onClick={() => {
                    const current = selected;
                    setSelected(null);
                    setTimeout(() => setSelected(current), 50);
                  }}
                  className="mt-4 rounded-lg bg-rose-100 px-4 py-2 text-xs font-semibold text-rose-700 transition-colors hover:bg-rose-200"
                >
                  Reintentar
                </button>
              </div>
            )}

            {selected && !loading && !error && html && (
              <iframe
                srcDoc={html}
                title={`Preview — ${selected.name}`}
                className={`h-[650px] bg-white rounded-lg shadow-sm border border-slate-200 transition-all duration-300 ${
                  viewport === 'mobile' ? 'max-w-[375px]' : 'max-w-[600px]'
                } w-full`}
                sandbox="allow-same-origin"
              />
            )}
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
