import { useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { OverlayPanel } from 'primereact/overlaypanel';
import { toast } from 'sonner';
import { useAuth } from '../../context/AuthContext';

const roleColors = {
  admin: { bg: 'bg-rose-50', text: 'text-rose-700', border: 'border-rose-200' },
  manager: { bg: 'bg-amber-50', text: 'text-amber-700', border: 'border-amber-200' },
  vendor: { bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200' },
};

export default function UserProfileDropdown() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const overlayRef = useRef(null);

  const role = user?.roles?.[0] || 'vendor';
  const colors = roleColors[role] || roleColors.vendor;
  const initials = (user?.name || 'U')
    .split(' ')
    .map((w) => w[0])
    .join('')
    .slice(0, 2)
    .toUpperCase();

  const handleLogout = async () => {
    overlayRef.current?.hide();
    await logout();
    toast.success('Sesion cerrada correctamente.');
    navigate('/login');
  };

  const handleNavigate = (path) => {
    overlayRef.current?.hide();
    navigate(path);
  };

  return (
    <>
      <button
        onClick={(e) => overlayRef.current?.toggle(e)}
        className="flex cursor-pointer items-center gap-2.5 rounded-xl border border-slate-200 bg-white p-1.5 shadow-sm transition-all hover:border-slate-300 hover:shadow-md sm:pr-3"
      >
        <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br from-indigo-500 to-indigo-700 text-xs font-bold text-white shadow-inner">
          {initials}
        </div>
        <div className="hidden text-left sm:block">
          <p className="text-[13px] font-semibold leading-tight text-slate-800">{user?.name}</p>
          <p className="text-[11px] capitalize text-slate-400">{role}</p>
        </div>
        <svg className="ml-0.5 hidden h-4 w-4 text-slate-400 sm:block" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
          <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
        </svg>
      </button>

      <OverlayPanel
        ref={overlayRef}
        className="w-72 max-w-[calc(100vw-1.5rem)] !rounded-2xl !border-slate-200 !p-0 !shadow-xl !shadow-slate-200/50"
        pt={{
          content: { className: '!p-0' },
        }}
      >
        <div className="p-4 sm:p-5">
          <div className="flex items-center gap-3.5">
            <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-indigo-700 text-base font-bold text-white shadow-md shadow-indigo-200">
              {initials}
            </div>
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-semibold text-slate-900">{user?.name}</p>
              <p className="mt-0.5 truncate text-xs text-slate-500">{user?.email}</p>
              <span
                className={`mt-1.5 inline-flex items-center rounded-md border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${colors.bg} ${colors.text} ${colors.border}`}
              >
                {role}
              </span>
            </div>
          </div>
        </div>

        <div className="border-t border-slate-100" />

        <div className="px-2 py-2">
          <button
            onClick={() => handleNavigate('/profile')}
            className="flex w-full cursor-pointer items-center gap-3 rounded-xl px-3 py-3 text-left text-sm text-slate-700 transition-colors hover:bg-slate-50 sm:py-2.5"
          >
            <svg className="h-[18px] w-[18px] text-slate-400" fill="none" viewBox="0 0 24 24" strokeWidth={1.75} stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
            </svg>
            <span className="font-medium">Mi Perfil</span>
          </button>
          <button
            onClick={() => handleNavigate('/profile/notifications')}
            className="flex w-full cursor-pointer items-center gap-3 rounded-xl px-3 py-3 text-left text-sm text-slate-700 transition-colors hover:bg-slate-50 sm:py-2.5"
          >
            <svg className="h-[18px] w-[18px] text-slate-400" fill="none" viewBox="0 0 24 24" strokeWidth={1.75} stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
              <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
            <span className="font-medium">Configurar Alertas</span>
          </button>
        </div>

        <div className="border-t border-slate-100" />

        <div className="p-2">
          <button
            onClick={handleLogout}
            className="flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl border border-rose-200 py-3 text-sm font-semibold text-rose-600 transition-colors hover:bg-rose-50 sm:py-2.5"
          >
            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9" />
            </svg>
            Cerrar Sesion
          </button>
        </div>
      </OverlayPanel>
    </>
  );
}
