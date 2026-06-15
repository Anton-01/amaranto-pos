import { useAuth } from '../context/AuthContext';
import AppLayout from '../components/layout/AppLayout';

export default function DashboardPage() {
  const { user } = useAuth();

  return (
    <AppLayout>
      <h1 className="text-2xl font-bold text-slate-900">Dashboard</h1>
      <p className="mt-2 text-slate-500">
        Bienvenido, {user?.name}. Selecciona un modulo del menu para comenzar.
      </p>
    </AppLayout>
  );
}
