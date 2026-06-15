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
| Usuarios, Roles y Permisos (RBAC)| [🟢 Completado] | [🟢 Completado] | CRUD con Kill-Switch, detail tabs (seguridad/sesiones/cajas), reset link |
| Caja Chica, Retiros e Integridad| [🟢 Completado] | [🟢 Completado] | SHA256 inmutable, eventos, notificaciones, audit ticket |
| Ventas, Ticket Config & Historico | [🟢 Completado] | [🟢 Completado] | Append-only versioning, OrderController, ticket preview 80mm, @media print |
| Finanzas Avanzadas (70/30) & Stock | [🟢 Completado] | [🟢 Completado] | Recharts dashboard, 70/30 split, stock movements con merma validada |
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

## 7. Detalle del Modulo Completado: Caja Chica, Retiros e Integridad

### Backend - API Endpoints (5 rutas)
| Metodo | Ruta | Middleware | Descripcion |
| :--- | :--- | :--- | :--- |
| GET | /api/petty-cash | auth, user.active | Lista transacciones con filtros (reason, date_from, date_to, user_id) |
| POST | /api/petty-cash | auth, user.active | Registrar retiro con snapshot inmutable y sello SHA256 |
| GET | /api/petty-cash/summary | auth, user.active | Resumen: totales hoy, acumulado, desglose por motivo |
| GET | /api/petty-cash/{id} | auth, user.active | Detalle de transaccion con verificacion de integridad |
| GET | /api/petty-cash/{id}/verify | auth, user.active | Verificacion explicita del sello criptografico |

### Modelo: PettyCashTransaction (Inmutable)
- Bloquea actualizaciones via `booted()::updating` -> lanza `RuntimeException`
- Campo `immutable_snapshot` (JSONB) almacena: amount, reason, description, operator_name, operator_email, operator_id, balance_before, balance_after, timestamp, security_seal
- Metodo `verifyIntegrity()` recalcula SHA256 y compara contra el sello almacenado
- Sello criptografico: `hash_hmac('sha256', json_encode(snapshot_sin_sello), APP_KEY)`

### FormRequest: StorePettyCashRequest
- `amount` -> required, numeric, min:0.01, max:9999999999.99
- `reason` -> required, in:provider_payment,supplies_purchase,change_delivery,emergency
- `description` -> required, string, max:500

### Controlador: PettyCashController
- `store()` ejecuta en `DB::transaction`: construye snapshot JSONB con datos del operador y saldos, genera sello SHA256 con APP_KEY, crea registro, dispara evento
- `show()` retorna transaccion con metadata `integrity_valid` (resultado de verifyIntegrity)
- `summary()` retorna totales del dia, acumulado general, y desglose por motivo con conteos
- `verify()` endpoint dedicado para verificacion de integridad bajo demanda
- Limite configurable via `GlobalSetting::where('key', 'petty_cash_daily_limit')` con codigo de error `ERR_FIN_PETTY_CASH_LIMIT_EXCEEDED`

### Eventos y Notificaciones
- **PettyCashTransactionRegistered** (ShouldBroadcast) - Canal: `petty-cash-alerts`, evento: `transaction.registered`
- **NotifyPettyCashWithdrawal** (Listener) - Busca usuarios admin con preferencia `petty_cash_withdrawal` activa, envia notificacion
- **PettyCashWithdrawalNotification** (ShouldQueue) - Via `database` + condicionalmente `mail`, incluye datos del retiro en formato toMail/toArray
- Registro en `AppServiceProvider`: `Event::listen(PettyCashTransactionRegistered::class, NotifyPettyCashWithdrawal::class)`

### Frontend - Paginas

#### PettyCashPage (`/petty-cash`)
- **Tarjetas de resumen**: Retiros hoy (count + monto), Total acumulado, Desglose por motivo con conteos
- **DataTable**: Fecha, Operador, Monto (rojo con signo negativo), Motivo (Tag coloreado), Descripcion, Sello (primeros 12 chars), Accion auditar
- Busqueda global por operador o descripcion
- Paginacion de 15 registros, ordenado por fecha descendente

