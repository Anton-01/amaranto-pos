# Estado Actual del Sistema POS - Cronos

## 1. Arquitectura General
- Backend: Laravel 13 (API-First), PHP 8.3 Alpine
- Frontend: React 18/19, Tailwind CSS v4, PrimeReact, Sonner
- Base de Datos: Managed PostgreSQL (DigitalOcean)
- Estado de Infraestructura: Docker Compose configurado y funcional.

## 2. Matriz de Modulos y Progreso
| Modulo | Estado Backend | Estado Frontend | Observaciones |
| :--- | :--- | :--- | :--- |
| Infraestructura & Docker | [🟢 Completado] | [🟢 Completado] | Dockerfiles ligeros listos |
| Migraciones & Modelos Base (BD) | [🟢 Completado] | N/A | 18 migraciones, 15 modelos, Trait AdvancedSoftDeletes, Seeder base |
| Autenticacion & 2FA (Sanctum) | [🟢 Completado] | [🟢 Completado] | Login, Logout, 2FA TOTP, Kill-Switch, Session Log |
| Catalogo, Categorias y Variaciones| [🟢 Completado] | [🟢 Completado] | CRUD completo, DataTable filtros avanzados, variaciones en serie |
| Promociones e Historicos | [🟢 Completado] | [🟢 Completado] | CRUD con RBAC, limite 1 promo/ticket, POS cart con validacion cruzada |
| Usuarios, Roles y Permisos (RBAC)| [⚪ Pendiente] | [⚪ Pendiente] | - |
| Caja Chica, Retiros e Integridad| [⚪ Pendiente] | [⚪ Pendiente] | - |
| Ventas, Ticket Config & Historico | [⚪ Pendiente] | [⚪ Pendiente] | - |
| Finanzas Avanzadas (70/30) | [⚪ Pendiente] | [⚪ Pendiente] | - |
| Notificaciones Reverb (Push/Mail)| [⚪ Pendiente] | [⚪ Pendiente] | - |
| Papelera Global (Soft Deletes)| [⚪ Pendiente] | [⚪ Pendiente] | - |

## 3. Detalle del Modulo Completado: Migraciones & Modelos Base

### Migraciones (PostgreSQL)
Todas las tablas utilizan UUID como llave primaria. Tipos monetarios: `NUMERIC(12,2)`. Tipos JSONB para snapshots inmutables y configuraciones. ENUMs nativos de PostgreSQL para estatus y tipos.

| # | Migracion | Tabla |
| :--- | :--- | :--- |
| 00 | create_enums | ENUMs nativos: user_status, payment_method, promotion_type, stock_movement_type, stock_movement_reason, petty_cash_reason |
| 01 | create_global_settings_table | global_settings (PK: key VARCHAR) |
| 02 | create_roles_table | roles |
| 03 | create_users_table | users (con columnas AdvancedSoftDeletes + 2FA) |
| 04 | create_model_has_roles_table | model_has_roles (pivot, PK compuesto) |
| 05 | create_user_sessions_log_table | user_sessions_log |
| 06 | create_ticket_configs_table | ticket_configs |
| 07 | create_categories_table | categories (con AdvancedSoftDeletes) |
| 08 | create_products_table | products (con AdvancedSoftDeletes) |
| 09 | create_promotions_table | promotions (con AdvancedSoftDeletes) |
| 10 | create_product_promotion_table | product_promotion (pivot, PK compuesto) |
| 11 | create_cash_registers_table | cash_registers |
| 12 | create_orders_table | orders |
| 13 | create_order_items_table | order_items (campos `_at_sale` inmutables) |
| 14 | create_stock_movements_table | stock_movements |
| 15 | create_petty_cash_transactions_table | petty_cash_transactions (JSONB immutable_snapshot) |
| 16 | create_notification_types_table | notification_types |
| 17 | create_user_notification_preferences_table | user_notification_preferences (PK compuesto triple) |

### Modelos Eloquent
- `User` (HasUuids, HasApiTokens, AdvancedSoftDeletes, Notifiable)
- `Role`, `GlobalSetting`, `UserSessionLog`, `TicketConfig`
- `Category`, `Product`, `Promotion` (todos con AdvancedSoftDeletes)
- `CashRegister`, `Order`, `OrderItem`
- `StockMovement`, `PettyCashTransaction`
- `NotificationType`, `UserNotificationPreference`

### Trait: AdvancedSoftDeletes
- Extiende SoftDeletes nativo de Laravel
- Administra automaticamente: `deleted_at`, `deleted_by`, `deletion_reason`
- Metodos: `advancedDelete($deletedBy, $reason)`, `advancedRestore()`, `deletedByUser()`

### Seeder (DatabaseSeeder)
- 3 roles base: `admin`, `manager`, `vendor`
- Usuario Admin inicial: admin@cronos.pos / password
- 6 tipos de notificacion base con roles permitidos configurados

