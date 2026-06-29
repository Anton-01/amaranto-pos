import { useEffect } from 'react';
import { useLocation } from 'react-router-dom';

const PAGE_TITLES = {
  '/dashboard': 'Dashboard',
  '/pos': 'Punto de Venta',
  '/ticket-config': 'Configuracion de Tickets',
  '/products': 'Productos',
  '/products/create': 'Nuevo Producto',
  '/categories': 'Categorias',
  '/promotions': 'Promociones',
  '/stock-movements': 'Almacen',
  '/petty-cash': 'Caja Chica',
  '/finance': 'Panel Financiero',
  '/admin/usuarios': 'Gestion de Usuarios',
  '/admin/ventas': 'Historial de Tickets',
  '/admin/metodos-pago': 'Metodos de Pago',
  '/admin/roles-permisos': 'Roles y Permisos',
  '/admin/configuracion': 'Configuracion del Sistema',
  '/admin/notificaciones/plantillas': 'Plantillas de Correo',
  '/admin/papelera': 'Papelera Global',
  '/admin/cierres': 'Cierres de Caja',
  '/profile': 'Mi Perfil',
  '/profile/notifications': 'Preferencias de Notificaciones',
  '/login': 'Iniciar Sesion',
};

const BASE_TITLE = 'Amaranto POS';

export default function usePageTitle() {
  const { pathname } = useLocation();

  useEffect(() => {
    let moduleName = PAGE_TITLES[pathname];

    if (!moduleName) {
      const editMatch = pathname.match(/^\/products\/[^/]+\/edit$/);
      if (editMatch) moduleName = 'Editar Producto';
    }

    document.title = moduleName ? `${BASE_TITLE} - ${moduleName}` : BASE_TITLE;
  }, [pathname]);
}