#### WithdrawModal (Componente)
- Dialog PrimeReact con backdrop-blur y sombra
- Campos: Monto (InputNumber currency MXN), Motivo (Dropdown con 4 opciones), Descripcion (InputText)
- Banner de advertencia: "Se generara un sello SHA256 inmutable y se notificara a los administradores"
- Manejo de error especifico para `ERR_FIN_PETTY_CASH_LIMIT_EXCEEDED`
- Reset de formulario al abrir, bloqueo de cierre durante guardado

#### AuditTicket (Componente)
- Estilo de ticket termico con fuente monoespaciada y bordes punteados
- Encabezado: "CRONOS POS - COMPROBANTE DE CAJA CHICA"
- Datos del snapshot: Folio (8 chars UUID), Fecha, Operador, Email, Motivo, Descripcion
- Financiero: Saldo anterior, RETIRO (rojo), Saldo posterior
- Seccion criptografica: Sello SHA256 truncado (16...16 chars), Tag de integridad (VERIFICADA/COMPROMETIDA/SIN VERIFICAR)
- Llama a `/petty-cash/{id}` para obtener `integrity_valid` del backend

### Archivos Creados/Modificados en esta Fase
**Backend (nuevos):**
- `app/Events/PettyCashTransactionRegistered.php`
- `app/Listeners/NotifyPettyCashWithdrawal.php`
- `app/Notifications/PettyCashWithdrawalNotification.php`
- `app/Http/Controllers/Finance/PettyCashController.php`
- `app/Http/Requests/PettyCash/StorePettyCashRequest.php`

**Backend (modificados):**
- `app/Models/PettyCashTransaction.php` - Agregado booted::updating, verifyIntegrity()
- `app/Providers/AppServiceProvider.php` - Registro de evento/listener
- `routes/api.php` - 5 rutas de caja chica

**Frontend (nuevos):**
- `src/pages/finance/PettyCashPage.jsx`
- `src/components/finance/WithdrawModal.jsx`
- `src/components/finance/AuditTicket.jsx`

**Frontend (modificados):**
- `src/App.jsx` - Ruta /petty-cash con ProtectedRoute
- `src/components/layout/AppLayout.jsx` - NavLink "Caja Chica" en navbar

## 8. Detalle del Modulo Completado: Ventas, Ticket Config & Historico

### Backend - API Endpoints (7 rutas)
| Metodo | Ruta | Middleware | Descripcion |
| :--- | :--- | :--- | :--- |
| GET | /api/ticket-configs | auth, user.active | Lista todas las versiones de ticket (ordenadas desc) |
| GET | /api/ticket-configs/active | auth, user.active | Retorna la configuracion de ticket activa |
| GET | /api/ticket-configs/{id} | auth, user.active | Detalle de una version con updatedByUser |
| POST | /api/ticket-configs | auth, user.active, **role:admin,manager** | Crea nueva version (append-only: desactiva previa, incrementa version) |
| GET | /api/orders | auth, user.active | Lista ordenes con filtros (date_from, date_to, payment_method), paginado |
| POST | /api/orders | auth, user.active | Procesar venta: calcula precios, descuentos, IVA, asocia ticket_config activo |
| GET | /api/orders/{id} | auth, user.active | Detalle de orden con items, productos, promociones, ticket config |

### Controlador: TicketConfigController (Append-Only Versioning)
- `store()` ejecuta en `DB::transaction`: desactiva version previa (`is_active = false`), calcula `max(version) + 1`, inserta nueva version como activa
- Cada version es inmutable una vez creada — no se permite editar ni eliminar
- Historico completo preservado: ordenes antiguas referencian la version de ticket vigente al momento de la venta