### Restricciones de Integridad
- `order_items.product_id` -> `onDelete('set null')` (preserva historico si producto se purga)
- `order_items.order_id` -> `onDelete('cascade')`
- `model_has_roles` -> ambas FK con `onDelete('cascade')`
- `product_promotion` -> ambas FK con `onDelete('cascade')`
- `user_notification_preferences` -> ambas FK con `onDelete('cascade')`
- Tablas financieras (orders, cash_registers, stock_movements, petty_cash) -> `onDelete('restrict')`

## 4. Detalle del Modulo Completado: Autenticacion & 2FA (Sanctum)

### Backend - API Endpoints (7 rutas)
| Metodo | Ruta | Middleware | Descripcion |
| :--- | :--- | :--- | :--- |
| POST | /api/auth/login | ninguno | Login con email/password. Retorna token o 2FA_CHALLENGE |
| POST | /api/auth/2fa/verify | auth:sanctum | Valida codigo TOTP de 6 digitos con token temporal |
| POST | /api/auth/logout | auth:sanctum, user.active | Cierra sesion, registra logout_at en session_log |
| GET | /api/auth/me | auth:sanctum, user.active | Retorna datos del usuario autenticado con roles |
| POST | /api/auth/2fa/setup | auth:sanctum, user.active | Genera secreto TOTP + QR code en Base64 (SVG) |
| POST | /api/auth/2fa/confirm | auth:sanctum, user.active | Confirma 2FA con codigo, genera recovery codes |
| POST | /api/auth/2fa/disable | auth:sanctum, user.active | Desactiva 2FA previo codigo valido |

### Flujo de Autenticacion
1. Login con credenciales -> Si 2FA activo: retorna `2FA_CHALLENGE` + token temporal (5 min, ability: `2fa-challenge`)
2. El token temporal NO tiene permisos operativos (solo puede usarse en `/2fa/verify`)
3. Verificacion exitosa del TOTP -> Revoca token temporal, emite token completo (`*`)
4. Cada login exitoso crea registro en `user_sessions_log` (IP, User Agent)
5. Cada logout actualiza `logout_at` en la sesion activa

### Middleware: EnsureUserIsActive (Kill-Switch)
- Alias: `user.active`
- Verifica `status === 'suspended'` en cada request protegida
- Si suspendido: revoca TODOS los tokens (`$user->tokens()->delete()`) y retorna 403
- Formato de respuesta: catalogo corporativo JSON homogeneo

### Paquetes Instalados
- `laravel/sanctum` v4.3 - Autenticacion API con tokens
- `pragmarx/google2fa-laravel` v3.0 - Generacion/validacion TOTP
- `bacon/bacon-qr-code` v3.1 - Generacion QR codes para Google Authenticator

### Controladores
- `AuthController` - Login, Logout, Verify2FA, Me
- `TwoFactorController` - Setup, Confirm, Disable

### Frontend React
- **LoginPage** (`/login`) - Vista de login con InputText + Password (PrimeReact), diseno Tailwind v4 con fondo slate-50, tarjeta blanca con sombra, acento indigo-600
- **TwoFactorModal** - Dialog animado de PrimeReact con backdrop-blur-sm, InputOtp de 6 digitos, bloqueo de cierre durante verificacion
- **DashboardPage** (`/dashboard`) - Vista protegida basica con header y boton de logout
- **AuthContext** - Provider React con estado global de autenticacion (login, logout, fetchUser)
- **Axios Interceptor** - Interceptor global que maneja suspension (403), expiracion de token (401), y redirecciones
- **Sonner** - Notificaciones toast para exito/error de autenticacion
- **Rutas protegidas** - ProtectedRoute (requiere auth) y GuestRoute (redirige si ya autenticado)

## 5. Detalle del Modulo Completado: Catalogo, Categorias y Variaciones

### Backend - API Endpoints (11 rutas)
| Metodo | Ruta | Descripcion |
| :--- | :--- | :--- |
| GET | /api/categories | Lista categorias con filtros (search, is_active), incluye count de productos |
| POST | /api/categories | Crear categoria |
| GET | /api/categories/{id} | Detalle de categoria |
| PUT | /api/categories/{id} | Actualizar categoria |
| DELETE | /api/categories/{id} | Borrado logico con motivo obligatorio (AdvancedSoftDeletes) |
| GET | /api/products | Lista productos con filtros avanzados (search, category_id[], is_active, sale_price_min/max, parent_sku, low_stock) |
| POST | /api/products | Crear producto |
| GET | /api/products/{id} | Detalle con variaciones del mismo parent_sku |
| PUT | /api/products/{id} | Actualizar producto |
| DELETE | /api/products/{id} | Borrado logico con motivo obligatorio |
| GET | /api/products/grouped | Productos agrupados por parent_sku para punto de venta |

### FormRequests (Validacion estricta)
- `StoreCategoryRequest`, `UpdateCategoryRequest`, `DeleteCategoryRequest`
- `StoreProductRequest`, `UpdateProductRequest`, `DeleteProductRequest`
- SKU unico validado con `unique:products,sku` (ignorando el registro actual en update)
- Precios validados como `numeric|min:0|max:9999999999.99`
- `deletion_reason` obligatorio en toda eliminacion logica

