import { useState, useEffect } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import Sidebar from './Sidebar';
import AppHeader from './AppHeader';

/**
 * Chrome de la aplicacion (sidebar + header + area de contenido).
 *
 * Admite las DOS formas de uso que conviven hoy:
 *
 *  - `<AppLayout><Pagina/></AppLayout>` — cada pagina monta su propio shell.
 *    Es el patron historico del resto del sistema.
 *  - Como *layout route* de React Router, sin hijos: renderiza `<Outlet/>`.
 *    Asi lo usa `PersistentShell` para /pos y /mesas (Fase 11), de modo que el
 *    sidebar y el header NO se desmontan al alternar entre esas dos vistas.
 *
 * MOBILE-FIRST SHELL
 * ------------------
 * The shell runs two independent navigation states, one per form factor, so
 * neither can corrupt the other:
 *
 *  - `collapsed` (>= lg) — the historic desktop rail. Toggling it shrinks the
 *    sidebar to a 72px icon rail and pulls the content margin with it. Under
 *    `lg` this state is inert: every class it drives is `lg:`-prefixed, so a
 *    phone always gets the full-width labelled drawer regardless of the value
 *    the user left behind on desktop.
 *  - `mobileNavOpen` (< lg) — the off-canvas drawer. The sidebar is translated
 *    fully off-screen by default and slides in over a dimmed backdrop.
 *
 * The content column carries NO left margin below `lg`: the drawer overlays
 * the page instead of displacing it, which is what keeps narrow viewports free
 * of the horizontal scrollbar that a permanent `ml-64` would force.
 */
export default function AppLayout({ children }) {
  const [collapsed, setCollapsed] = useState(false);
  const [mobileNavOpen, setMobileNavOpen] = useState(false);
  const { pathname } = useLocation();

  /*
   * A tap on a drawer entry navigates; leaving the drawer open on top of the
   * destination would hide the very page the user just asked for.
   *
   * This is the "adjust state during render" pattern rather than an effect:
   * the drawer must already be closed in the first frame that paints the new
   * route. An effect would close it one commit late, which reads as a flash of
   * the menu over the destination.
   */
  const [lastPath, setLastPath] = useState(pathname);
  if (pathname !== lastPath) {
    setLastPath(pathname);
    setMobileNavOpen(false);
  }

  /*
   * Scroll lock while the drawer is open.
   *
   * The drawer is a fixed overlay, so without this the page underneath keeps
   * scrolling behind it under the finger — the classic mobile "scroll bleed".
   * The previous value is captured and restored so the lock composes with any
   * other component that sets its own overflow (PrimeReact dialogs do).
   */
  useEffect(() => {
    if (!mobileNavOpen) return undefined;

    const previous = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    return () => {
      document.body.style.overflow = previous;
    };
  }, [mobileNavOpen]);

  // Escape closes the drawer, the standard affordance for any modal overlay.
  useEffect(() => {
    if (!mobileNavOpen) return undefined;

    const onKeyDown = (event) => {
      if (event.key === 'Escape') setMobileNavOpen(false);
    };

    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [mobileNavOpen]);

  return (
    // `overflow-x-hidden` is the last line of defence: any single runaway child
    // deep in a page can no longer drag the whole viewport sideways.
    <div className="flex min-h-screen w-full overflow-x-hidden bg-slate-50">
      <Sidebar
        collapsed={collapsed}
        onToggle={() => setCollapsed((v) => !v)}
        mobileOpen={mobileNavOpen}
        onCloseMobile={() => setMobileNavOpen(false)}
      />

      {/* Backdrop — mobile only, and only while the drawer is out. */}
      <div
        onClick={() => setMobileNavOpen(false)}
        aria-hidden="true"
        className={`fixed inset-0 z-40 bg-slate-900/50 backdrop-blur-[2px] transition-opacity duration-300 lg:hidden ${
          mobileNavOpen ? 'opacity-100' : 'pointer-events-none opacity-0'
        }`}
      />

      <div
        className={`flex min-h-screen min-w-0 flex-1 flex-col transition-all duration-300 ease-in-out ${
          collapsed ? 'lg:ml-[72px]' : 'lg:ml-64'
        }`}
      >
        <AppHeader
          collapsed={collapsed}
          onToggleSidebar={() => setCollapsed((v) => !v)}
          onOpenMobileNav={() => setMobileNavOpen(true)}
        />

        {/* Mobile-first padding: tight on phones, roomy from sm upwards. */}
        <main className="min-w-0 flex-1 p-3 sm:p-4 lg:p-6">
          {children ?? <Outlet />}
        </main>
      </div>
    </div>
  );
}