### Controlador: OrderController
- `store()` ejecuta en `DB::transaction`:
  1. Obtiene ticket_config activo (retorna ERR_TICKET_NO_ACTIVE_CONFIG si no existe)
  2. Auto-abre caja registradora si el usuario no tiene una abierta (`CashRegister::create`)
  3. Para cada item: obtiene producto, calcula descuento segun tipo de promocion (percentage/fixed_amount/freebie_100)
  4. Registra `base_price_at_sale`, `discount_amount_at_sale`, `final_price_at_sale`, `tax_amount_at_sale` en order_items
  5. Decrementa `current_stock` del producto
  6. Calcula subtotal, IVA (16%), total
  7. Incrementa `expected_closing_balance` en la caja registradora
  8. Retorna orden completa con items, productos y ticket config
- `index()` con filtros: date_from, date_to, payment_method + paginacion
- `show()` carga orden con todas las relaciones

### FormRequest: StoreOrderRequest (Actualizado)
- Removido `cash_register_id` (ahora auto-gestionado por el controlador)
- Mantiene validacion de limite 1 promocion por ticket con `ERR_POS_PROMOTION_LIMIT_EXCEEDED`

### Preservacion Historica de Precios
- `order_items.base_price_at_sale` — Precio base del producto al momento de la venta
- `order_items.discount_amount_at_sale` — Descuento calculado por promocion
- `order_items.final_price_at_sale` — Precio final (base * qty - descuento)
- `order_items.tax_amount_at_sale` — IVA calculado (16% del precio final)
- `orders.ticket_config_id` — FK inmutable a la version de ticket vigente al momento de la venta

### Frontend - Componentes

#### TicketPreview (Componente reutilizable)
- Simula dimensiones fisicas de papel termico de 80mm (302px)
- Fuente monoespaciada con leading relajado
- Secciones: Encabezado (razon social, RFC, direccion, telefono, mensaje cabecera), Info de orden (folio, fecha, pago), Items (nombre, qty x precio, descuentos, promociones), Totales (subtotal, IVA, total), Leyenda personalizada, Pie (mensaje pie, version)
- Acepta `forwardRef` para integracion con impresion
- Renderiza tanto datos de preview (cart) como datos de orden completada (API response)

#### CheckoutModal (Componente)
- Dialog PrimeReact con layout de 2 columnas: controles a la izquierda, ticket preview a la derecha
- **Controles**: Metodo de pago (Dropdown), Leyenda personalizada (textarea con contador 0/500), Totales, Botones cancelar/confirmar
- **Inyeccion "Al Vuelo"**: El textarea modifica `customLegend` en tiempo real, que se renderiza instantaneamente en el TicketPreview
- **Post-venta**: Muestra la orden completada con botones Cerrar e Imprimir Ticket
- Carga automaticamente la configuracion de ticket activa al abrir
- Maneja errores: ERR_POS_PROMOTION_LIMIT_EXCEEDED, ERR_TICKET_NO_ACTIVE_CONFIG

#### TicketConfigPage (`/ticket-config`)
- DataTable con historial de versiones: numero de version (con Tag "ACTIVA"), razon social, RFC, telefono, fecha
- Boton "Nueva Version": abre formulario pre-poblado con datos de la version activa actual
- Formulario con preview en tiempo real del ticket a la derecha (datos de ejemplo)
- Dialogo para ver ticket de cualquier version historica
- Acceso restringido a admin/manager via middleware backend

### Estilos CSS para Impresion Termica
- `@media print` en index.css configura:
  - Oculta navbar, modales, controles (`print:hidden`)
  - Ticket a 80mm con `@page { size: 80mm auto; margin: 0 }`
  - Elimina bordes, sombras y padding decorativo del ticket
  - `page-break-inside: avoid` para evitar cortes

### Archivos Creados/Modificados en esta Fase
**Backend (nuevos):**
- `app/Http/Controllers/Sales/TicketConfigController.php`
- `app/Http/Controllers/Sales/OrderController.php`
- `app/Http/Requests/TicketConfig/StoreTicketConfigRequest.php`

**Backend (modificados):**
- `app/Http/Requests/Order/StoreOrderRequest.php` — Removido cash_register_id, auto-gestionado
- `routes/api.php` — 7 rutas nuevas (4 ticket-configs, 3 orders)