### Reglas de Negocio
- Categoria no se puede eliminar si tiene productos activos (retorna 409 con count)
- Productos eliminados logicamente con `advancedDelete()` registran `deleted_by` y `deletion_reason`
- `parent_sku` agrupa variaciones logicamente para el punto de venta
- Endpoint `/products/grouped` devuelve productos activos agrupados por parent_sku

### Frontend React - Paginas
- **CategoriesPage** (`/categories`) - DataTable con busqueda global, CRUD inline via modales, borrado con motivo
- **ProductsPage** (`/products`) - DataTable avanzado con filtros por columna
- **ProductFormPage** (`/products/create`, `/products/:id/edit`) - Formulario con "Guardar y Crear Variacion"

### Componentes Compartidos
- **AppLayout** - Layout unificado con navbar (Dashboard, POS, Productos, Categorias, Promociones)
- **DeleteDialog** - Modal reutilizable para borrado logico con campo de motivo obligatorio

## 6. Detalle del Modulo Completado: Promociones e Historicos

### Backend - API Endpoints (7 rutas)
| Metodo | Ruta | Middleware | Descripcion |
| :--- | :--- | :--- | :--- |
| GET | /api/promotions | auth, user.active | Lista todas las promociones con productos vinculados |
| GET | /api/promotions/active | auth, user.active | Solo promociones vigentes (is_active + dentro de fecha) |
| GET | /api/promotions/{id} | auth, user.active | Detalle de promocion con productos |
| POST | /api/promotions | auth, user.active, **role:admin,manager** | Crear promocion (RBAC restringido) |
| PUT | /api/promotions/{id} | auth, user.active, **role:admin,manager** | Actualizar promocion (RBAC restringido) |
| DELETE | /api/promotions/{id} | auth, user.active, **role:admin,manager** | Borrado logico (RBAC restringido) |

### Middleware: EnsureUserHasRole (RBAC)
- Alias: `role`
- Acepta multiples roles: `role:admin,manager`
- Verifica que el usuario autenticado tenga al menos uno de los roles requeridos
- Retorna 403 con `ERR_AUTH_FORBIDDEN_ROLE` si no cumple

### Validacion de Orden: StoreOrderRequest
- Valida el array de `items` con `product_id`, `quantity`, `promotion_id` (nullable)
- **Regla critica**: Cuenta los `promotion_id` unicos no-null en todo el payload
- Si hay mas de 1 promocion distinta -> bloquea con `ERR_POS_PROMOTION_LIMIT_EXCEEDED` (422)
- Override de `failedValidation()` para retornar formato de error corporativo con el codigo especifico

### FormRequests
- `StorePromotionRequest` - name, type (enum), value, start/end_date, is_active, product_ids[]
- `UpdatePromotionRequest` - Todos los campos opcionales, product_ids[] para sync
- `DeletePromotionRequest` - deletion_reason obligatorio

### Controlador: PromotionController
- CRUD completo con sync de product_promotion pivot
- Endpoint `/active` filtra por is_active + rango de fechas vigente
- Borrado logico con advancedDelete()

### Frontend - Paginas

#### PromotionsPage (`/promotions`)
- DataTable con columnas: nombre, tipo (Tag coloreado), valor, productos, inicio, fin, estatus (Vigente/Programada/Inactiva)
- CRUD via modales con campos: nombre, tipo (Dropdown), valor (InputNumber adaptativo), fechas (Calendar con hora), productos (MultiSelect con filtro), activa (checkbox)
- Botones de editar/eliminar solo visibles si el usuario tiene rol admin o manager
- Vendor solo ve la tabla en modo lectura

#### POSPage (`/pos`) - Vista de Punto de Venta
- **Catalogo**: Productos agrupados por parent_sku en tarjetas clicables, busqueda por nombre/SKU
- **Carrito (Ticket de Venta)**: Lista de items con cantidad +/-, precio, eliminar
- **Sistema de Promocion Unica por Ticket**:
  - Estado reactivo `hasActivePromotion` evaluado con `useMemo` sobre el carrito
  - Si ya hay una promocion aplicada: Dropdown de cupones se deshabilita en TODOS los demas items
  - Mensaje visual "Cupon bloqueado (1 por ticket)" en items sin promocion cuando el limite esta activo
  - Banner amarillo "Promocion aplicada - Limite: 1 por ticket" en la cabecera del carrito
  - Boton "Quitar" en el item con promocion para liberarla y permitir reasignacion
  - Toast de Sonner con warning si se intenta aplicar una segunda promocion
- **Checkout**: Subtotal, IVA 16%, Total, metodo de pago (Dropdown), leyenda opcional
- **Validacion dual**: Frontend bloquea visualmente + Backend valida con StoreOrderRequest

## 7. Ultima Accion Ejecutada
- Implementacion completa del modulo de Promociones con RBAC, validacion de limite 1 promo/ticket, y vista POS con carrito reactivo.

## 8. Proximo Paso Inmediato
- Implementar el modulo de Usuarios, Roles y Permisos (RBAC) con gestion completa de usuarios.
