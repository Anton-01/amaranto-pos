import { useEffect, useState, useCallback } from 'react';
import { InputSwitch } from 'primereact/inputswitch';
import { ProgressSpinner } from 'primereact/progressspinner';
import { toast } from 'sonner';
import api from '../../api/axios';
import AppLayout from '../../components/layout/AppLayout';

export default function NotificationPreferencesPage() {
  const [types, setTypes] = useState([]);
  const [loading, setLoading] = useState(true);
  const [updating, setUpdating] = useState({});

  const fetchPreferences = useCallback(async () => {
    try {
      const { data } = await api.get('/profile/notifications');
      setTypes(data.data);
    } catch {
      toast.error('Error al cargar las preferencias de notificación.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchPreferences();
  }, [fetchPreferences]);

  const handleToggle = async (typeId, channel, newValue) => {
    const key = `${typeId}-${channel}`;
    if (updating[key]) return;

    setUpdating((prev) => ({ ...prev, [key]: true }));

    setTypes((prev) =>
      prev.map((t) =>
        t.id === typeId
          ? { ...t, channels: { ...t.channels, [channel]: newValue } }
          : t
      )
    );

    try {
      await api.put('/profile/notifications', {
        preferences: [
          {
            notification_type_id: typeId,
            channel,
            is_enabled: newValue,
          },
        ],
      });
      toast.success('Preferencia actualizada.');
    } catch {
      setTypes((prev) =>
        prev.map((t) =>
          t.id === typeId
            ? { ...t, channels: { ...t.channels, [channel]: !newValue } }
            : t
        )
      );
      toast.error('Error al actualizar la preferencia.');
    } finally {
      setUpdating((prev) => {
        const next = { ...prev };
        delete next[key];
        return next;
      });
    }
  };

  const channelLabel = { mail: 'Correo Electrónico', database: 'Alertas Push en Sistema' };

  if (loading) {
    return (
      <AppLayout>
        <div className="flex min-h-[60vh] items-center justify-center">
          <ProgressSpinner style={{ width: '50px', height: '50px' }} />
        </div>
      </AppLayout>
    );
  }

  return (
    <AppLayout>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-900">Preferencias de Notificaciones</h1>
        <p className="mt-1 text-sm text-slate-500">
          Configura cómo deseas recibir alertas del sistema. Los cambios se aplican de inmediato.
        </p>
      </div>

      {types.length === 0 ? (
        <div className="rounded-xl border border-slate-200 bg-white p-12 text-center shadow-sm">
          <p className="text-slate-500">No hay tipos de notificación disponibles para tu rol.</p>
        </div>
      ) : (
        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
          <table className="w-full">
            <thead>
              <tr className="border-b border-slate-200 bg-slate-50">
                <th className="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                  Notificación
                </th>
                {Object.values(channelLabel).map((label) => (
                  <th
                    key={label}
                    className="px-6 py-3 text-center text-xs font-semibold uppercase tracking-wider text-slate-500"
                  >
                    {label}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {types.map((type) => (
                <tr key={type.id} className="transition-colors hover:bg-slate-50/50">
                  <td className="px-6 py-4">
                    <p className="text-sm font-medium text-slate-900">{type.name}</p>
                    <p className="mt-0.5 text-xs text-slate-500">{type.description}</p>
                  </td>
                  {Object.keys(channelLabel).map((channel) => {
                    const key = `${type.id}-${channel}`;
                    return (
                      <td key={channel} className="px-6 py-4 text-center">
                        <div className="inline-flex items-center gap-2">
                          <InputSwitch
                            checked={type.channels[channel]}
                            onChange={(e) => handleToggle(type.id, channel, e.value)}
                            disabled={!!updating[key]}
                          />
                          {updating[key] && (
                            <ProgressSpinner
                              style={{ width: '16px', height: '16px' }}
                              strokeWidth="4"
                            />
                          )}
                        </div>
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </AppLayout>
  );
}