**Frontend (nuevos):**
- `src/components/pos/TicketPreview.jsx`
- `src/components/pos/CheckoutModal.jsx`
- `src/pages/settings/TicketConfigPage.jsx`

**Frontend (modificados):**
- `src/pages/pos/POSPage.jsx` — Reemplazado checkout inline con CheckoutModal, limpieza de imports
- `src/App.jsx` — Ruta /ticket-config con ProtectedRoute
- `src/components/layout/AppLayout.jsx` — NavLink "Tickets" en navbar
- `src/index.css` — Estilos @media print para impresion termica 80mm

## 9. Detalle del Modulo Completado: Finanzas Avanzadas (70/30) & Logistica de Stock

### Backend - API Endpoints (6 rutas nuevas)
| Metodo | Ruta | Middleware | Descripcion |
| :--- | :--- | :--- | :--- |
| GET | /api/analytics/sales-by-payment | auth, user.active, **role:admin,manager** | Ventas diarias desglosadas por metodo de pago (ingreso neto sin IVA) |
| GET | /api/analytics/financial-summary | auth, user.active, **role:admin,manager** | Resumen 70/30: ingreso neto, fondo inversion, utilidad, deducciones |
| GET | /api/analytics/daily-trend | auth, user.active, **role:admin,manager** | Tendencia diaria de ingresos (neto/bruto/ordenes) |
| GET | /api/stock-movements | auth, user.active | Lista movimientos de stock con filtros (product_id, type, reason, rango fechas) |
| POST | /api/stock-movements | auth, user.active | Registrar movimiento: entrada, merma (motivo obligatorio), ajuste |
| GET | /api/stock-movements/summary | auth, user.active | Resumen por tipo y desglose de merma por motivo |

### Controlador: AnalyticsController (Exclusivo Admin/Manager)
- `salesByPaymentMethod()` — Agrega ventas por DATE(created_at) y payment_method usando PostgreSQL. Retorna ingreso neto (subtotal, sin IVA) para cada dia/metodo. Filtros: date_from, date_to.
- `financialSummary()` — Calcula el ciclo financiero completo:
  1. Lee `tax_rate` de global_settings (default 0.16)
  2. Lee `investment_split` de global_settings (default 70/30)
  3. Ingreso Bruto = SUM(orders.total), Ingreso Neto = SUM(orders.subtotal)
  4. Fondo Inversion = Neto * 70%
  5. Utilidad Real = Neto * 30%
  6. Deducciones del 70%: SUM(petty_cash_transactions.amount) + SUM(stock_movements[purchase_input].cost * qty)
  7. Remanente = Fondo 70% - Deducciones totales
  8. Merma informativa: SUM(stock_movements[merma_output].cost * qty)
- `dailyTrend()` — Serie temporal de ingresos diarios (neto/bruto/ordenes)

### Controlador: StockMovementController
- `store()` ejecuta en `DB::transaction` con `lockForUpdate()`:
  - `purchase_input`: incrementa current_stock
  - `merma_output`: valida stock suficiente, decrementa current_stock, **requiere reason obligatorio** del catalogo (expired, damaged_spilled, internal_consumption, theft_loss)
  - `adjustment`: establece current_stock al valor indicado
  - Registra cost_price_at_movement para trazabilidad contable
- `index()` con filtros: product_id, type, reason, date_from, date_to + paginacion
- `summary()` agrega por tipo (count, total_quantity, total_cost) y desglose de merma por motivo

### FormRequest: StoreStockMovementRequest
- `product_id` -> required, uuid, exists:products
- `type` -> required, in:purchase_input,merma_output,adjustment (excluye sale_output — reservado para OrderController)
- `quantity` -> required, integer, min:1
- `cost_price_at_movement` -> required, numeric, min:0
- `reason` -> **required** si type=merma_output, nullable en otros casos. Valores: expired, damaged_spilled, internal_consumption, theft_loss

### GlobalSettings Seedados
- `tax_rate` -> { rate: 0.16, label: "IVA 16%" }
- `investment_split` -> { investment_pct: 70, profit_pct: 30 }

### Frontend - Paginas

#### FinanceDashboardPage (`/finance`) — Panel Financiero Avanzado
- **Selector de periodo**: Calendar PrimeReact con seleccion de rango
- **KPI Cards (4)**: Ingreso Bruto, Ingreso Neto (sin IVA), Fondo Inversion 70% (con Tag REMANENTE/DEFICIT), Utilidad Real 30%
- **Vista 1 — Stacked Bar Chart (Recharts)**: Ventas diarias por metodo de pago (Efectivo verde, Tarjeta indigo, Transferencia amber). Tooltip personalizado con formato MXN. Eje Y con formato moneda.
- **Vista 2 — Barra Horizontal Comparativa (Recharts)**: Ingreso Neto vs Fondo Inversion vs Utilidad. Cada barra con color diferente via Cell. Debajo: panel de deducciones desglosado (caja chica, compras stock, merma) con calculo de remanente.

#### StockMovementsPage (`/stock-movements`) — Logistica de Almacen
- **Tarjetas de resumen**: Entradas (+uds, $ invertido), Mermas (-uds, $ perdido), Desglose de merma por motivo
- **DataTable**: Fecha, Producto, Tipo (Tag coloreado), Cantidad (+/-), Costo Unitario, Costo Total, Motivo, Usuario
- **Modal de Registro**: Producto (Dropdown con filtro), Tipo de movimiento, Motivo de merma (condicional, aparece solo para merma_output), Cantidad, Costo unitario (auto-poblado desde cost_price del producto)
- Banner de advertencia para mermas

### Archivos Creados/Modificados en esta Fase
**Backend (nuevos):**
- `app/Http/Controllers/Finance/AnalyticsController.php`
- `app/Http/Controllers/Logistics/StockMovementController.php`
- `app/Http/Requests/StockMovement/StoreStockMovementRequest.php`

**Backend (modificados):**
- `routes/api.php` — 6 rutas nuevas (3 analytics, 3 stock-movements)
- `database/seeders/DatabaseSeeder.php` — Seeds tax_rate e investment_split en global_settings

**Frontend (nuevos):**
- `src/pages/finance/FinanceDashboardPage.jsx`
- `src/pages/logistics/StockMovementsPage.jsx`

**Frontend (modificados):**
- `src/App.jsx` — Rutas /finance y /stock-movements
- `src/components/layout/AppLayout.jsx` — NavLinks "Finanzas" y "Almacen"

**Dependencias:**
- `recharts` agregado a package.json

## 10. Detalle del Modulo Completado: Usuarios, Roles y Permisos (RBAC)

### Backend - API Endpoints (8 rutas nuevas)
| Metodo | Ruta | Middleware | Descripcion |
| :--- | :--- | :--- | :--- |
| GET | /api/admin/users | auth, user.active, **role:admin,manager** | Lista usuarios con filtros: status, role, has_active_session, search |
| POST | /api/admin/users | auth, user.active, **role:admin,manager** | Crear usuario con asignacion de rol |
| GET | /api/admin/users/roles | auth, user.active, **role:admin,manager** | Lista roles disponibles |
| GET | /api/admin/users/{id} | auth, user.active, **role:admin,manager** | Detalle con seguridad, ultimas 3 sesiones, historial de cajas |
| POST | /api/admin/users/{id}/toggle-status | auth, user.active, **role:admin,manager** | Suspender/Activar (Kill-Switch: revoca todos los tokens) |
| DELETE | /api/admin/users/{id} | auth, user.active, **role:admin,manager** | Baja logica con motivo obligatorio (AdvancedSoftDeletes) |
| POST | /api/admin/users/{id}/send-password-reset | auth, user.active, **role:admin,manager** | Genera URL firmada de 4 horas y envia Mailable |
| POST | /api/admin/users/{id}/sessions/{sid}/revoke | auth, user.active, **role:admin,manager** | Fuerza cierre de sesion individual + revoca tokens |

### Controlador: UserController (Admin)
- `index()` — Filtros avanzados: status (active/suspended), role (admin/manager/vendor), has_active_session (users con sessionsLog sin logout_at), search (name/email con ilike). Incluye `active_tokens_count` via withCount.
- `show()` — API Resource optimizado que retorna:
  - User con roles
  - Security: two_factor_enabled, two_factor_confirmed_at, password_restored_at, active_tokens count
  - Sessions: ultimas 3 de user_sessions_log (login_at, logout_at, ip_address, user_agent)
  - Cash Registers: ultimos 10 turnos (opened_at, closed_at, opening/expected/actual balance)
- `store()` — Crea usuario en DB::transaction con asignacion de rol via pivot model_has_roles
- `toggleStatus()` — Kill-Switch: cambia status via raw SQL (enum nativo PostgreSQL), revoca tokens si suspendido
- `destroy()` — Baja logica con advancedDelete() que registra deleted_by y deletion_reason
- `sendPasswordReset()` — URL::temporarySignedRoute de 4 horas + PasswordResetLinkMail (ShouldQueue)
- `revokeSession()` — Cierra sesion individual (logout_at = now) y revoca todos los tokens del usuario

### FormRequest: StoreUserRequest
- name: required, string, max:150
- email: required, email, max:150, unique:users
- password: required, string, min:8, max:255
- role: required, string, exists:roles,name

### Mailable: PasswordResetLinkMail (ShouldQueue)
- Email HTML inline con diseno corporativo Cronos POS
- Boton de restauracion con URL firmada
- Expiracion de 4 horas indicada en el cuerpo del email

### Frontend - UsersPage (`/admin/usuarios`)

#### DataTable con Filtros Avanzados
- **Busqueda global**: InputText por nombre o email
- **Filtro por estatus**: Dropdown (Todos/Activo/Suspendido) — consulta backend con param status
- **Filtro por rol**: Dropdown (Todos/Admin/Manager/Vendor) — consulta backend con param role
- **Filtro por sesion activa**: Dropdown (Todos/Con sesion/Sin sesion) — consulta backend con param has_active_session
- Columnas: Usuario (nombre clickeable + email), Rol (Tag coloreado), Estatus (Tag), Sesion (indicador de conexion con punto animado), Creado, Acciones

#### Acciones por Fila
- **Suspender/Activar**: Boton toggle que invoca Kill-Switch (revoca tokens inmediatamente)
- **Reset**: Envia enlace de restauracion de contraseña (URL firmada 4 horas) por email
- **Eliminar**: Abre modal con textarea de motivo obligatorio, ejecuta advancedDelete

#### Detalle de Usuario (Dialog con TabView)
- **Tab 1 (Seguridad)**: Estado 2FA (HABILITADO/DESHABILITADO con fecha), ultima restauracion de contraseña, tokens activos
- **Tab 2 (Sesiones)**: Ultimas 3 conexiones de user_sessions_log con IP, navegador (parseado de user_agent), fecha login/logout, indicador de sesion activa, boton "Forzar Cierre" para sesiones activas
- **Tab 3 (Auditoria de Cajas)**: Historial de cash_registers con apertura/cierre, balances (apertura, esperado, real), calculo de diferencia con color (verde positivo, rojo negativo), Tag ABIERTA/CERRADA

#### Crear Usuario (Dialog)
- Campos: Nombre, Email, Contraseña, Rol (Dropdown)
- Validacion frontend + backend con errores específicos de Sonner

### Archivos Creados/Modificados en esta Fase
**Backend (nuevos):**
- `app/Http/Controllers/Admin/UserController.php`
- `app/Http/Requests/User/StoreUserRequest.php`
- `app/Mail/PasswordResetLinkMail.php`

**Backend (modificados):**
- `routes/api.php` — 8 rutas nuevas bajo /admin/users con role:admin,manager
- `routes/web.php` — Ruta nombrada password.reset para URL firmada

**Frontend (nuevos):**
- `src/pages/admin/UsersPage.jsx`

**Frontend (modificados):**
- `src/App.jsx` — Ruta /admin/usuarios
- `src/components/layout/AppLayout.jsx` — NavLink "Usuarios"

## 11. Reporte de Cierre General de la Arquitectura en Marcha

### Resumen de Progreso Global
| # | Modulo | Backend | Frontend | Estado |
| :--- | :--- | :--- | :--- | :--- |
| 1 | Infraestructura Docker | PHP 8.3 Alpine + Nginx + Postgres | Vite + Tailwind v4 | 🟢 |
| 2 | Migraciones & Modelos | 18 migraciones, 15 modelos, ENUMs nativos PostgreSQL | N/A | 🟢 |
| 3 | Autenticacion & 2FA | Sanctum + Google2FA TOTP + Kill-Switch | Login + TwoFactorModal | 🟢 |
| 4 | Catalogo & Variaciones | CRUD Categories/Products, AdvancedSoftDeletes | DataTable filtros avanzados, ProductFormPage | 🟢 |
| 5 | Promociones | CRUD con RBAC, StoreOrderRequest 1-promo limit | PromotionsPage + POSPage carrito reactivo | 🟢 |
| 6 | Caja Chica & Integridad | SHA256 seal, Events/Listeners/Notifications | WithdrawModal + AuditTicket termico | 🟢 |
| 7 | Ventas & Ticket Config | OrderController, Append-Only versioning | CheckoutModal + TicketPreview 80mm + @media print | 🟢 |
| 8 | Finanzas 70/30 & Stock | AnalyticsController, StockMovementController | Recharts dashboard, StockMovementsPage | 🟢 |
| 9 | Usuarios & Roles (RBAC) | UserController, Kill-Switch, URL firmada | UsersPage con TabView detail | 🟢 |
| 10 | Notificaciones Reverb | Pendiente | Pendiente | ⚪ |
| 11 | Papelera Global | Pendiente | Pendiente | ⚪ |

### Estadisticas de la Arquitectura
- **Rutas API totales**: ~48 endpoints RESTful
- **Controladores**: 11 (Auth, TwoFactor, Category, Product, Promotion, PettyCash, TicketConfig, Order, Analytics, StockMovement, UserAdmin)
- **FormRequests**: 12 clases de validacion estricta
- **Modelos Eloquent**: 15 con relaciones completas
- **Middleware custom**: 2 (EnsureUserIsActive, EnsureUserHasRole)
- **Mailables**: 1 (PasswordResetLinkMail, ShouldQueue)
- **Eventos/Listeners**: 1 evento broadcast + 1 listener + 1 notificacion queued
- **Paginas React**: 12 (Login, Dashboard, Categories, Products, ProductForm, Promotions, POS, PettyCash, TicketConfig, Finance, StockMovements, UsersAdmin)
- **Componentes React**: 8 (AppLayout, DeleteDialog, TwoFactorModal, WithdrawModal, AuditTicket, TicketPreview, CheckoutModal)
- **Librerias frontend**: React, Tailwind CSS v4, PrimeReact, Sonner, Recharts, Axios

### Patrones Arquitectonicos Implementados
1. **Inmutabilidad financiera**: order_items con _at_sale fields, petty_cash con JSONB snapshot + SHA256
2. **Append-Only**: ticket_configs con versionado incremental
3. **RBAC con middleware**: role:admin,manager para operaciones sensibles
4. **Kill-Switch**: Suspension instantanea de usuarios con revocacion de tokens (usado en UserController y EnsureUserIsActive)
5. **Transacciones atomicas**: DB::transaction con lockForUpdate en stock, ordenes, caja chica
6. **Segmentacion contable 70/30**: Configurable via global_settings, deducciones automaticas
7. **Validacion dual**: Frontend reactivo + Backend FormRequest (ej: 1 promo por ticket)
8. **URLs firmadas temporales**: URL::temporarySignedRoute para enlaces de restauracion de 4 horas

### Proximo Paso Inmediato
- Implementar Notificaciones Reverb para push en tiempo real, o Papelera Global con recuperacion de registros soft-deleted.
