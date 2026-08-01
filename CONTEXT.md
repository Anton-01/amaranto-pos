# Estado Actual del Sistema POS - Cronos

## 1. Arquitectura General
- Backend: Laravel 13 (API-First), PHP 8.4 Alpine
- Frontend: React 18/19, Tailwind CSS v4, PrimeReact, Sonner
- Base de Datos: Managed PostgreSQL (DigitalOcean)
- Cache / Colas: Redis 7 Alpine (cache global + queue worker)
- WebSockets: Laravel Reverb (puerto 8080, tiempo real)
- Estado del Proyecto: [🟢 FASE 8: SISTEMA ENTERPRISE DE CIERRES AUTOMÁTICOS, NOTIFICACIONES TAGGEADAS, ANALÍTICA FINANCIERA Y AUDITORÍA INMUTABLE COMPLETADO] — Scheduler de cierre de caja 21:00 bajo usuario de sistema, notificaciones estructuradas por tags JSON con modal de plantillas dinámicas, modal de analítica financiera mensual con export CSV, y auditoría forense de cierres admin-only (ver sección 40).
- Fase previa: [🟢 FASE 7: SISTEMA DE GESTIÓN DE MESAS (DINE-IN) IMPLEMENTADO] — Comedor operativo: plano de mesas, cuentas vivas transaccionales, comandas incrementales y cobro con liberación automática (ver sección 39).
- Estado de Infraestructura: [🟢 FASE 7: DEPLOY ÁGIL Y DOCKER OPTIMIZADO] — Docker Compose multi-contenedor operativo (4 servicios: backend, frontend, postgres, redis).
- Despliegue Produccion: [🟢 FASE 7: DEPLOY ÁGIL Y DOCKER OPTIMIZADO] — DigitalOcean Droplet + Managed PostgreSQL, imagenes base pre-compiladas (serversideup/php:8.4-fpm-alpine + nginx:alpine), frontend Vite pre-construido en local (frontend/dist versionado), Nginx proxy inverso HTTPS/WSS, Certbot SSL, deploy.sh automatizado. Tiempo estimado de build en el Droplet: < 2 minutos (antes: ~82 min).

## 2. Matriz de Modulos y Progreso
| Modulo | Estado Backend | Estado Frontend | Observaciones |
| :--- | :--- | :--- | :--- |
| Infraestructura & Docker | [🟢 Completado y Operativo] | [🟢 Completado y Operativo] | Docker Compose multi-contenedor, Alpine, Hot-Reload, Reverb WS :8080 |
| Despliegue Produccion (DigitalOcean) | [🟢 FASE 7: DEPLOY ÁGIL Y DOCKER OPTIMIZADO] | [🟢 FASE 7: DEPLOY ÁGIL Y DOCKER OPTIMIZADO] | Imagenes pre-compiladas (serversideup/php + nginx:alpine), frontend pre-construido en local, build en Droplet < 2 min, Nginx HTTPS/WSS proxy, Managed PostgreSQL SSL, Certbot, deploy.sh, cron scheduler |
| Migraciones & Modelos Base (BD) | [🟢 Completado] | N/A | 23 migraciones, 16 modelos, Trait AdvancedSoftDeletes, Seeder base |
| Autenticacion & 2FA (Sanctum) | [🟢 Completado] | [🟢 Completado] | Login, Logout, 2FA TOTP, Kill-Switch, Session Log |
| Catalogo, Categorias y Variaciones| [🟢 Completado] | [🟢 Completado] | CRUD completo, DataTable filtros avanzados, variaciones en serie |
| Promociones e Historicos | [🟢 Completado] | [🟢 Completado] | CRUD con RBAC, limite 1 promo/ticket, POS cart con validacion cruzada |
| Usuarios, Roles y Permisos (RBAC)| [🟢 Completado] | [🟢 Completado] | CRUD con Kill-Switch, detail tabs (seguridad/sesiones/cajas), reset link, doble confirmacion, self-guard, CRUD roles |
| Caja Chica, Retiros e Integridad| [🟢 Completado] | [🟢 Completado] | SHA256 inmutable, eventos, notificaciones, audit ticket |
| Ventas, Ticket Config & Historico | [🟢 Completado] | [🟢 Completado] | Append-only versioning, OrderController, ticket preview 58mm (migrado de 80mm), @media print |
| Finanzas Avanzadas (70/30) & Stock | [🟢 Completado] | [🟢 Completado] | Recharts dashboard, 70/30 split, stock movements con merma validada |
| Notificaciones Reverb (Push/Mail)| [🟢 Completado] | [🟢 Completado] | Preferencias matriciales mail/database por rol |
| Papelera Global (Soft Deletes)| [🟢 Completado] | [🟢 Completado] | Auditoria forense, restore/purge con RBAC admin-only |
| Configuracion del Sistema | [🟢 Completado] | [🟢 Completado] | Settings globales (timezone, moneda, fiscal, IVA, split) |
| Perfil de Usuario | [🟢 Completado] | [🟢 Completado] | Editar perfil, cambiar contraseña, auditoria sesiones |
| Dashboard Avanzado | [🟢 Completado] | [🟢 Completado] | KPIs, tendencia horaria (LineChart), top productos (PieChart) |
| Validaciones Anti-Duplicados | [🟢 Completado] | [🟢 Completado] | Unique constraints + field-level error display |
| Upload Imagen Producto | [🟢 Completado] | [🟢 Completado] | Storage local, FileUpload PrimeReact, preview + delete |
| Ventas Diarias (Header Modal) | [🟢 Completado] | [🟢 Completado] | Resumen diario con desglose por metodo de pago |
| Botones Icono DataTables | N/A | [🟢 Completado] | Iconos PrimeReact con tooltips en todas las tablas |
| Historial de Ventas | [🟢 Completado] | [🟢 Completado] | DataTable con filtros (quick+avanzados), detalle modal, cancelacion con admin password, reimpresion, exportacion CSV |
| Metodos de Pago Dinamicos | [🟢 Completado] | [🟢 Completado] | CRUD payment_methods, FK restrictOnDelete, seeder base (cash/card/transfer), despliegue dinamico en POS/checkout/historial, **catalogo cacheado en Redis (TTL 60 min + invalidacion automatica por eventos del modelo)** — ver seccion 38 |
| Transformacion SKU Mayusculas | [🟢 Completado] | [🟢 Completado] | Mutadores en modelo Product + onChange uppercase en frontend |
| Cierre de Caja (Blind Closing) | [🟢 Completado] | [🟢 Completado] | Inmutabilidad DB (booted events), arqueo ciego, desglose por metodo pago JSONB, export PDF/Excel/Email batch, historial forense /admin/cierres |
| Track Stock (Paquetes/Combos) | [🟢 Completado] | [🟢 Completado] | Columna track_stock en products, InputSwitch en formulario, pipeline ventas omite decremento si false, cancel reversa condicionada |
| Apertura de Caja (Fondo de Caja) | [🟢 Completado] | [🟢 Completado] | Apertura obligatoria con fondo inicial, bloqueo POS reactivo, auditoria forense (IP/UA/token), validacion en ordenes y caja chica, cierre alineado con formula (Fondo + Ventas - Retiros) |
| Bugs Track Stock (Inventario Flexible) | [🟢 Completado] | [🟢 Completado] | DataTable muestra Tag ILIMITADO, POS no bloquea productos sin control stock, formulario edicion sincroniza boolean correctamente |
| Resiliencia Local-First (Offline) | [🟢 Completado] | [🟢 Completado] | Hook useOnlineStatus, buffer LocalStorage, background sync, indicador amber en TopBar |
| Correos Corporativos Centralizados | [🟢 Completado] | N/A | Master layout Blade, 4 Mailables ShouldQueue (Redis), preview routes local-only |
| Visualizador In-App Plantillas Email | [🟢 Completado] | [🟢 Completado] | MailPreviewController render HTML, iframe srcDoc aislado, viewport Desktop/Mobile |
| Motor Impresión QZ Tray + Firma Digital | [🟢 COMPLETADO Y OPERATIVO] | [🟢 COMPLETADO Y OPERATIVO] | Migrado a Cronos POS Agent (ver secciones 34, 35 y 37). SafeDummyPrintConnector (PHP 8 compatible), impresión post-venta CONFIRMADA POR EL USUARIO via PrintConfirmationModal (sin auto-impresión), agente HTTP local 127.0.0.1:9100 |
| Descuentos y Cupones en Checkout | [🟢 Completado] | [🟢 Completado] | Descuento directo (fijo/porcentual) + cupón por autocomplete, audit trail en orders, propagación completa (ticket, historial, finanzas, Excel, ESC/POS) |
| Estatus Inline (Toggle Switch) | [🟢 COMPLETADO Y OPERATIVO] | [🟢 COMPLETADO Y OPERATIVO] | InputSwitch en DataTables (Productos, Categorías, Promociones, Usuarios), PATCH endpoints, actualización optimista con rollback, Emerald/Slate CSS, etiquetas dinámicas (Activo/Inactivo) bajo cada switch, Toast éxito/error en cada mutación |
| Selector de Impresoras Locales (QZ Tray) | [🟢 COMPLETADO Y OPERATIVO] | [🟢 COMPLETADO Y OPERATIVO] | Migrado a Cronos POS Agent (ver secciones 34 y 37). PrinterSetupPanel con botón "Detectar Agente Local" (GET /api/health), listado automático de impresoras tras detección, campo de Token de Seguridad, consulta MANUAL de cola (sin polling), persistencia localStorage (cronos_active_printer / cronos_agent_token) |
| Confirmación de Impresión Post-Venta | N/A | [🟢 COMPLETADO Y OPERATIVO] | PrintConfirmationModal con resumen visual del ticket (folio, fecha, productos, totales, método de pago, agradecimiento), botones "Imprimir Ticket" / "Omitir — No imprimir" (cero peticiones HTTP al agente al omitir) |
| Cierre Automático de Caja (Scheduler 21:00) | [🟢 FASE 8: COMPLETADO] | [🟢 FASE 8: COMPLETADO] | Comando `cronos:auto-close-registers` diario 21:00 (America/Mexico_City), usuario System Automated Process, ledger insert-only, notificación a admins — ver sección 40 |
| Notificaciones Estructuradas por Tags JSON | [🟢 FASE 8: COMPLETADO] | [🟢 FASE 8: COMPLETADO] | Tabla `system_notifications` (data JSONB inmutable), campana del header conectada con badge y polling 60s, modal de plantillas dinámicas por `type` — ver sección 40 |
| Analítica Financiera Mensual (Dashboard) | [🟢 FASE 8: COMPLETADO] | [🟢 FASE 8: COMPLETADO] | Endpoint agregado role:admin,manager (totales, comparativa mensual, métodos de pago, top productos, horas pico, tendencia diaria), modal con skeleton + export CSV — ver sección 40 |
| Auditoría Histórica de Cierres (Admin Only) | [🟢 FASE 8: COMPLETADO] | [🟢 FASE 8: COMPLETADO] | `/admin/cash-closings-audit` con middleware role:admin, tabla lazy paginada con filtros, radiografía forense de solo lectura, triple candado de inmutabilidad — ver sección 40 |
| Sistema de Gestión de Mesas (Dine-in) | [🟢 FASE 7: IMPLEMENTADO] | [🟢 FASE 7: IMPLEMENTADO] | Tablas `tables` y `table_sessions`, orden base en estado `open`, 4 endpoints transaccionales con `lockForUpdate` + índice único parcial, botón de mesas a la izquierda del reloj en el header del POS, catálogo en sidebar de Administración, trazabilidad mesa/mesero en histórico y ticket — ver sección 39 |
| Optimizacion Modal de Cobro (Rendimiento) | [🟢 COMPLETADO Y OPERATIVO] | [🟢 COMPLETADO Y OPERATIVO] | Catálogo de métodos de pago servido desde Redis (`Cache::remember`, TTL 60 min, invalidación automática en alta/edición/baja); input de "Dinero Recibido" con sanitización estricta en `onChange` (sin `onBlur`), cálculo del cambio y habilitación de "Confirmar Cobro" en tiempo real desde la primera tecla |

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

## 11. Detalle del Modulo Completado: Notificaciones (Push/Mail)

### Backend - API Endpoints (2 rutas nuevas)
| Metodo | Ruta | Middleware | Descripcion |
| :--- | :--- | :--- | :--- |
| GET | /api/profile/notifications | auth, user.active | Lista tipos de notificacion filtrados por rol del usuario con preferencias actuales |
| PUT | /api/profile/notifications | auth, user.active | Actualiza preferencias con upsert atomico en PK compuesto triple |

### Controlador: NotificationPreferenceController (Profile)
- `index()` — Obtiene NotificationType filtrados por interseccion de allowed_roles (JSONB) con los roles del usuario autenticado. Combina con UserNotificationPreference existentes para retornar canales {mail: bool, database: bool} por tipo.
- `update()` — Valida array de preferences (notification_type_id, channel, is_enabled). Filtra por tipos permitidos para el rol. Ejecuta DB::transaction con DB::table()->upsert() sobre PK compuesto triple (user_id, notification_type_id, channel).

### Seguridad RBAC
- Cada usuario solo ve y modifica los tipos de notificacion cuyo `allowed_roles` JSONB incluya al menos uno de sus roles
- El upsert ignora silenciosamente tipos no autorizados para el usuario

### Frontend - NotificationPreferencesPage (`/profile/notifications`)
- **Tabla matricial**: Filas = tipos de notificacion (nombre + descripcion), Columnas = canales (Correo Electronico, Alertas Push en Sistema)
- **Toggle Switches**: InputSwitch de PrimeReact por cada combinacion tipo/canal
- **Actualizacion asincrona**: Cada toggle dispara PUT individual con optimistic update. Rollback automatico si falla.
- **Feedback instantaneo**: Sonner toast de exito/error por cada cambio
- **Loading state**: ProgressSpinner micro (16px) junto al toggle durante la peticion
- **Estado vacio**: Mensaje informativo si no hay tipos disponibles para el rol del usuario

### Archivos Creados/Modificados en esta Fase
**Backend (nuevos):**
- `app/Http/Controllers/Profile/NotificationPreferenceController.php`

**Backend (modificados):**
- `routes/api.php` — 2 rutas nuevas bajo /profile/notifications

**Frontend (nuevos):**
- `src/pages/profile/NotificationPreferencesPage.jsx`

**Frontend (modificados):**
- `src/App.jsx` — Ruta /profile/notifications
- `src/components/layout/AppLayout.jsx` — NavLink "Notificaciones"

## 12. Detalle del Modulo Completado: Papelera Global (Soft Deletes)

### Backend - API Endpoints (3 rutas nuevas)
| Metodo | Ruta | Middleware | Descripcion |
| :--- | :--- | :--- | :--- |
| GET | /api/admin/trash/{type} | auth, user.active, **role:admin,manager** | Lista registros soft-deleted por tipo (products, categories, users, promotions) |
| POST | /api/admin/trash/{type}/{id}/restore | auth, user.active, **role:admin,manager** | Restaura registro al catalogo activo via advancedRestore() |
| DELETE | /api/admin/trash/{type}/{id} | auth, user.active, **role:admin,manager** | Purga permanente (forceDelete) — **exclusivo admin** con doble validacion |

### Controlador: TrashController (Admin)
- **Arquitectura unificada**: Un solo controlador maneja 4 modelos (Product, Category, User, Promotion) via constante MODELS con mapeo tipo->clase
- `index()` — Usa `onlyTrashed()` de Eloquent con eager loading de `deletedByUser` (relacion del trait AdvancedSoftDeletes). Carga relaciones contextuales: category para productos, roles para usuarios, products para promociones. Filtro de busqueda con ilike (name, sku, email). Paginado de 15 registros ordenados por deleted_at descendente.
- `restore()` — Ejecuta `advancedRestore()` del trait que limpia deleted_by y deletion_reason, luego llama restore() nativo de Laravel.
- `forceDelete()` — **Triple capa de seguridad**:
  1. Middleware `role:admin,manager` en la ruta (bloquea vendor)
  2. Validacion de rol admin dentro del controlador (bloquea manager con ERR_TRASH_ADMIN_ONLY 403)
  3. Campo `confirmation` obligatorio con valor exacto "ELIMINAR" (validacion request)
  - Ejecuta `forceDelete()` nativo de Eloquent para purga fisica de PostgreSQL

### Seguridad RBAC Granular
- **Vendor**: Bloqueado completamente via middleware `role:admin,manager`
- **Manager**: Puede ver papelera y restaurar registros. NO puede purgar (bloqueado por validacion interna de rol)
- **Admin**: Acceso completo: ver, restaurar y purgar permanentemente con doble confirmacion

### Frontend - TrashPage (`/admin/papelera`)

#### Selector de Sub-modulo
- Dropdown superior con 4 opciones: Productos Eliminados, Categorias Eliminadas, Usuarios Suspendidos/Bajas, Promociones Vencidas/Eliminadas
- Cambio de modulo resetea busqueda y paginacion

#### DataTable con Metadatos de Auditoria
- **Registro**: Nombre/identificador con datos contextuales (SKU para productos, email para usuarios)
- **Columna contextual**: Categoria (productos), Rol (usuarios con Tag coloreado), Tipo (promociones)
- **Eliminado por**: Nombre y email del usuario que ejecuto la baja (via relacion deletedByUser del trait)
- **Fecha de Baja**: deleted_at formateado localmente (es-MX)
- **Motivo**: deletion_reason truncado a 50 chars con boton "Ver mas" que abre Dialog de PrimeReact con texto completo
- Busqueda por nombre, SKU o email. Paginacion lazy server-side.

#### Acciones de Recuperacion
- **Restaurar** (Button success outlined): Disponible para Admin y Manager. Ejecuta advancedRestore() y notifica con Sonner.
- **Purgar** (Button danger outlined): Deshabilitado para Manager (tooltip "Solo administradores"). Admin abre modal de doble confirmacion.

#### Modal de Purga Permanente (Doble Validacion)
- Banner de advertencia rojo con nombre del registro
- Muestra motivo de baja original si existe
- Input de confirmacion: debe escribir exactamente "ELIMINAR" para habilitar el boton
- Boton de purga deshabilitado hasta que la confirmacion coincida
- Loading state durante la operacion

### Archivos Creados/Modificados en esta Fase
**Backend (nuevos):**
- `app/Http/Controllers/Admin/TrashController.php`

**Backend (modificados):**
- `routes/api.php` — 3 rutas nuevas bajo /admin/trash con role:admin,manager

**Frontend (nuevos):**
- `src/pages/admin/TrashPage.jsx`

**Frontend (modificados):**
- `src/App.jsx` — Ruta /admin/papelera
- `src/components/layout/AppLayout.jsx` — NavLink "Papelera"

## 13. Detalle: Infraestructura Docker Multi-Contenedor

### docker-compose.yml (Raiz del Proyecto)
4 servicios orquestados con healthchecks y dependencias:

| Servicio | Imagen Base | Puerto Expuesto | Descripcion |
| :--- | :--- | :--- | :--- |
| backend | PHP 8.4-FPM Alpine (custom) | 8000, 8080 | Laravel API + Reverb WebSockets |
| frontend | Node 22 Alpine (custom) | 3000 | Vite dev server con HMR |
| postgres | postgres:16-alpine | 5432 | Base de datos con volumen persistente |
| redis | redis:7-alpine | 6379 | Cache global + colas de notificaciones |

### Volumenes
- `postgres-data`: Persistencia de datos PostgreSQL entre reinicios
- `redis-data`: Persistencia de datos Redis
- `backend-vendor`: Volumen anonimo para vendor/ (evita conflictos host/contenedor)
- `frontend-node-modules`: Volumen anonimo para node_modules/ (evita conflictos OS)

### backend/Dockerfile.dev
- Base: `php:8.4-fpm-alpine`
- Extensiones instaladas via apk + docker-php-ext-install: `pdo_pgsql`, `pgsql`, `zip`, `gd` (con freetype+jpeg), `intl`, `bcmath`, `opcache`, `mbstring`, `pcntl`
- Extension Redis via PECL: `pecl install redis && docker-php-ext-enable redis`
- Composer copiado desde imagen oficial: `COPY --from=composer:2`
- Limpieza de dependencias de compilacion tras instalar extensiones

### backend/docker-entrypoint.sh (Automatizacion de Arranque)
Secuencia de ejecucion al levantar el contenedor:
1. `composer install` si vendor/autoload.php no existe
2. `chown -R www-data:www-data storage bootstrap/cache` + `chmod -R 775`
3. Copia `.env.example` → `.env` + `key:generate` si .env no existe
4. `migrate:fresh --seed --force` (recrea BD con datos base)
5. Limpieza de caches (config, cache, route)
6. Instala `laravel/reverb` si no esta presente
7. Inicia `queue:work` en background
8. Inicia `reverb:start --host=0.0.0.0 --port=8080` en background
9. Inicia `php artisan serve --host=0.0.0.0 --port=8000` en foreground

### frontend/Dockerfile.dev
- Base: `node:22-alpine`
- WORKDIR: `/app`
- Copia `package.json` + `package-lock.json`, ejecuta `npm install`
- CMD: `npm run dev -- --host 0.0.0.0 --port 3000`

### .dockerignore
- Backend: excluye `vendor/`, `node_modules/`, logs, cache, `.env`
- Frontend: excluye `node_modules/`, `dist/`, `.env`

### Configuracion de Red
- Red bridge `cronos-network` compartida entre todos los servicios
- Frontend proxy `/api` → `http://localhost:8000` (configurable via `VITE_API_URL`)
- Variables de entorno de Docker inyectadas desde docker-compose.yml (no requiere .env manual)

### Healthchecks
- PostgreSQL: `pg_isready -U cronos -d cronos_pos` cada 5s
- Redis: `redis-cli ping` cada 5s
- Backend depende de postgres + redis con `condition: service_healthy`

### Archivos Creados/Modificados
**Raiz (nuevos):**
- `docker-compose.yml`

**Backend (nuevos):**
- `Dockerfile.dev`
- `docker-entrypoint.sh` (con permisos de ejecucion)
- `.dockerignore`

**Backend (modificados):**
- `.env.example` — Actualizado con defaults Docker (postgres/redis como hosts, Reverb config)

**Frontend (nuevos):**
- `Dockerfile.dev`
- `.dockerignore`

**Frontend (modificados):**
- `vite.config.js` — Proxy target configurable via `VITE_API_URL`

**Documentacion (modificados):**
- `SETUP_LOCAL.md` — Reestructurado: Opcion A (Docker zero-deps) + Opcion B (nativa)
- `DEPLOY_DIGITALOCEAN.md` — Agregado Redis, Reverb, Supervisor para Reverb, Nginx WebSocket proxy

## 14. Reporte Final de Cierre de Arquitectura

### Resumen de Progreso Global — TODOS LOS MODULOS COMPLETADOS
| # | Modulo | Backend | Frontend | Estado |
| :--- | :--- | :--- | :--- | :--- |
| 1 | Infraestructura Docker | PHP 8.4-FPM Alpine + PostgreSQL 16 + Redis 7 + Reverb WS | Vite + Node 22 Alpine + HMR | 🟢 |
| 2 | Migraciones & Modelos | 18 migraciones, 15 modelos, ENUMs nativos PostgreSQL | N/A | 🟢 |
| 3 | Autenticacion & 2FA | Sanctum + Google2FA TOTP + Kill-Switch | Login + TwoFactorModal | 🟢 |
| 4 | Catalogo & Variaciones | CRUD Categories/Products, AdvancedSoftDeletes | DataTable filtros avanzados, ProductFormPage | 🟢 |
| 5 | Promociones | CRUD con RBAC, StoreOrderRequest 1-promo limit | PromotionsPage + POSPage carrito reactivo | 🟢 |
| 6 | Caja Chica & Integridad | SHA256 seal, Events/Listeners/Notifications | WithdrawModal + AuditTicket termico | 🟢 |
| 7 | Ventas & Ticket Config | OrderController, Append-Only versioning | CheckoutModal + TicketPreview 58mm (migrado de 80mm) + @media print | 🟢 |
| 8 | Finanzas 70/30 & Stock | AnalyticsController, StockMovementController | Recharts dashboard, StockMovementsPage | 🟢 |
| 9 | Usuarios & Roles (RBAC) | UserController, Kill-Switch, URL firmada | UsersPage con TabView detail | 🟢 |
| 10 | Notificaciones Reverb | NotificationPreferenceController, upsert PK triple | NotificationPreferencesPage matricial | 🟢 |
| 11 | Papelera Global | TrashController unificado, restore/forceDelete RBAC | TrashPage con auditoria forense | 🟢 |

### Estadisticas Finales de la Arquitectura
- **Rutas API totales**: ~53 endpoints RESTful
- **Controladores**: 13 (Auth, TwoFactor, Category, Product, Promotion, PettyCash, TicketConfig, Order, Analytics, StockMovement, UserAdmin, NotificationPreference, Trash)
- **FormRequests**: 12 clases de validacion estricta
- **Modelos Eloquent**: 15 con relaciones completas
- **Middleware custom**: 2 (EnsureUserIsActive, EnsureUserHasRole)
- **Trait custom**: 1 (AdvancedSoftDeletes con deleted_by, deletion_reason, advancedRestore)
- **Mailables**: 5 (UserPasswordResetMail, LowStockAlertMail, PettyCashWithdrawalMail, CashRegisterClosingReportMail, PasswordResetLinkMail — todas ShouldQueue)
- **Eventos/Listeners**: 1 evento broadcast + 1 listener + 1 notificacion queued
- **Paginas React**: 14 (Login, Dashboard, Categories, Products, ProductForm, Promotions, POS, PettyCash, TicketConfig, Finance, StockMovements, UsersAdmin, NotificationPreferences, Trash)
- **Componentes React**: 8 (AppLayout, DeleteDialog, TwoFactorModal, WithdrawModal, AuditTicket, TicketPreview, CheckoutModal)
- **Librerias frontend**: React, Tailwind CSS v4, PrimeReact, Sonner, Recharts, Axios

### Patrones Arquitectonicos Implementados
1. **Inmutabilidad financiera**: order_items con _at_sale fields, petty_cash con JSONB snapshot + SHA256
2. **Append-Only**: ticket_configs con versionado incremental
3. **RBAC con middleware**: role:admin,manager para operaciones sensibles
4. **RBAC granular en controlador**: forceDelete exclusivo admin con doble validacion (middleware + logica interna)
5. **Kill-Switch**: Suspension instantanea de usuarios con revocacion de tokens (usado en UserController y EnsureUserIsActive)
6. **Transacciones atomicas**: DB::transaction con lockForUpdate en stock, ordenes, caja chica
7. **Segmentacion contable 70/30**: Configurable via global_settings, deducciones automaticas
8. **Validacion dual**: Frontend reactivo + Backend FormRequest (ej: 1 promo por ticket)
9. **URLs firmadas temporales**: URL::temporarySignedRoute para enlaces de restauracion de 4 horas
10. **Auditoria forense centralizada**: Papelera Global con trazabilidad completa (quien elimino, cuando, por que) via AdvancedSoftDeletes trait

### Cobertura de Seguridad
| Capa | Implementacion |
| :--- | :--- |
| Autenticacion | Sanctum tokens + 2FA TOTP |
| Autorizacion | RBAC middleware (admin/manager/vendor) |
| Kill-Switch | Suspension instantanea + revocacion de tokens |
| Integridad financiera | HMAC-SHA256 en caja chica, _at_sale inmutables |
| Borrado logico | AdvancedSoftDeletes con auditoria (who/when/why) |
| Purga permanente | Admin-only + doble confirmacion textual |
| URLs seguras | temporarySignedRoute con expiracion de 4 horas |
| Concurrencia | lockForUpdate en transacciones de stock |

### Estado del Sistema
Todos los 11 modulos planificados han sido completados exitosamente. El sistema Cronos POS esta listo para pruebas de integracion y despliegue.

### Notas Generales

#### Cambio de Arquitectura de Navegacion: Sidebar Global (Post-Fase 11)
Se refactorizo por completo el layout global del sistema, migrando de una barra de navegacion superior horizontal saturada a un **AppShell premium con Sidebar izquierdo fijo/colapsable**.

**Estructura del nuevo layout:**
- **AppLayout.jsx** — Shell raiz con flexbox: Sidebar fijo a la izquierda + contenedor fluido a la derecha (header + content area)
- **Sidebar.jsx** — Panel lateral izquierdo (`w-64`, colapsable a `w-[72px]`) con navegacion agrupada por categorias semanticas:
  - VENTAS: Dashboard, Punto de Venta, Tickets
  - CATALOGO: Productos, Categorías, Promociones
  - LOGISTICA: Almacén, Caja Chica
  - ADMINISTRACION: Usuarios, Finanzas, Notificaciones, Papelera
  - Cada item con icono SVG Heroicons, estados activos con `bg-indigo-50 text-indigo-700`, transiciones fluidas
  - Boton de colapso en el footer del sidebar con animacion de rotacion
- **AppHeader.jsx** — Header superior sticky con `backdrop-blur-md`, contiene:
  - Boton hamburguesa para toggle del sidebar
  - Titulo de pagina contextual dinamico segun ruta
  - KPI compacto en tiempo real ("X ventas hoy") con polling cada 60s
  - Campana de notificaciones con badge dinamico
  - Componente UserProfileDropdown
- **UserProfileDropdown.jsx** — OverlayPanel de PrimeReact con tarjeta de perfil:
  - Avatar con iniciales + gradiente indigo
  - Nombre, email, rol con Tag estilizado por rol (admin=rose, manager=amber, vendor=emerald)
  - Links internos: "Mi Perfil", "Configurar Alertas"
  - Boton "Cerrar Sesion" full-width con borde rose y hover rose-50

**Archivos creados:**
- `frontend/src/components/layout/Sidebar.jsx`
- `frontend/src/components/layout/AppHeader.jsx`
- `frontend/src/components/layout/UserProfileDropdown.jsx`

**Archivos modificados:**
- `frontend/src/components/layout/AppLayout.jsx` — Reescrito como AppShell con Sidebar + Header
- `frontend/src/index.css` — Agregado `aside` a reglas @media print para ocultar sidebar al imprimir

#### Sprint de Optimizacion Masiva: 7 Deudas Tecnicas Resueltas (Post-Sidebar)

##### Tarea 1: Validaciones Anti-Duplicados + Errores de Campo [🟢 Completado]
- **Backend**: Agregadas reglas `unique` en FormRequests para categorias (`unique:categories,name`) y promociones (`unique:promotions,name`), con `Rule::unique()->ignore()` en updates. Productos ya tenian SKU unico, usuarios ya tenian email unico.
- **Frontend**: Patron unificado de `fieldErrors` state en CategoriesPage, ProductFormPage, PromotionsPage y UsersPage. Parseo de `err.response.data.errors` en catch 422 para mostrar errores bajo cada campo con `text-rose-500`.

##### Tarea 2: Modulo de Configuracion del Sistema [🟢 Completado]
- **Backend**: `SystemSettingsController` con `index()` (retorna settings keyed by key) y `update()` (upsert en transaccion con `updated_by`). Migracion `add_system_columns` agrega `updated_by`, `created_at`, `updated_at` a `global_settings`. Modelo `GlobalSetting` actualizado con relacion `updatedByUser()`. Seeds: timezone, currency, fiscal_data.
- **Frontend**: `SystemSettingsPage` (`/admin/configuracion`) con TabView de 3 tabs: Region (timezone + currency dropdowns), Datos Fiscales (razon social, RFC, direccion, ciudad, telefono), Impuestos & Finanzas (IVA %, split inversion/utilidad). Ruta protegida con role:admin,manager.

##### Tarea 3: Modal de Ventas Diarias en Header [🟢 Completado]
- **Backend**: `DailySummaryController` (invokable) en `GET /api/sales/daily-summary`. Retorna: gross_income, net_income, total_tax, order_count, desglose por metodo de pago, total caja chica del dia.
- **Frontend**: Boton "billetera" en `AppHeader.jsx` que abre Dialog con resumen del dia: ingresos brutos/netos, IVA, ordenes, desglose por metodo de pago, total caja chica.

##### Tarea 4: Botones de Icono en DataTables [🟢 Completado]
- Reemplazo global de botones de texto ("Editar", "Eliminar", "Suspender", etc.) por Button con iconos PrimeReact (`pi-pencil`, `pi-trash`, `pi-ban`, `pi-key`, `pi-undo`, `pi-times-circle`, `pi-search`) con tooltips. Paginas actualizadas: CategoriesPage, ProductsPage, PromotionsPage, UsersPage, PettyCashPage, TrashPage.

##### Tarea 5: Upload de Imagen de Producto (Storage Local) [🟢 Completado]
- **Backend**: `ProductImageController` con `upload()` (valida jpeg/png max 2MB, almacena en disk 'public') y `destroy()` (elimina archivo y limpia image_url). Migracion agrega `image_url` (varchar 500) a products. `docker-entrypoint.sh` actualizado con `php artisan storage:link`.
- **Frontend**: FileUpload de PrimeReact en `ProductFormPage` (solo en modo edicion). Preview de imagen con boton de eliminar. Envio multipart/form-data.

##### Tarea 6: Pagina de Perfil de Usuario [🟢 Completado]
- **Backend**: `ProfileController` con `show()`, `update()` (name, phone), `updatePassword()` (valida current_password con Hash::check), `sessions()` (ultimas 10 sesiones), `revokeSession()` (revoca sesion propia). Migracion agrega `phone` y `avatar_url` a users.
- **Frontend**: `ProfilePage` (`/profile`) con 3 secciones: Datos Personales (avatar con iniciales, nombre, email read-only, telefono), Seguridad (cambio de contraseña con validacion, estado 2FA), Auditoria de Sesiones (grid con IP, navegador, indicador activa, boton revocar).

##### Tarea 7: Dashboard Transformado con Graficas [🟢 Completado]
- **Backend**: `DashboardController` con `stats()` (ventas mes, ordenes hoy, ticket promedio, alertas stock bajo), `hourlyTrend()` (array 24h con ventas por hora via PostgreSQL EXTRACT), `topProducts()` (top 5 productos por cantidad vendida con joins order_items+orders+products).
- **Frontend**: `DashboardPage` reescrito con 4 KPI cards, LineChart (Recharts) para tendencia horaria de ventas, PieChart (donut) para top 5 productos del mes.

**Archivos creados en este sprint:**
- `backend/app/Http/Controllers/Admin/SystemSettingsController.php`
- `backend/app/Http/Controllers/Sales/DailySummaryController.php`
- `backend/app/Http/Controllers/Dashboard/DashboardController.php`
- `backend/app/Http/Controllers/Catalog/ProductImageController.php`
- `backend/app/Http/Controllers/Profile/ProfileController.php`
- `backend/database/migrations/2026_06_16_000001_add_system_columns.php`
- `frontend/src/pages/admin/SystemSettingsPage.jsx`
- `frontend/src/pages/profile/ProfilePage.jsx`

**Archivos modificados en este sprint:**
- `backend/app/Http/Requests/Category/StoreCategoryRequest.php` — unique name
- `backend/app/Http/Requests/Category/UpdateCategoryRequest.php` — unique name ignore
- `backend/app/Http/Requests/Promotion/StorePromotionRequest.php` — unique name
- `backend/app/Http/Requests/Promotion/UpdatePromotionRequest.php` — unique name ignore
- `backend/app/Models/Product.php` — image_url fillable
- `backend/app/Models/User.php` — phone, avatar_url fillable
- `backend/app/Models/GlobalSetting.php` — updated_by, timestamps, updatedByUser relation
- `backend/database/seeders/DatabaseSeeder.php` — timezone, currency, fiscal_data seeds
- `backend/routes/api.php` — ~15 rutas nuevas (settings, daily-summary, dashboard, product image, profile)
- `backend/docker-entrypoint.sh` — storage:link
- `frontend/src/App.jsx` — Rutas /profile, /admin/configuracion
- `frontend/src/components/layout/Sidebar.jsx` — Nav item "Configuracion"
- `frontend/src/components/layout/AppHeader.jsx` — Modal ventas diarias
- `frontend/src/components/layout/UserProfileDropdown.jsx` — Link "Mi Perfil" → /profile
- `frontend/src/pages/DashboardPage.jsx` — Reescrito con KPIs + Recharts
- `frontend/src/pages/catalog/CategoriesPage.jsx` — fieldErrors + icon buttons
- `frontend/src/pages/catalog/ProductsPage.jsx` — icon buttons
- `frontend/src/pages/catalog/ProductFormPage.jsx` — fieldErrors + image upload
- `frontend/src/pages/promotions/PromotionsPage.jsx` — fieldErrors + icon buttons
- `frontend/src/pages/admin/UsersPage.jsx` — fieldErrors + icon buttons
- `frontend/src/pages/finance/PettyCashPage.jsx` — icon buttons
- `frontend/src/pages/admin/TrashPage.jsx` — icon buttons

## 15. Detalle del Modulo Completado: Historial de Ventas & Metodos de Pago Dinamicos

### Cambio Arquitectonico: Metodos de Pago Dinamicos
Se reemplazo el ENUM nativo de PostgreSQL `payment_method` por una tabla relacional `payment_methods` con FK restrictiva, permitiendo gestion dinamica (CRUD) de metodos de pago sin alterar la estructura de la BD.

### Migracion: payment_methods (0001_01_01_000011a)
- `id` (UUID, PK), `name` (varchar 100), `slug` (varchar 50, unique), `status` (active/inactive), `is_system` (boolean), timestamps
- Ubicada entre cash_registers (000011) y orders (000012) para respetar dependencias FK

### Migracion: orders (000012) — Modificada
- Reemplazado `payment_method` ENUM por `payment_method_id` UUID FK → `payment_methods(id) ON DELETE RESTRICT`
- Agregado `status` (order_status ENUM: completed/canceled, default completed)
- Agregado `canceled_by` UUID FK → users(id) ON DELETE SET NULL
- Agregado `canceled_at` timestampTz nullable
- Agregado `cancellation_reason` text nullable

### ENUM: order_status (Nuevo)
- Agregado en migracion 000000: `CREATE TYPE order_status AS ENUM ('completed', 'canceled')`
- Removido ENUM `payment_method` (reemplazado por tabla relacional)

### Modelo: PaymentMethod (Nuevo)
- HasUuids, fillable: name, slug, status, is_system
- Relacion: `orders()` HasMany

### Modelo: Order (Modificado)
- Reemplazado `payment_method` string por `payment_method_id` FK
- Agregado `status`, `canceled_by`, `canceled_at`, `cancellation_reason` a fillable
- Nuevas relaciones: `paymentMethod()` BelongsTo, `canceledByUser()` BelongsTo

### Modelo: Product (Modificado)
- Agregados mutadores `setSkuAttribute()` y `setParentSkuAttribute()` que convierten a MAYUSCULAS automaticamente

### Seeder: DatabaseSeeder (Modificado)
- Seed de 3 metodos de pago base: Efectivo (cash), Tarjeta de Credito/Debito (card), Transferencia (transfer) — todos con `is_system: true`

### Backend - API Endpoints Nuevos (8 rutas)
| Metodo | Ruta | Middleware | Descripcion |
| :--- | :--- | :--- | :--- |
| GET | /api/payment-methods | auth, user.active | Lista metodos de pago (filtro por status) |
| POST | /api/payment-methods | auth, user.active, role:admin,manager | Crear metodo de pago |
| PUT | /api/payment-methods/{id} | auth, user.active, role:admin,manager | Actualizar metodo de pago |
| DELETE | /api/payment-methods/{id} | auth, user.active, role:admin,manager | Eliminar (bloqueado si tiene ventas) |
| POST | /api/orders/{id}/cancel | auth, user.active | Cancelar orden con password admin |
| GET | /api/sales/export | auth, user.active | Exportar ventas a CSV |
| GET | /api/orders | auth, user.active | Lista ordenes con filtros avanzados (payment_method_id, status, user_id, total_min/max) |

### Controlador: PaymentMethodController (Nuevo)
- `index()` — Lista metodos con filtro por status
- `store()` — Crea metodo con validacion unique name/slug, is_system=false
- `update()` — Actualiza name/slug/status
- `destroy()` — Validacion estricta: si tiene ventas asociadas retorna 422 (ERR_PAYMENT_METHOD_HAS_SALES) con sugerencia de cambiar a inactive

### Controlador: OrderController (Modificado)
- `index()` — Filtros expandidos: payment_method_id, status, user_id, total_min, total_max + eager load paymentMethod y cashRegister.user
- `store()` — Usa payment_method_id FK, status='completed' por defecto
- `show()` — Carga paymentMethod y canceledByUser
- `cancel()` (Nuevo) — Valida password admin con Hash::check, revierte stock de todos los items, actualiza status/canceled_by/canceled_at/cancellation_reason en transaccion atomica

### Controlador: SalesExportController (Nuevo)
- `export()` — Genera CSV con cabecera institucional (Cronos POS, fecha generacion, periodo), BOM UTF-8 para compatibilidad Excel. Recibe mismos filtros que index(). StreamedResponse sin dependencias externas.

### Controladores Modificados (Filtro status=completed)
- `DailySummaryController` — Solo suma ordenes completed, by_payment usa JOIN payment_methods
- `AnalyticsController` — salesByPaymentMethod/financialSummary/dailyTrend filtran por status=completed
- `DashboardController` — stats/hourlyTrend/topProducts filtran por status=completed

### Frontend - SalesHistoryPage (`/admin/ventas`) (Nuevo)
- **Quick Filters**: SelectButton (Hoy, Ultima Semana, Ultimo Mes)
- **Advanced Filters**: Panel colapsable con Calendar range, Dropdown metodo de pago (dinamico desde API), Dropdown operador (solo admin/manager), InputNumber monto minimo, Dropdown estatus
- **DataTable lazy paginated**: ID Ticket (mono), Fecha/Hora, Vendedor, Metodo de Pago (Tag), Total Neto, Estatus (Tag success/danger), Acciones (iconos)
- **Modal Detalle** (pi-eye): Desglose completo con tabla de items, cantidades, precios unitarios, descuentos, info de cancelacion si aplica
- **Modal Reimprimir** (pi-print): Renderiza TicketPreview con ticket config activo
- **Modal Cancelacion** (pi-ban): Solo admin/manager, requiere motivo + password admin, banner de advertencia, Password con toggleMask

### Frontend - CheckoutModal (Modificado)
- Dropdown de metodo de pago ahora consume GET /api/payment-methods dinamicamente
- Envia `payment_method_id` UUID en lugar de string enum

### Frontend - TicketPreview (Modificado)
- Soporta payment_method como objeto {name, slug} (dinamico) o string (legacy)

### Frontend - AppHeader (Modificado)
- Daily summary renderiza desglose por metodo de pago dinamicamente desde API (slug keyed)

### Frontend - ProductFormPage (Modificado)
- SKU y parent_sku se convierten a MAYUSCULAS en tiempo real via `e.target.value.toUpperCase()` en onChange

### Frontend - Sidebar (Modificado)
- Agregado nav item "Historial" bajo grupo VENTAS con ruta /admin/ventas

### Archivos Creados en esta Fase
**Backend (nuevos):**
- `database/migrations/0001_01_01_000011a_create_payment_methods_table.php`
- `app/Models/PaymentMethod.php`
- `app/Http/Controllers/Catalog/PaymentMethodController.php`
- `app/Http/Controllers/Sales/SalesExportController.php`

**Frontend (nuevos):**
- `src/pages/sales/SalesHistoryPage.jsx`

**Backend (modificados):**
- `database/migrations/0001_01_01_000000_create_enums.php` — Removido payment_method enum, agregado order_status enum
- `database/migrations/0001_01_01_000012_create_orders_table.php` — payment_method_id FK, status, cancel fields
- `app/Models/Order.php` — payment_method_id, status, cancel fields, nuevas relaciones
- `app/Models/Product.php` — Mutadores SKU uppercase
- `database/seeders/DatabaseSeeder.php` — Seed payment_methods
- `app/Http/Controllers/Sales/OrderController.php` — Filtros expandidos, cancel(), payment_method_id
- `app/Http/Requests/Order/StoreOrderRequest.php` — payment_method_id UUID validation
- `app/Http/Controllers/Sales/DailySummaryController.php` — JOIN payment_methods, status filter
- `app/Http/Controllers/Finance/AnalyticsController.php` — JOIN payment_methods, status filter
- `app/Http/Controllers/Dashboard/DashboardController.php` — status=completed filter
- `routes/api.php` — 8 rutas nuevas

**Frontend (modificados):**
- `src/App.jsx` — Ruta /admin/ventas
- `src/components/layout/Sidebar.jsx` — Nav item "Historial"
- `src/components/layout/AppHeader.jsx` — Dynamic payment methods en daily summary
- `src/components/pos/CheckoutModal.jsx` — Dynamic payment methods via API
- `src/components/pos/TicketPreview.jsx` — Soporta payment method como objeto
- `src/pages/catalog/ProductFormPage.jsx` — SKU/parent_sku uppercase onChange

## 16. Refactorizacion Critica: Cancelacion Auditable, Impresion CSS Aislada & Motor Financiero Tax-Inclusive [🟢 Completado]

### Tarea 1: Cancelacion de Ventas Auditable [🟢 Completado]

#### Backend
- **OrderController::cancel()** refactorizado para validar contraseña contra usuarios con rol `admin` O `manager` (no solo admin) usando `Hash::check` iterativo
- **Transaccion atomica** con `DB::transaction`:
  1. Revierte stock de productos con `track_stock == true` via `Product::increment()`
  2. Registra contra-movimiento en `stock_movements` de tipo `adjustment` por cada producto revertido
  3. Actualiza orden con status `canceled`, `canceled_by`, `canceled_at`, `cancellation_reason`
  4. Inserta traza forense en nueva tabla `audit_logs` con metadata JSONB (autorizador, IP, user_agent, razon, total, items)

#### Migracion: audit_logs (2026_06_22_000001)
- `id` UUID PK, `action` varchar, `auditable_type` + `auditable_id` (polimorfismo), `user_id` FK nullable, `metadata` JSONB, `ip_address`, `user_agent`, `created_at` timestampTz
- Indices en `(auditable_type, auditable_id)` y `action`

#### Modelo: AuditLog (Nuevo)
- HasUuids, timestamps deshabilitados, cast metadata a array

#### Frontend (SalesHistoryPage)
- Modal de cancelacion ya existente funciona correctamente con la nueva logica backend
- Acepta contraseña de admin O manager para autorizar

### Tarea 2: Solucion Definitiva al Flujo de Impresion [🟢 Completado]

#### CSS Print Isolation (index.css)
- Implementada directiva `@media print` con aislamiento estricto:
  - `body * { visibility: hidden }` — Oculta todo el AppShell
  - `#ticket-print-area, #ticket-print-area * { visibility: visible }` — Solo muestra el ticket
  - `#ticket-print-area { position: absolute; left: 0; top: 0; width: 80mm }` — Dimensionamiento para papel termico
  - `@page { size: 80mm auto; margin: 0 }` — Configuracion de pagina para ticketera

#### TicketPreview (Modificado)
- Agregado `id="ticket-print-area"` al contenedor raiz del ticket
- Funciona tanto en CheckoutModal (POS) como en SalesHistoryPage (reimprimir)

#### CheckoutModal (Modificado)
- Auto-disparo de `window.print()` 400ms despues de confirmar cobro exitoso
- Flujo completo: API -> limpiar carrito -> mostrar ticket -> auto-imprimir
- Boton manual "Imprimir Ticket" preservado para reimpresion

### Tarea 3: Motor Financiero Tax-Inclusive (Desglose IVA Invertido) [🟢 Completado]

#### Formulas Matematicas Implementadas
- **Precio Neto (Subtotal)** = Precio Final / (1 + IVA%)
- **Monto IVA** = Precio Final - Precio Neto
- Ejemplo: Precio publico $90 con IVA 16% → Subtotal $77.59, IVA $12.41, Total $90.00

#### Backend (OrderController::store)
- Lee `tax_rate` dinamicamente de `global_settings` (fallback 0.16)
- Calcula desglose inverso: `base_price_at_sale` = precio neto por unidad, `tax_amount_at_sale` = IVA extraido, `final_price_at_sale` = precio publico original
- `orders.subtotal` = total bruto / (1 + tasa), `orders.iva_total` = total - subtotal, `orders.total` = precio publico cobrado

#### API: GET /api/tax-rate (Nueva)
- Endpoint publico (autenticado) que retorna la tasa de IVA configurada en `global_settings`
- Respuesta: `{ rate: 0.16, label: "IVA 16%" }`

#### Frontend
- **POSPage**: Obtiene `taxRate` de API al montar, calcula subtotal/IVA con formula inversa, label dinamico "IVA (X%)"
- **CheckoutModal**: Recibe `taxRate` como prop, calcula desglose inverso, pasa a TicketPreview
- **TicketPreview**: Recibe `taxRate` como prop, muestra label dinamico "IVA (X%):"
- **SalesHistoryPage**: Obtiene `taxRate` de API, muestra label dinamico en detalle modal y pasa a TicketPreview en reimprimir

### Archivos Creados en esta Fase
**Backend (nuevos):**
- `database/migrations/2026_06_22_000001_create_audit_logs_table.php`
- `app/Models/AuditLog.php`

**Backend (modificados):**
- `app/Http/Controllers/Sales/OrderController.php` — Tax-inclusive store(), auditable cancel() con stock_movements + audit_logs
- `routes/api.php` — Endpoint GET /api/tax-rate

**Frontend (modificados):**
- `src/index.css` — @media print con #ticket-print-area isolation estricto 80mm
- `src/components/pos/TicketPreview.jsx` — id="ticket-print-area", taxRate prop, IVA label dinamico
- `src/components/pos/CheckoutModal.jsx` — taxRate prop, formula inversa, auto-print post-venta
- `src/pages/pos/POSPage.jsx` — Fetch taxRate de API, formula inversa, taxRate prop a CheckoutModal
- `src/pages/sales/SalesHistoryPage.jsx` — Fetch taxRate de API, IVA label dinamico, taxRate prop a TicketPreview

---

## 17. Correcciones de Sincronizacion Asincrona y UX — Flujo de Ventas [🟢 Completado]

### Fix 1: Ciclo de Vida del Checkout Modal [🟢 Completado]
**Problema**: La modal de cobro no se cerraba tras el pago exitoso, y la impresion se disparaba antes de hidratar los datos.
**Solucion**: CheckoutModal ahora cierra inmediatamente tras exito y delega la impresion al componente padre (POSPage).
- Secuencia estricta: API POST → toast.success → onSuccess(order, ticketConfig) → onHide() → parent clearCart → setTimeout(350ms) → window.print()
- Se elimino el estado `completedOrder` del modal — ya no muestra vista post-venta
- `onSuccess` signature cambiada a `(order, ticketConfig)` para que POSPage pueda renderizar el ticket independientemente

### Fix 2: Impresion con Datos Reales (Blank Ticket Fix) [🟢 Completado]
**Problema**: TicketPreview con `id="ticket-print-area"` se renderizaba DENTRO del Dialog de PrimeReact. El CSS `body * { visibility: hidden }` ocultaba el overlay del Dialog, dejando el ticket invisible durante la impresion.
**Solucion**: El ticket de impresion se renderiza ahora a nivel de POSPage, FUERA de cualquier Dialog, en un `<div className="hidden print:block">`.
- Nuevos estados en POSPage: `activeOrderForPrinting`, `activeTicketConfigForPrinting`
- `handleCheckoutSuccess` guarda order+config, limpia carrito, espera 350ms, luego invoca window.print()
- El TicketPreview de impresion usa `position: absolute` via CSS para posicionarse en top-left durante print

### Fix 3: Boton de Cancelacion en Historial de Ventas [🟢 Completado]
**Verificacion**: El boton de cancelar (pi pi-ban) ya existia en SalesHistoryPage con modal de contrasena admin, campo de razon, y llamada a `/orders/{id}/cancel`. Funcionalidad confirmada intacta.

### Fix 4: Ruta de Escape en Apertura de Caja [🟢 Completado]
**Problema**: La pantalla de apertura de caja no tenia forma de regresar al dashboard sin abrir una caja.
**Solucion**: Agregado boton "Regresar al Panel" con `useNavigate('/dashboard')` en la pantalla de apertura de caja de POSPage.

### Fix 5: setTimeout en Reimprimir Ticket del Historial [🟢 Completado]
**Problema**: La funcion handlePrint en SalesHistoryPage ejecutaba `window.print()` directamente, arriesgando impresion en blanco.
**Solucion**: Envuelto en `setTimeout(() => window.print(), 350)` para permitir hidratacion del DOM.

### Archivos Modificados en esta Fase
**Frontend:**
- `src/components/pos/CheckoutModal.jsx` — Reescrito: cierra inmediatamente, onSuccess(order, ticketConfig), sin vista post-venta
- `src/pages/pos/POSPage.jsx` — Reescrito: print ticket fuera de Dialog, handleCheckoutSuccess con setTimeout, boton "Regresar al Panel"
- `src/pages/sales/SalesHistoryPage.jsx` — handlePrint con setTimeout(350ms)

---

## 18. Resiliencia Local-First, Guardias de Seguridad, Roles CRUD & Detalle Avanzado [🟢 Completado]

### Tarea 1: Estrategia de Resiliencia Local-First (Frontend) [🟢 Completado]

#### Hook de Conexion: useOnlineStatus
- Hook nativo React con `navigator.onLine` + event listeners `online`/`offline`
- Indicador visual en AppHeader: badge `text-amber-500` con icono `pi pi-wifi` y texto "Operando en Modo Offline (Local)"
- Oculta el KPI de ventas del dia cuando esta offline para evitar datos stale

#### Buffer Offline de Ventas
- Funciones utilitarias: `addToOfflineQueue()`, `getOfflineQueue()`, `clearOfflineQueue()`, `removeFromOfflineQueue()`
- Almacenamiento en LocalStorage bajo clave `offline_orders_queue`
- Cada entrada incluye `_offline_id` (UUID), `_queued_at` (timestamp), payload completo de la orden
- CheckoutModal detecta estado offline (prop `isOnline` o fallback via `navigator.onLine` en catch de red)
- En modo offline: guarda payload, genera orden local con UUID temporal, cierra modal, limpia carrito, invoca `window.print()` normal
- Toast informativo: "Orden guardada localmente — Se sincronizará automáticamente al recuperar conexión"

#### Background Sync
- useEffect en POSPage escucha cambios de `isOnline`
- Al regresar a online: recorre buffer atómicamente, envía cada orden a `POST /api/orders`
- Limpia cola tras completar, notifica con Sonner: "Sincronización completada: X órdenes offline procesadas"
- Protección contra doble ejecución con `syncingRef`

### Tarea 2: Doble Confirmacion por Impacto y Guardia de Auto-Accion [🟢 Completado]

#### Modals de Impacto Explicito (Frontend)
- **Suspender**: Dialog de PrimeReact con mensaje "Al suspender a este usuario se revocarán inmediatamente todas sus sesiones activas y no podrá ingresar al sistema hasta que sea reactivado. ¿Confirmar acción?"
- **Resetear Contraseña**: Dialog con mensaje "Se enviará un enlace de restauración de contraseña con vigencia estricta de 4 horas al correo institucional del usuario. ¿Confirmar envío?"
- **Dar de Baja**: Dialog con banner rose explicando impacto forense, campo de motivo obligatorio, botón deshabilitado hasta completar motivo

#### Bloqueo de Auto-Accion
- **Frontend**: Evalúa `authUser.id === row.id` en la columna de acciones. Si coincide: remueve botones Suspender/Resetear/Eliminar, muestra texto "Acciones bloqueadas (usuario propio)", solo deja visible el botón de detalle (pi pi-user)
- **Backend**: Validación `if ($user->id === auth()->id()) abort(422, "Operación no permitida sobre el usuario autenticado actualmente.")` en `toggleStatus()`, `destroy()`, y `sendPasswordReset()` del UserController

### Tarea 3: Gestión Completa de Roles y Permisos (CRUD + Mapping) [🟢 Completado]

#### Backend: RoleController (6 rutas nuevas)
| Metodo | Ruta | Descripcion |
| :--- | :--- | :--- |
| GET | /api/admin/roles | Lista roles con conteo de usuarios asignados |
| GET | /api/admin/roles/permissions | Lista permisos disponibles del sistema (15 permisos, 5 grupos) |
| POST | /api/admin/roles | Crear rol (name unique, lowercase) |
| GET | /api/admin/roles/{id} | Detalle de rol con usuarios asignados |
| PUT | /api/admin/roles/{id} | Actualizar nombre del rol |
| DELETE | /api/admin/roles/{id} | Eliminar rol (bloquea si tiene usuarios con ERR_ROLE_HAS_USERS 422) |

- Regla de integridad: si `model_has_roles` tiene registros para el rol, retorna 422 con mensaje descriptivo

#### Frontend: RolesPermissionsPage (`/admin/roles-permisos`)
- Layout grid 1/3 + 2/3: DataTable de roles a la izquierda, panel de permisos a la derecha
- DataTable: nombre clickeable (selecciona rol), conteo de usuarios asignados (Tag), acciones (editar/eliminar)
- Panel de permisos: matriz agrupada por categoría (Ventas, Catálogo, Logística, Administración) con Checkboxes informativos
- Dialog crear/editar rol con validación unique
- Dialog eliminar con bloqueo si tiene usuarios asignados

### Tarea 4: Panel de Detalle Avanzado de Usuario [🟢 Completado]
- Boton `pi pi-user` (color slate/indigo con cursor-pointer) agregado a la columna de acciones de cada fila
- Al hacer clic abre Dialog con TabView de 3 tabs:
  * **Seguridad**: Estado 2FA (HABILITADO/DESHABILITADO con fecha), última restauración de contraseña, tokens activos
  * **Sesiones**: Últimas 3 conexiones (IP, navegador parseado de user_agent, estado de sesión con indicador activa/inactiva, botón "Forzar Cierre")
  * **Auditoría de Cajas**: Historial de cash_registers con apertura/cierre, balances (apertura, esperado, real), cálculo de diferencia con color (verde positivo, rojo negativo)

### Archivos Creados en esta Fase
**Backend (nuevos):**
- `app/Http/Controllers/Admin/RoleController.php`

**Frontend (nuevos):**
- `src/hooks/useOnlineStatus.js`
- `src/pages/admin/RolesPermissionsPage.jsx`

### Archivos Modificados en esta Fase
**Backend (modificados):**
- `app/Http/Controllers/Admin/UserController.php` — Guardias de auto-acción en toggleStatus, destroy, sendPasswordReset
- `routes/api.php` — 6 rutas nuevas bajo /admin/roles

**Frontend (modificados):**
- `src/App.jsx` — Ruta /admin/roles-permisos
- `src/components/layout/Sidebar.jsx` — Nav item "Roles y Permisos"
- `src/components/layout/AppHeader.jsx` — Indicador offline con useOnlineStatus, page name roles-permisos
- `src/components/pos/CheckoutModal.jsx` — Prop isOnline, buffer offline con addToOfflineQueue, fallback offline en catch
- `src/pages/pos/POSPage.jsx` — useOnlineStatus, background sync con getOfflineQueue/clearOfflineQueue, prop isOnline a CheckoutModal
- `src/pages/admin/UsersPage.jsx` — useAuth para self-guard, modals doble confirmación (suspender, reset, dar de baja), botón pi-user detalle

---

## 19. Modulo de Correos Corporativos Centralizados [🟢 Completado]

### Arquitectura de Email

#### Master Layout: corporate.blade.php
- Template Blade base (`resources/views/mail/layouts/corporate.blade.php`) con estructura de tablas HTML para compatibilidad universal con clientes de email
- **Header**: Badge "Cronos POS" con fondo indigo (#4f46e5) y texto blanco, tipografia Inter/Segoe UI/sans-serif
- **Body**: Tarjeta blanca de 600px max-width sobre fondo #f8fafc con `@yield('content')` para contenido dinamico
- **Footer Legal**: Datos fiscales dinamicos desde `GlobalSetting::where('key', 'fiscal_data')`, disclaimers de confidencialidad y procesamiento automatizado
- **Design Tokens**: bg #f8fafc, card white 600px, text #0f172a/#475569, CTA #4f46e5, footer #94a3b8, SIN emojis
- MSO conditional comments para compatibilidad con Outlook

#### Mailables (4 clases, todas ShouldQueue via Redis)

##### UserPasswordResetMail
- **Archivo**: `app/Mail/UserPasswordResetMail.php`
- **Vista**: `mail.password-reset`
- Recibe `User $user` y `string $resetUrl`
- Contenido: saludo personalizado, boton CTA indigo con URL firmada, aviso de vigencia 4 horas, URL alternativa en texto plano
- Integrado en `UserController::sendPasswordReset()` reemplazando `PasswordResetLinkMail` con `Mail::to()->queue()`

##### LowStockAlertMail
- **Archivo**: `app/Mail/LowStockAlertMail.php`
- **Vista**: `mail.low-stock-alert`
- Recibe `array $products` con estructura: sku, name, category, minimum_stock, current_stock
- Subject dinamico: "Cronos POS - Alerta de Stock Critico (N producto/s)"
- Tabla HTML con columnas SKU, Producto, Categoria, Minimo, Actual
- Stock actual color-coded: rojo (#dc2626) si <=0, amber (#d97706) si >0
- Banner de advertencia con conteo total de productos afectados

##### PettyCashWithdrawalMail
- **Archivo**: `app/Mail/PettyCashWithdrawalMail.php`
- **Vista**: `mail.petty-cash-withdrawal`
- Recibe `PettyCashTransaction $transaction`
- Mapeo de razones: provider_payment, supplies_purchase, change_delivery, emergency → etiquetas en espanol
- Tabla key-value: monto (rojo con formato MXN), concepto, justificacion, operador, email operador, fecha/hora
- Sello SHA256 en monospace con fondo slate
- Disclaimer de inmutabilidad criptografica
- Integrado en `PettyCashWithdrawalNotification::toMail()` retornando instancia del Mailable

##### CashRegisterClosingReportMail
- **Archivo**: `app/Mail/CashRegisterClosingReportMail.php`
- **Vista**: `mail.cash-register-closing-report`
- Recibe parametros escalares: closingId, operatorName, closingDate, expectedAmount, declaredAmount, differenceAmount, paymentBreakdown[], pdfPath?
- Metricas principales: operador, fecha, esperado, declarado, diferencia
- Diferencia color-coded con badges: FALTANTE (rojo), SOBRANTE (amber), EXACTO (verde)
- Seccion de desglose por metodo de pago (condicional)
- Adjunto PDF opcional via `attachments()` con nombre `arqueo-caja-{ID}.pdf`

### Rutas de Preview Local (Solo Entorno 'local')
- **Archivo**: `routes/web.php`
- Protegidas con `if (app()->environment('local'))`
- 4 endpoints bajo prefijo `/mail-preview/`:
  - `GET /mail-preview/password-reset` — Renderiza UserPasswordResetMail con usuario existente o dummy
  - `GET /mail-preview/low-stock` — Renderiza LowStockAlertMail con 4 productos ficticios
  - `GET /mail-preview/petty-cash` — Renderiza PettyCashWithdrawalMail con transaccion real o dummy con snapshot
  - `GET /mail-preview/cash-register-closing` — Renderiza CashRegisterClosingReportMail con datos ficticios de cierre con diferencia negativa
- Retornan directamente la instancia Mailable para preview en navegador sin disparar envio real

### Archivos Creados en esta Fase
**Backend (nuevos):**
- `resources/views/mail/layouts/corporate.blade.php` — Master layout corporativo
- `resources/views/mail/password-reset.blade.php` — Vista email reset de contraseña
- `resources/views/mail/low-stock-alert.blade.php` — Vista email alerta stock bajo
- `resources/views/mail/petty-cash-withdrawal.blade.php` — Vista email retiro caja chica
- `resources/views/mail/cash-register-closing-report.blade.php` — Vista email cierre de caja
- `app/Mail/UserPasswordResetMail.php` — Mailable ShouldQueue
- `app/Mail/CashRegisterClosingReportMail.php` — Mailable ShouldQueue con PDF attachment

### Archivos Modificados en esta Fase
**Backend (modificados):**
- `app/Mail/LowStockAlertMail.php` — Actualizado para usar vista `mail.low-stock-alert` con layout corporativo
- `app/Mail/PettyCashWithdrawalMail.php` — Actualizado para usar vista `mail.petty-cash-withdrawal` con layout corporativo
- `app/Http/Controllers/Admin/UserController.php` — Usa `UserPasswordResetMail` con `Mail::to()->queue()` en lugar de `send()`
- `app/Notifications/PettyCashWithdrawalNotification.php` — `toMail()` retorna instancia de `PettyCashWithdrawalMail`
- `routes/web.php` — 4 rutas de preview local bajo `/mail-preview/`

---

## 20. Visualizador In-App de Plantillas de Correo (Preview Iframe Aislado) [🟢 Completado]

### Backend: MailPreviewController (2 rutas nuevas)
| Metodo | Ruta | Middleware | Descripcion |
| :--- | :--- | :--- | :--- |
| GET | /api/admin/mail-templates | auth, user.active, role:admin,manager | Lista plantillas disponibles (slug + nombre ejecutivo) |
| GET | /api/admin/mail-templates/{slug}/render | auth, user.active, role:admin,manager | Renderiza Mailable con datos ficticios, retorna HTML puro (text/html) |

#### Controlador: MailPreviewController
- `index()` — Retorna catalogo de 4 plantillas: password-reset, low-stock, petty-cash, day-closing con nombres en espanol
- `render(slug)` — Match por slug, instancia el Mailable correspondiente con factory mock data, invoca `$mailable->render()` de Laravel para obtener HTML compilado por Blade, retorna con Content-Type `text/html; charset=UTF-8`
- Datos de fabrica: usuario dummy o real para password-reset, 3 productos ficticios para low-stock, transaccion real o snapshot simulado para petty-cash, cierre con diferencia negativa para day-closing
- Slug invalido retorna 404

### Frontend: MailTemplatesPage (`/admin/notificaciones/plantillas`)

#### Layout Splitter (Panel Dual)
- **Panel Izquierdo (w-80)**: ListBox de PrimeReact con catalogo de plantillas, iconos por tipo (pi-key, pi-exclamation-triangle, pi-wallet, pi-lock), seleccion activa con highlight
- **Panel Derecho (flex-1)**: Contenedor bg-slate-100 con toolbar superior y visor iframe

#### Toolbar de Control
- Nombre de plantilla seleccionada + badge slug
- SelectButton responsivo: Desktop (max-w-[600px]) / Mobile (max-w-[375px]) para simular dispositivos

#### Visor Iframe Aislado (CSS Isolation)
- `<iframe srcDoc={html}>` recibe HTML renderizado via Axios con Bearer Token de Sanctum
- sandbox="allow-same-origin" para aislamiento de CSS (Tailwind v4 no contamina estilos inline del email)
- Transicion suave de ancho con `transition-all duration-300`

#### Gestion de Estados
- **Loading**: ProgressSpinner centrado con texto "Renderizando plantilla..."
- **Error**: Tarjeta rose con icono pi-times-circle, mensaje descriptivo y boton "Reintentar"
- **Vacio**: Mensaje placeholder cuando no hay plantilla seleccionada

### Archivos Creados en esta Fase
**Backend (nuevos):**
- `app/Http/Controllers/Admin/MailPreviewController.php`

**Backend (modificados):**
- `routes/api.php` — 2 rutas nuevas bajo /admin/mail-templates con role:admin,manager

**Frontend (nuevos):**
- `src/pages/admin/MailTemplatesPage.jsx`

**Frontend (modificados):**
- `src/App.jsx` — Ruta /admin/notificaciones/plantillas
- `src/components/layout/Sidebar.jsx` — Nav item "Plantillas Email" con icono envelope bajo ADMINISTRACION
- `src/components/layout/AppHeader.jsx` — Page name "Plantillas de Correo"

---

## 21. Optimizacion de Hardware de Impresion: Migracion de 80mm a 58mm (Definitivo) [🟢 Completado]

### Contexto de Hardware
Tras pruebas fisicas con la ticketera real del restaurante, se confirmo que el hardware NO es de 80mm. Se trata de una **impresora termica de 58mm (Modelo 58-VII-U)** con capacidad fisica estricta de **384 puntos por linea** (~32-36 caracteres por linea con fuentes estandar). El diseño anterior desbordaba margenes, generaba textos borrosos por anti-aliasing del navegador, y cortaba informacion del lado derecho (ej. imprimia 'EFE[' en vez de 'EFECTIVO').

### Cambios Implementados

#### 1. Rediseño Completo del TicketPreview.jsx
- **Ancho del contenedor**: Cambiado de `302px` (80mm) a `width: 48mm; max-width: 58mm` para aprovechar al maximo el area de quemado de la cabeza termica (384 dots)
- **Tipografia termica**: Fuente `'Courier New', Courier, monospace` con `font-size: 12px`, `line-height: 1.3`, `font-weight: 600`
- **Anti-aliasing deshabilitado**: `-webkit-font-smoothing: none; -moz-osx-font-smoothing: unset` para quemado solido en papel termico
- **Alineacion por caracteres**: Funciones `padLine()` y `truncate()` con `LINE_WIDTH = 32` caracteres para alineacion precisa columna-por-columna usando `<pre>` tags
- **Separadores de texto**: `'='.repeat(32)` y `'-'.repeat(32)` en lugar de lineas CSS, calculados al limite exacto de la linea de impresion
- **Metadatos sin recorte**: `white-space: nowrap` en campos criticos (Folio, Fecha, Pago) con `padLine()` que garantiza que 'EFECTIVO', 'TRANSFERENCIA', etc. nunca se corten
- **Estilos inline**: Todo el layout del ticket usa estilos inline en lugar de clases Tailwind para garantizar consistencia absoluta entre pantalla e impresion
- **Clase `.ticket-inner-screen`**: Separacion de estilos decorativos (bordes punteados, border-radius) que se eliminan en @media print

#### 2. Hoja de Estilos @media print (index.css)
- **@page**: Cambiado de `size: 80mm auto` a `size: 58mm auto; margin: 0`
- **#ticket-print-area**: `width: 48mm; max-width: 58mm; margin: 0; padding: 0`
- **Tipografia forzada en print**: `font-family: 'Courier New', Courier, monospace !important` con `-webkit-font-smoothing: none !important` en todos los `<pre>` del ticket
- **Contenedor decorativo**: `.ticket-inner-screen` pierde bordes, border-radius, padding decorativo y box-shadow en impresion
- **Overflow visible**: `overflow: visible !important` en `<pre>` y `<div>` del ticket para evitar recortes
- **Ocultamiento de UI**: `nav, aside, header, footer, .p-dialog-mask, .p-dialog, .p-sidebar, .p-menubar` con `display: none !important`

#### 3. Sincronizacion POS e Historial de Ventas
- **POSPage**: Ya renderizaba TicketPreview fuera de Dialog en `<div className="hidden print:block">` — funciona correctamente con el nuevo componente
- **SalesHistoryPage**: Agregado `<div className="hidden print:block">` con TicketPreview fuera del Dialog de reimprimir, replicando el patron de POSPage para impresion consistente
- **CheckoutModal**: TicketPreview dentro del Dialog es solo para preview en pantalla — la impresion real usa el componente de POSPage
- **TicketConfigPage**: TicketPreview usado solo para vista previa en pantalla — no afectado por cambios de impresion

### Especificaciones Tecnicas del Hardware
| Parametro | Valor |
| :--- | :--- |
| Modelo | 58-VII-U |
| Ancho del papel | 58mm |
| Area de impresion efectiva | 48mm (~384 dots) |
| Caracteres por linea (Courier 12px) | 32 |
| Tipo de impresion | Termica directa |
| Ancho CSS del ticket (@media print) | 48mm |
| Ancho CSS del ticket (pantalla) | 48mm |
| Fuente de impresion | Courier New, Courier, monospace |
| Tamano de fuente | 12px (principal), 10px (detalle) |
| Anti-aliasing | Deshabilitado para nitidez termica |

### Archivos Modificados en esta Fase
**Frontend (modificados):**
- `src/components/pos/TicketPreview.jsx` — Reescrito completo: layout basado en caracteres con `<pre>`, tipografia monoespaciada, estilos inline, LINE_WIDTH=32, funciones padLine/truncate/formatMoney
- `src/index.css` — @media print reescrito para 58mm: @page 58mm, tipografia termica forzada, anti-aliasing deshabilitado, overflow visible
- `src/pages/sales/SalesHistoryPage.jsx` — Agregado div hidden print:block con TicketPreview fuera del Dialog para impresion consistente

---

## 22. Compactacion Vertical de Ticket, Calculo de Cambio & @page Forzado [🟢 Completado]

### Tarea 1: @page Forzado y Compactacion Vertical (CSS @media print) [🟢 Completado]

#### CSS Print (@media print en index.css)
- **@page avanzado**: `size: 58mm auto; margin: 0mm` fuerza ancho y elimina encabezados/pies de pagina del navegador automaticamente
- **html, body reset**: `margin: 0 !important; padding: 0 !important; width: 58mm !important` anula margenes predeterminados del navegador
- **line-height agresivo**: Reducido de `1.3` a `1.0` en `#ticket-print-area` y `1.1` en `<pre>` para compactacion vertical maxima
- **Padding del contenedor**: `.ticket-inner-screen` reducido a `0 0.5mm` en print (eliminando padding vertical)
- **Margins eliminados**: `margin-top: 0 !important; margin-bottom: 0 !important` en todos los `div` del ticket

#### TicketPreview.jsx — Compactacion
- **line-height global**: Reducido de `1.3` a `1.1` en `monoStyle`
- **Padding del contenedor**: Reducido de `4mm 3mm` a `2mm 2mm` en pantalla
- **Margins entre secciones**: Cambiados de `1mm` a `1px` para minima separacion vertical
- **preStyle compartido**: Objeto `preStyle` reutilizado para evitar repeticion de estilos inline

### Tarea 2: Migracion BD — amount_received / amount_change [🟢 Completado]

#### Migracion: 2026_06_24_000001_add_amount_received_change_to_orders
- `amount_received` NUMERIC(12,2) nullable — Dinero entregado por el cliente
- `amount_change` NUMERIC(12,2) nullable — Cambio devuelto por el cajero
- Ambas columnas posicionadas despues de `total` en la tabla `orders`

#### Modelo: Order (Modificado)
- Agregados `amount_received` y `amount_change` a `$fillable`
- Agregados casts `decimal:2` para ambos campos

### Tarea 3: Logica de Cobro con Calculo de Cambio (CheckoutModal) [🟢 Completado]

#### Frontend (CheckoutModal.jsx)
- **Deteccion de efectivo**: Evalua `selectedMethod?.slug === 'cash'` para determinar si el pago es en efectivo
- **InputNumber condicional**: Campo "Dinero Recibido ($) *" se muestra solo cuando el metodo de pago es efectivo (slug `cash`)
- **Calculo reactivo**: `amountChange = Math.max(0, amountReceived - total)` calculado en tiempo real
- **Visualizacion prominente**: Card emerald con texto grande (`text-2xl font-bold`) mostrando "Cambio a devolver"
- **Validacion de insuficiencia**: Si `amountReceived < total`, muestra banner rose con faltante exacto y deshabilita "Confirmar Cobro"
- **Pagos no-efectivo**: `amount_received = total` y `amount_change = 0` asignados automaticamente en el payload
- **previewOrder con useMemo**: Incluye `amount_received` y `amount_change` para renderizado en tiempo real en TicketPreview

#### Backend (OrderController::store + StoreOrderRequest)
- `StoreOrderRequest`: Agregadas reglas `amount_received` (nullable, numeric, min:0, max:9999999999.99) y `amount_change` (mismas reglas)
- `OrderController::store()`: Almacena `$request->amount_received` y `$request->amount_change` en Order::create

### Tarea 4: Inyeccion en Ticket Termico [🟢 Completado]

#### TicketPreview.jsx — Seccion de Cambio
- Detecta `amountReceived != null && amountReceived > 0` para mostrar lineas de cambio
- Agrega separador `--------` seguido de `Recibido:` y `Cambio:` (en negrita) inmediatamente debajo del TOTAL
- Compatible con reimprimir desde SalesHistoryPage (los campos `amount_received`/`amount_change` se cargan desde la API `GET /orders/{id}`)

### Tarea 5: Ruta de Escape en Apertura de Caja [🟢 Ya Existente]
- El boton "Regresar al Panel" con `cursor-pointer` y `navigate('/dashboard')` ya existia en POSPage desde la fase 17. Verificado funcional.

### Archivos Creados en esta Fase
**Backend (nuevos):**
- `database/migrations/2026_06_24_000001_add_amount_received_change_to_orders.php`

### Archivos Modificados en esta Fase
**Backend (modificados):**
- `app/Models/Order.php` — Agregados amount_received, amount_change a fillable y casts
- `app/Http/Controllers/Sales/OrderController.php` — Almacena amount_received y amount_change en store()
- `app/Http/Requests/Order/StoreOrderRequest.php` — Validacion nullable numeric para ambos campos

**Frontend (modificados):**
- `src/components/pos/TicketPreview.jsx` — Compactacion vertical (line-height 1.1, margins 1px), seccion Recibido/Cambio condicional
- `src/components/pos/CheckoutModal.jsx` — InputNumber "Dinero Recibido" condicional por slug cash, calculo reactivo de cambio, validacion de insuficiencia, previewOrder con useMemo
- `src/index.css` — @page forzado 58mm, html/body reset, line-height 1.0/1.1 agresivo, margins eliminados en print

---

## 23. Encuadrado Milimetrico del Ticket 58mm & Flujo de Efectivo en Historial [🟢 Completado]

### Tarea 1: Correccion y Encuadrado del Ticket de 58mm [🟢 Completado]

#### Problema Diagnosticado
El contenedor del ticket usaba `width: 48mm` que a 96 DPI equivale a ~181px, pero 32 caracteres de Courier New a 12px ocupan ~230px. Esto causaba que las lineas divisorias y subtotales desbordaran el recuadro punteado hacia la derecha.

#### Solucion Implementada (TicketPreview.jsx)
- **Contenedor externo**: `box-sizing: border-box; width: 100%; max-width: 240px; margin: 0 auto; padding: 0` — Ancho basado en pixeles que acomoda exactamente 32 caracteres Courier New 12px en pantalla
- **Contenedor interno (.ticket-inner-screen)**: `box-sizing: border-box; padding: 4px; width: 100%; overflow: hidden` — Clip de seguridad en pantalla para evitar desbordamientos
- **Header**: Textos largos (business_name, address, header_message) con `wordWrap: 'break-word'; whiteSpace: 'normal'` para salto de linea limpio
- **Footer/Legend**: Mismas propiedades de wrapping para evitar que textos largos rompan el layout
- **Font sizes ajustados**: Header `12px`, RFC/phone `10px`, address/legend/footer `9px` para maxima compactacion dentro del ancho disponible
- **Print override**: CSS `@media print` restaura `width: 48mm !important` y `overflow: visible !important` para el papel termico real

#### CSS Print Refinado (index.css)
- `#ticket-print-area .ticket-inner-screen`: `overflow: visible !important; width: 100% !important; max-width: none !important` — En impresion no se recorta nada
- `#ticket-print-area pre`: `white-space: pre !important` (en lugar de `nowrap`) para consistencia con pre tags

### Tarea 2: Columnas de Efectivo en Historial de Ventas DataTable [🟢 Completado]

#### SalesHistoryPage — DataTable
- **Columna "Recibido"**: Muestra `amount_received` formateado como moneda ($X.XX). Si es null o 0 (pago no-efectivo), muestra guion `-` en color slate-400
- **Columna "Cambio"**: Muestra `amount_change` formateado como moneda en color emerald-600 (font-semibold). Si es null o 0, muestra guion `-`
- Ambas columnas insertadas entre "Total" y "Estatus" en el DataTable
- Anchos ajustados: Total 100px, Recibido 100px, Cambio 90px, Estatus 100px

### Tarea 3: Detalle del Pedido con Efectivo Recibido/Cambio [🟢 Completado]

#### SalesHistoryPage — Modal Detalle
- Debajo del bloque financiero (Subtotal, IVA, Total), se agregan condicionalmente dos renglones:
  - **Efectivo Recibido**: Formato moneda, separado por border-top slate-200, color slate-600
  - **Cambio Devuelto**: Formato moneda, font-semibold emerald-600
- Solo se muestran cuando `amount_received > 0` (pagos en efectivo)

### Tarea 4: Verificacion Backend API [🟢 Completado]
- `GET /api/orders` (index) y `GET /api/orders/{id}` (show) ya incluyen `amount_received` y `amount_change` en el JSON de respuesta automaticamente via los casts `decimal:2` del modelo Order
- No se requieren cambios adicionales en el backend

### Archivos Modificados en esta Fase
**Frontend (modificados):**
- `src/components/pos/TicketPreview.jsx` — Contenedor box-sizing border-box, max-width 240px, overflow hidden en pantalla, overflow visible en print, font sizes ajustados para encuadrado perfecto
- `src/index.css` — Print: overflow visible forzado en .ticket-inner-screen, white-space pre en pre tags, width/max-width overrides
- `src/pages/sales/SalesHistoryPage.jsx` — Columnas Recibido/Cambio en DataTable, seccion Efectivo Recibido/Cambio Devuelto en modal detalle

---

## 24. Optimizacion Monocromatica, Login Premium, Desglose por Producto & Flujo Efectivo [🟢 Completado]

### Tarea 1: Optimizacion Monocromatica Estricta para Ticket 58mm (@media print) [🟢 Completado]

#### Tipografia Fina y Nitida
- `font-weight` reducido de `600` a `400` en `monoStyle` global del TicketPreview para evitar sangrado por exceso de calor en papel termico
- Encabezados (business_name, PRODUCTO/IMPORTE, TOTAL, Cambio) reducidos de `700` a `500` para diferenciacion sutil sin empaste
- CSS `@media print` fuerza `font-weight: 400 !important` en `#ticket-print-area` y `#ticket-print-area pre`

#### Eliminacion Total de Grises (Negro Puro)
- Removidos todos los colores intermedios del ticket: `#666` (detalle items), `#059669` (promociones), `#999` (footer version) → todos a `#000`
- CSS `@media print` aplica regla universal: `#ticket-print-area, #ticket-print-area * { color: #000000 !important; text-shadow: none !important }` para negro solido inmutable
- Las lineas divisorias de 32 caracteres (`=` y `-`) heredan el mismo color negro forzado

### Tarea 2: Correccion de Alineacion y Rediseno Estetico del Login [🟢 Completado]

#### Alineacion de Inputs
- Componente `<Password>` corregido con `inputClassName="w-full !w-full"` y `pt` exhaustivo: `root`, `input`, e `iconField` con `{ className: 'w-full', style: { width: '100%' } }` para forzar ancho identico al InputText de email

#### Fondo Ejecutivo Premium
- Fondo migrado de `bg-slate-50` plano a `bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900` — degradado geometrico oscuro enterprise
- Tarjeta del formulario: `bg-white/95 shadow-2xl shadow-black/20 ring-1 ring-white/10 backdrop-blur-sm` para efecto cristal sobre fondo oscuro
- Footer actualizado a `text-slate-400/70` para contraste sobre fondo oscuro

### Tarea 3: Ampliacion del Modal "Resumen del Dia" (Desglose por Producto) [🟢 Completado]

#### Backend (DailySummaryController)
- Nueva consulta de agregacion: `order_items` JOIN `orders` LEFT JOIN `products`, agrupado por `products.name`, filtrado por `status=completed` y rango del dia
- Retorna arreglo `product_breakdown` ordenado por `total_revenue` descendente con campos: `product_name`, `quantity_sold`, `total_revenue`

#### Frontend (AppHeader — Dialog Resumen)
- Dialog expandido de `max-w-md` a `style={{ width: '50vw' }}` con `max-w-4xl` para vista amplia
- Importado `DataTable` y `Column` de PrimeReact
- Nueva seccion "Desglose de Ventas por Articulo" con DataTable compacto: columna Producto, Piezas (badge indigo), Ingreso (font-semibold)
- Scroll vertical con `scrollHeight="250px"` para listas largas
- Renderizado condicional: solo se muestra si `product_breakdown.length > 0`

### Tarea 4: Exposicion de Flujos de Efectivo en Historial y Detalle [🟢 Ya Existente - Verificado]
- Las columnas `Recibido` y `Cambio` ya existian en el DataTable de SalesHistoryPage desde la fase 23
- La seccion `Efectivo Recibido` / `Cambio Devuelto` ya existia en el modal de detalle del pedido
- No se requirieron cambios adicionales

### Archivos Modificados en esta Fase
**Backend (modificados):**
- `app/Http/Controllers/Sales/DailySummaryController.php` — Agregada consulta product_breakdown con agregacion por producto

**Frontend (modificados):**
- `src/index.css` — @media print: font-weight 400, color #000000 universal, text-shadow none en todo #ticket-print-area
- `src/components/pos/TicketPreview.jsx` — font-weight 400/500 (era 600/700), colores grises eliminados (#666, #059669, #999 → #000)
- `src/pages/auth/LoginPage.jsx` — Fondo gradient slate-900/indigo-950, tarjeta frosted glass, Password pt con iconField width 100%
- `src/components/layout/AppHeader.jsx` — Dialog 50vw/max-w-4xl, DataTable product_breakdown con columnas Producto/Piezas/Ingreso

---

## 25. Motor de Impresión Directa ESC/POS (Ticketera Térmica 58mm) [🟢 COMPLETADO Y OPERATIVO]

### Arquitectura Modular SOLID

#### 1. TicketDTO (Data Transfer Object Inmutable)
- **Ubicación**: `App\DTOs\TicketDTO`
- **Clase auxiliar**: `App\DTOs\TicketItemDTO`
- Propiedades públicas tipadas `readonly`: empresa, RFC, dirección, teléfono, mensajes cabecera/pie, folio, fecha/hora, método de pago, operador, colección de items (`TicketItemDTO[]`), subtotal neto, IVA monto, IVA label, total público, recibido, cambio, leyenda personalizada, versión, QR content
- Inmutable por diseño (`final readonly class`)

#### 2. TicketBuilder (Patrón Constructor + Formateo Estadístico)
- **Ubicación**: `App\Builders\TicketBuilder`
- **Constante**: `LINE_WIDTH = 32` caracteres (restricción física de 384 puntos / Font A)
- **Métodos de formateo**:
  - `padLine(left, right)` — Alinea texto izquierda-derecha a exactamente 32 caracteres, trunca con `..` si excede
  - `formatProductLine(TicketItemDTO)` — Formatea línea `Cant x Producto... $Total` en exactamente 32 chars
  - `separator(char)` — Genera línea divisoria de exactamente 32 caracteres
  - `centerText(text)` — Centra texto dentro de 32 caracteres
  - `wrapText(text, maxWidth)` — Divide texto largo en líneas que respetan el ancho máximo
  - `formatMoney(float)` — Formato moneda MXN con separador de miles
  - `buildTextLines(TicketDTO)` — Genera representación completa del ticket como array de líneas <= 32 chars
- **Mapeo de datos**: Recibe modelos `Order` + `TicketConfig`, lee `tax_rate` de `GlobalSetting`, construye `TicketDTO` con cálculo inverso de IVA

#### 3. PrinterService (Driver de Hardware ESC/POS)
- **Ubicación**: `App\Services\PrinterService`
- **Dependencia**: `mike42/escpos-php` ^4.0
- **Modos de conexión** (configurables via `.env` con `PRINTER_CONNECTION_TYPE`):
  - `network` → `NetworkPrintConnector` — Para impresoras en red (default: 192.168.1.100:9100)
  - `linux_file` | `usb` | `file` → `FilePrintConnector` — Para conexión directa USB (/dev/usb/lp0)
  - `windows_share` | `smb` | `windows` → `SambaAuthPrintConnector` — Conector custom con autenticación SMB via `smbclient` (lee `PRINTER_SMB_HOST`, `PRINTER_SMB_SHARE`, `PRINTER_SMB_USER`, `PRINTER_SMB_PASS`) [🟢 COMPLETADO Y OPERATIVO]

#### 3.1 SambaAuthPrintConnector (Conector Custom con Autenticación)
- **Ubicación**: `App\Services\PrintConnectors\SambaAuthPrintConnector`
- **Interfaz**: Implementa `Mike42\Escpos\PrintConnectors\PrintConnector`
- **Razón de existencia**: El `WindowsPrintConnector` nativo de mike42/escpos-php valida URIs con regex estricta que rechaza credenciales embebidas (`:` y `@`). Este conector bypasea esa limitación invocando `smbclient` directamente.
- **Flujo**: Acumula datos ESC/POS en buffer interno → `finalize()` abre proceso `smbclient` con `popen()` → escribe buffer al pipe → valida código de salida
- **Autenticación**: Si `PRINTER_SMB_USER` y `PRINTER_SMB_PASS` están configurados, pasa `-U user%pass`. Si están vacíos, usa `-N` (anónimo).
- **Seguridad**: Todas las entradas escapadas con `escapeshellarg()`. Protocolo forzado a SMB2 via `-m SMB2`.
- **Comandos binarios ESC/POS**:
  - CodePage CP858 para caracteres españoles (ñ, acentos)
  - `setJustification(JUSTIFY_CENTER)` para encabezados
  - `setEmphasis(true)` para TOTAL y Cambio
  - Líneas divisorias de 32 caracteres exactos (`=` y `-`)
  - QR Code nativo al final (`qrCode()` con nivel M, tamaño 5)
  - Corte físico de papel (`cut()`)
  - Encoding UTF-8 → CP858 via `iconv()` con TRANSLIT

#### 4. PrintTicketController (Punto de Entrada API)
- **Ubicación**: `App\Http\Controllers\Admin\PrintTicketController`
- **Endpoint**: `POST /api/orders/{order}/print` (Protegido por Sanctum + user.active)
- **Lógica**: Invokable controller, carga relaciones de la orden, construye DTO via `TicketBuilder`, despacha al `PrinterService` en bloque try-catch
- **Respuestas JSON homogéneas**:
  - `status: success` — Ticket enviado correctamente
  - `ERR_PRINT_NO_TICKET_CONFIG` (422) — Orden sin configuración de ticket
  - `ERR_PRINT_HARDWARE_FAILURE` (503) — Impresora desconectada/error de hardware (incluye detalle en modo debug)

#### 5. Configuración de Entorno e Infraestructura
- **Config file**: `config/printer.php` — Centraliza variables de conexión
- **Variables .env**:
  - `PRINTER_CONNECTION_TYPE=network` (opciones: network, linux_file/usb/file, windows_share/smb/windows)
  - `PRINTER_IP_ADDRESS=192.168.1.100`
  - `PRINTER_PORT=9100`
  - `PRINTER_FILE_PATH=/dev/usb/lp0`
  - `PRINTER_SMB_HOST=host.docker.internal` (host Windows desde Docker)
  - `PRINTER_SMB_SHARE=cronos_printer` (nombre del recurso compartido)
  - `PRINTER_SMB_USER=` (usuario Windows, vacío para anónimo)
  - `PRINTER_SMB_PASS=` (contraseña Windows, vacío para anónimo)
- **Docker**: Variables inyectadas en `docker-compose.yml` bajo el servicio backend

#### 6. Integración Frontend (React + Axios)
- **CheckoutModal**: Tras confirmar cobro exitoso, dispara `axios.post(/api/orders/${orderId}/print)` de forma asíncrona. Muestra toast de éxito o warning si la impresora no responde. Mantiene fallback a `window.print()` como respaldo.
- **SalesHistoryPage**: Botón dual de reimpresión:
  - "Ticketera" (emerald, pi-server) — Envía a impresora ESC/POS directamente via API
  - "Navegador" (indigo, pi-print) — Impresión CSS tradicional via `window.print()`
  - Loading state con spinner durante impresión directa

#### 7. Prueba Unitaria (PHPUnit)
- **Archivo**: `tests/Unit/TicketBuilderTest.php`
- **10 tests**:
  - `test_pad_line_produces_exactly_32_characters` — Verifica alineación exacta
  - `test_pad_line_truncates_long_left_text` — Verifica truncamiento con `..`
  - `test_separator_is_exactly_32_characters` — Verifica separadores `-` y `=`
  - `test_format_product_line_short_name` — Formato de producto corto
  - `test_format_product_line_long_name_truncated` — Truncamiento de nombre largo
  - `test_inverse_tax_calculation` — Fórmula inversa IVA ($90 → $77.59 + $12.41)
  - `test_inverse_tax_with_multiple_items` — IVA inverso con múltiples items
  - `test_build_text_lines_all_within_32_chars` — Certificación integral: ticket completo con todas las líneas <= 32 chars
  - `test_center_text` — Centrado de texto
  - `test_wrap_text_splits_long_strings` — División de texto largo
  - `test_format_money` — Formato moneda MXN

### Dependencia Registrada
- `mike42/escpos-php` ^4.0 agregado a `composer.json` en `require`

### Archivos Creados en esta Fase
**Backend (nuevos):**
- `app/DTOs/TicketDTO.php` — DTO inmutable del ticket
- `app/DTOs/TicketItemDTO.php` — DTO inmutable de item de ticket
- `app/Builders/TicketBuilder.php` — Constructor y formateador de ticket (32 chars)
- `app/Services/PrinterService.php` — Driver ESC/POS con soporte multi-conector
- `app/Http/Controllers/Admin/PrintTicketController.php` — Endpoint de impresión
- `config/printer.php` — Configuración centralizada de impresora
- `tests/Unit/TicketBuilderTest.php` — 10 tests unitarios

**Backend (modificados):**
- `composer.json` — Agregado `mike42/escpos-php` ^4.0
- `routes/api.php` — Ruta POST /api/orders/{order}/print
- `.env.example` — Variables PRINTER_* para configuración de impresora

**Frontend (modificados):**
- `src/components/pos/CheckoutModal.jsx` — Disparo asíncrono de impresión ESC/POS post-cobro
- `src/pages/sales/SalesHistoryPage.jsx` — Botón dual Ticketera/Navegador para reimpresión

**Infraestructura (modificados):**
- `docker-compose.yml` — Variables de entorno PRINTER_* en servicio backend

---

## 26. Robusteces Operativas: Turno, Excel, SKU, Welcome Mail & Perfil Avanzado [🟢 Completado]

### Tarea 1: Tarjeta de Estado de Turno de Caja en el POS [🟢 Completado]
- Card compacta en la parte superior de la interfaz POS (post-apertura de caja)
- Datos en tiempo real: nombre del cajero, fecha local formateada, reloj segundo a segundo via `useEffect`+`setInterval`
- Metadatos de control: Folio de turno (8 chars UUID uppercase), fondo inicial formateado como moneda MXN
- Backend: `CashRegisterController::active()` ahora incluye `with('user:id,name')` para eager loading del operador

### Tarea 2: Migracion de CSV a Excel Estructurado (.xlsx) [🟢 Completado]
- Eliminada generacion CSV rustica del `SalesExportController`
- Integrado `PhpOffice\PhpSpreadsheet` (ya existente en `composer.json`) para despachar `.xlsx`
- Cabecera institucional estilizada: "CRONOS POS - HISTORIAL DE TRANSACCIONES" con fondo indigo, sucursal actual, rango de fechas, conteo de registros
- Columnas con texto en negrita (fondo slate oscuro), anchos auto-ajustados, formato moneda $#,##0.00, zebra striping
- Frontend: Boton "Exportar CSV" renombrado a "Exportar Excel", descarga como `.xlsx`

### Tarea 3: Homologacion de SKU con Prefijo AMR- e Inmutabilidad [🟢 Completado]
- **Formulario de Alta**: Input de SKU con prefijo visual fijo "AMR-" (addon izquierdo) + conversion automatica a MAYUSCULAS. El estado React siempre almacena "AMR-" + valor capturado
- **Formulario de Edicion**: Campo SKU completamente `readOnly` con fondo `bg-slate-50`, leyenda "El SKU no es editable una vez dado de alta"
- **Validacion**: Frontend valida que el SKU inicie con "AMR-" antes de enviar. Backend recibe el SKU completo
- **Proteccion submit**: En modo edicion, el SKU se excluye del payload enviado al backend

### Tarea 4: Registro de Usuarios con Contrasena de Sistema y Welcome Mail [🟢 Completado]
- **Frontend**: Eliminados campos "Contrasena" y "Confirmar Contrasena" del modal de creacion de usuario. Banner informativo azul indica que la contrasena sera generada y enviada por correo
- **Backend**: `UserController::store()` genera `Str::random(12)` como contrasena temporal, almacena hash en PostgreSQL, registra `password_restored_at` con timestamp actual
- **StoreUserRequest**: Removida regla de validacion `password` (ya no se envia desde el frontend)
- **Mailable**: `UserWelcomeMail` (ShouldQueue) con vista Blade `mail.user-welcome` que extiende layout corporativo. Muestra: saludo personalizado, correo de acceso, contrasena temporal en monospace, boton CTA "Iniciar Sesion", banner de advertencia para cambio de contrasena
- **Preview**: Registrado en `MailPreviewController` (slug `welcome`) y en `routes/web.php` bajo `/mail-preview/welcome`

### Tarea 5: Telefonia Internacional y Generador de Contrasenas en Perfil [🟢 Completado]
- **Campo Telefono con Lada**: Dropdown PrimeReact para seleccionar pais (Mexico +52, USA +1) + InputMask con mascara exacta `(999) 999-9999`. Cambio de pais resetea el campo de telefono
- **Migracion**: Columna `phone_country_code` (varchar 5, nullable) agregada a tabla `users`
- **Backend**: `ProfileController::update()` acepta y almacena `phone_country_code`. `show()` retorna el campo en la respuesta
- **Generador de Contrasenas**: Panel interactivo dentro de la seccion de seguridad con Slider (8-32 chars), Checkboxes (mayusculas/minusculas/numeros/simbolos), generacion via `crypto.getRandomValues()`, vista previa en monospace, boton "Aplicar Contrasena" que inyecta en ambos campos del formulario
- **Forzar Logout**: Cambio de contrasena exitoso revoca token local, limpia sesion y redirige a `/login` con toast informativo via Sonner

### Archivos Creados en esta Fase
**Backend (nuevos):**
- `app/Mail/UserWelcomeMail.php` — Mailable ShouldQueue con contrasena temporal
- `resources/views/mail/user-welcome.blade.php` — Vista Blade ejecutiva con layout corporativo
- `database/migrations/2026_06_27_000001_add_phone_country_code_to_users.php` — Columna phone_country_code en users

### Archivos Modificados en esta Fase
**Backend (modificados):**
- `app/Http/Controllers/Finance/CashRegisterController.php` — Eager load user en active()
- `app/Http/Controllers/Sales/SalesExportController.php` — Reescrito: PhpSpreadsheet .xlsx con cabecera institucional
- `app/Http/Controllers/Admin/UserController.php` — Auto-genera contrasena con Str::random(12), envia UserWelcomeMail
- `app/Http/Controllers/Admin/MailPreviewController.php` — Agregado slug 'welcome' con preview de UserWelcomeMail
- `app/Http/Controllers/Profile/ProfileController.php` — Acepta phone_country_code en update(), retorna en show()
- `app/Http/Requests/User/StoreUserRequest.php` — Removida regla 'password'
- `app/Models/User.php` — Agregado phone_country_code a fillable
- `routes/web.php` — Ruta /mail-preview/welcome

**Frontend (modificados):**
- `src/pages/pos/POSPage.jsx` — Tarjeta de estado de turno con reloj en tiempo real, folio, fondo inicial
- `src/pages/sales/SalesHistoryPage.jsx` — Boton "Exportar Excel" (.xlsx), descarga con nombre .xlsx
- `src/pages/catalog/ProductFormPage.jsx` — Prefijo AMR- forzado en alta, SKU readOnly en edicion, exclusion de SKU en payload de update
- `src/pages/admin/UsersPage.jsx` — Eliminados campos contrasena del modal, banner informativo de contrasena auto-generada
- `src/pages/profile/ProfilePage.jsx` — Reescrito: Dropdown lada internacional + InputMask, generador de contrasenas con Slider/Checkboxes/crypto, logout forzado post-cambio contrasena

---

## 27. Modulo de Descuentos y Cupones en Checkout [🟢 COMPLETADO]

### Arquitectura del Sistema de Descuentos

#### Tipos de Descuento Soportados
1. **Descuento Directo Fijo** — El cajero ingresa un monto fijo en pesos (ej: $50.00) que se resta del total bruto
2. **Descuento Directo Porcentual** — El cajero ingresa un porcentaje (ej: 10%) que se aplica sobre el total bruto
3. **Cupon/Promocion** — El cajero busca una promocion activa via autocomplete predictivo; el tipo y valor se heredan de la promocion seleccionada

#### Formula Matematica (Tax-Inclusive con Descuento)
1. Se calcula `totalGross` = suma de (precio_unitario × cantidad) por cada item
2. Se aplica descuento global: `discountTotal = valor_fijo` o `totalGross × porcentaje / 100`
3. `totalAfterDiscount = totalGross - discountTotal`
4. Desglose fiscal inverso: `subtotal = totalAfterDiscount / (1 + taxRate)`, `ivaTotal = totalAfterDiscount - subtotal`
5. Distribucion proporcional del descuento en cada `order_item`: `itemDiscountShare = discountTotal × (itemFinal / totalGross)`

### Migracion: 2026_06_27_000002_add_discount_columns_to_orders
- ENUM nativo PostgreSQL `discount_type` con valores: `fixed`, `percentage`, `none`
- Columnas agregadas a `orders`:
  - `promotion_id` UUID FK nullable → `promotions(id) ON DELETE SET NULL`
  - `discount_value` NUMERIC(12,2) default 0.00
  - `discount_total` NUMERIC(12,2) default 0.00
  - `discount_type` discount_type NOT NULL DEFAULT 'none'

### Backend

#### Modelo: Order (Modificado)
- Agregados a `$fillable`: `promotion_id`, `discount_type`, `discount_value`, `discount_total`
- Casts `decimal:2` para `discount_value` y `discount_total`
- Nueva relacion: `promotion()` BelongsTo → Promotion

#### PromotionSearchController (Nuevo — Invokable)
- **Endpoint**: `GET /api/promotions/search?q=texto`
- Filtra promociones por: `is_active = true`, `start_date <= now`, `end_date >= now`, `name ILIKE %query%`
- Retorna max 10 resultados con campos: id, name, type, value, start_date, end_date

#### StoreOrderRequest (Modificado)
- Nuevas reglas: `discount_type` (nullable, in:fixed,percentage,none), `discount_value` (nullable, numeric, min:0), `promotion_id` (nullable, uuid, exists:promotions,id)

#### OrderController::store() (Modificado)
- Primer paso: calcula `totalGross` sin descuento (suma de precios × cantidades)
- Segundo paso: aplica descuento global segun tipo (fixed o percentage)
- Tercer paso: distribuye descuento proporcionalmente entre items para coherencia historica
- Cada `order_item` recibe: `discount_amount_at_sale` = su parte proporcional del descuento global
- `expected_closing_balance` del cash register se incrementa por `totalAfterDiscount`
- Almacena `promotion_id`, `discount_type`, `discount_value`, `discount_total` en la orden
- Relaciones `index()` y `show()` cargan `promotion` con eager loading

#### TicketDTO (Modificado)
- Nueva propiedad: `public ?string $descuentoTotal`

#### TicketBuilder (Modificado)
- `build()` calcula `$descuentoTotal` desde `$order->discount_total`
- `buildTextLines()` inserta linea `Descuento: -$X.XX` antes del subtotal cuando hay descuento

#### PrinterService (Modificado)
- `printTotals()` imprime linea de descuento con enfasis (bold) antes del subtotal cuando `$dto->descuentoTotal` esta presente

#### AnalyticsController (Modificado)
- `financialSummary()` incluye `COALESCE(SUM(discount_total), 0) as total_discounts` en la consulta de ventas
- Retorna `total_discounts` en el JSON de respuesta

#### DailySummaryController (Modificado)
- Agregado `COALESCE(SUM(discount_total), 0) as total_discounts` a la consulta de resumen diario

#### SalesExportController (Modificado)
- Agregada columna "Descuento" entre "Metodo de Pago" y "Subtotal" en el Excel .xlsx
- Rango de columnas expandido de A-H a A-I
- Carga relacion `promotion` en la consulta

#### Ruta API (Modificada)
- `GET /api/promotions/search` registrada ANTES de las rutas resource de promociones para evitar conflicto con {id}

### Frontend

#### CheckoutModal.jsx (Reescrito)
- Nuevos imports: Checkbox, RadioButton, AutoComplete de PrimeReact
- Estados: `applyDiscount`, `discountMode` (direct|coupon), `discountSubType` (fixed|percentage), `discountValue`, `couponQuery`, `couponSuggestions`, `selectedCoupon`
- `computedDiscount` (useMemo): calcula descuento monetario real segun modo y tipo
- `searchCoupons()`: llama `GET /api/promotions/search?q=` para autocomplete
- UI: Checkbox "Aplicar descuento o cupon?" → panel condicional con RadioButtons (Directo/Cupon), InputNumber+Dropdown para directo, AutoComplete para cupon
- Totals breakdown: Subtotal (bruto), Descuento (amber, condicional), Subtotal Neto, IVA, Total
- Payload incluye `discount_type`, `discount_value`, `promotion_id`
- `previewOrder` incluye `discount_total` para TicketPreview en tiempo real

#### TicketPreview.jsx (Modificado)
- Extrae `discountTotal` de los datos de la orden
- Muestra linea `Descuento: -$X.XX` en seccion de totales cuando `discountTotal > 0`

#### SalesHistoryPage.jsx (Modificado)
- Columna "Descuento" en DataTable con estilo amber cuando `discount_total > 0`
- Modal de detalle muestra info de descuento: nombre de promocion (si aplica), tipo y valor, monto total descontado

#### FinanceDashboardPage.jsx (Modificado)
- KPI "Ingreso Neto" muestra total de descuentos en texto amber cuando `total_discounts > 0`

### Integridad de Modulos Existentes (Verificada)
- **CashRegisterClosingController**: No requiere cambios — usa `SUM(total)` que ya refleja montos post-descuento; `expected_closing_balance` se incrementa por el total con descuento aplicado
- **DashboardController**: No requiere cambios — usa `SUM(total)` que incluye descuentos naturalmente
- **Cierre de Caja (Blind Closing)**: Integridad preservada — la formula `Fondo + Ventas - Retiros` opera sobre totales post-descuento

### Archivos Creados en esta Fase
**Backend (nuevos):**
- `database/migrations/2026_06_27_000002_add_discount_columns_to_orders.php`
- `app/Http/Controllers/Promotion/PromotionSearchController.php`

### Archivos Modificados en esta Fase
**Backend (modificados):**
- `app/Models/Order.php` — promotion_id, discount_type, discount_value, discount_total en fillable/casts, relacion promotion()
- `app/Http/Controllers/Sales/OrderController.php` — Logica de descuento global con distribucion proporcional, eager load promotion
- `app/Http/Requests/Order/StoreOrderRequest.php` — Reglas discount_type, discount_value, promotion_id
- `app/DTOs/TicketDTO.php` — Propiedad descuentoTotal
- `app/Builders/TicketBuilder.php` — Linea de descuento en buildTextLines()
- `app/Services/PrinterService.php` — Impresion de descuento con enfasis
- `app/Http/Controllers/Finance/AnalyticsController.php` — total_discounts en financialSummary
- `app/Http/Controllers/Sales/DailySummaryController.php` — total_discounts en resumen diario
- `app/Http/Controllers/Sales/SalesExportController.php` — Columna Descuento en Excel, rango A-I
- `routes/api.php` — Ruta GET /api/promotions/search

**Frontend (modificados):**
- `src/components/pos/CheckoutModal.jsx` — UI completa de descuentos/cupones con autocomplete, calculo reactivo, payload extendido
- `src/components/pos/TicketPreview.jsx` — Linea de descuento en totales
- `src/pages/sales/SalesHistoryPage.jsx` — Columna y detalle de descuento
- `src/pages/finance/FinanceDashboardPage.jsx` — KPI con total_discounts

---

## 28. Infraestructura de Despliegue en Produccion (DigitalOcean) [🟢 COMPLETADA Y LISTA PARA PRODUCCION]

### Arquitectura Fisica de Produccion
- **Droplet Optimizado** (Ubuntu 24.04 LTS, 4GB RAM / 2 vCPUs): API Laravel, Reverb WebSockets, Redis Queue Workers, SPA React
- **Managed PostgreSQL** (DigitalOcean): Cluster dedicado con Trusted Sources (solo acepta trafico del Droplet)
- **Nginx Host**: Proxy inverso con terminacion SSL, HTTPS, WebSockets seguros (WSS)
- **Certbot / Let's Encrypt**: Certificados SSL gratuitos con renovacion automatica

### Contenedores de Produccion (docker-compose.prod.yml)
| Servicio | Imagen | Puerto Interno | Descripcion |
| :--- | :--- | :--- | :--- |
| backend | PHP 8.3-FPM Alpine (multi-stage) | 8000, 8080 | Laravel API + Reverb + Queue Worker |
| frontend | Nginx Alpine (multi-stage) | 80 | SPA React compilada (Vite build) |
| redis | Redis 7 Alpine | 6379 | Cache + Colas (appendonly, 256MB limit) |

### Dockerfiles de Produccion (Multi-Stage Build)

#### Backend (backend/Dockerfile.prod)
- Stage 1 (vendor): Composer 2 — `composer install --no-dev --optimize-autoloader`
- Stage 2 (runtime): PHP 8.3-FPM Alpine — extensiones: pdo_pgsql, bcmath, opcache, redis, intl, gd, pcntl
- OPcache configurado con `validate_timestamps=0`, JIT habilitado (128M buffer), 256MB memoria
- Ejecuta como usuario `www-data` (no root)

#### Frontend (frontend/Dockerfile.prod)
- Stage 1 (builder): Node 22 Alpine — `npm ci` + `npm run build` (Vite + Tailwind v4)
- Stage 2 (serve): Nginx Alpine ultra-ligero — sirve archivos estaticos de `/dist`
- Cache de assets estaticos con `expires 1y` + `Cache-Control: public, immutable`
- Gzip habilitado para JS/CSS/JSON/SVG

### OPcache de Alto Rendimiento (backend/opcache.ini)
- `opcache.enable=1` + `opcache.enable_cli=1`
- `opcache.memory_consumption=256` MB
- `opcache.interned_strings_buffer=64` MB
- `opcache.max_accelerated_files=20000`
- `opcache.validate_timestamps=0` (produccion: no verifica cambios en disco)
- `opcache.jit=1255` + `opcache.jit_buffer_size=128M` (JIT completo)

### Proxy Inverso Nginx (infrastructure/cronos-pos.conf)
| Bloque | Ruta | Destino | Cabeceras Especiales |
| :--- | :--- | :--- | :--- |
| API Laravel | `/api/*` | 127.0.0.1:8000 | X-Real-IP, X-Forwarded-For, X-Forwarded-Proto |
| Sanctum | `/sanctum/*` | 127.0.0.1:8000 | X-Real-IP, X-Forwarded-For |
| Storage | `/storage/*` | 127.0.0.1:8000 | Cache 7d |
| WebSockets | `/app/*`, `/apps/*` | 127.0.0.1:8080 | **Upgrade, Connection "Upgrade"**, timeout 86400s |
| SPA React | `/*` | 127.0.0.1:3000 | X-Forwarded-Proto |

Cabeceras de seguridad globales: X-Frame-Options SAMEORIGIN, X-Content-Type-Options nosniff, HSTS 1 year, Referrer-Policy strict-origin-when-cross-origin

### Seguridad de Base de Datos (Managed PostgreSQL)
- **Trusted Sources**: Cluster configurado para RECHAZAR cualquier conexion excepto la IP publica del Droplet
- **SSL obligatorio**: `DB_SSLMODE=require` en `.env.production` cifra todas las conexiones Droplet ↔ PostgreSQL
- **Puerto no estandar**: 25060 (asignado por DigitalOcean, no accesible externamente)
- **Usuario dedicado**: `cronos_app` (no usar `doadmin` en produccion)

### Script de Despliegue Automatizado (deploy.sh)
Secuencia automatizada para actualizaciones zero-downtime:
1. `git pull origin main --ff-only`
2. `docker compose up --build -d` (rebuild + restart en background)
3. Espera healthcheck del backend (max 30 intentos, 2s cada uno)
4. `php artisan migrate --force` (migraciones de produccion)
5. Cache rebuild: `config:cache`, `route:cache`, `event:cache`, `view:cache`
6. `php artisan queue:restart` (reinicio seguro de workers Redis)
7. `docker image prune -f` (limpieza de imagenes huerfanas)

### Certificados SSL (Certbot / Let's Encrypt)
```bash
sudo certbot --nginx -d dominio.com -d www.dominio.com \
    --non-interactive --agree-tos --email admin@dominio.com --redirect
sudo certbot renew --dry-run  # Verificar renovacion automatica
```
Timer de systemd instalado automaticamente para renovacion cada 60 dias.

### Cron Jobs del Servidor (Laravel Scheduler)
```crontab
* * * * * cd /opt/cronos-pos && docker compose -f docker-compose.prod.yml exec -T backend php artisan schedule:run >> /var/log/cronos-schedule.log 2>&1
```
Ejecuta `schedule:run` cada minuto dentro del contenedor del backend para tareas programadas de Laravel.

### Mapa de Puertos (Verificacion de No-Conflictos)
| Puerto | Servicio | Exposicion |
| :--- | :--- | :--- |
| 80 | Nginx HTTP (redirect → 443) | Publico (UFW) |
| 443 | Nginx HTTPS (proxy) | Publico (UFW) |
| 3000 | Frontend container | Solo 127.0.0.1 |
| 8000 | Backend container | Solo 127.0.0.1 |
| 8080 | Reverb WebSocket | Solo 127.0.0.1 |
| 6379 | Redis | Solo red Docker interna |
| 25060 | PostgreSQL Managed | Solo IP Droplet (Trusted Sources) |

### Archivos Creados en esta Fase
**Raiz (nuevos):**
- `docker-compose.prod.yml` — Compose de produccion (3 servicios: backend, frontend, redis)
- `.env.production.example` — Template de variables de produccion
- `deploy.sh` — Script de despliegue automatizado
- `DEPLOY_PRODUCTION.md` — Manual paso a paso de aprovisionamiento

**Backend (nuevos):**
- `backend/Dockerfile.prod` — Multi-stage build PHP 8.3-FPM Alpine
- `backend/opcache.ini` — Directivas OPcache de alto rendimiento + JIT
- `backend/docker-entrypoint.prod.sh` — Entrypoint de produccion (caches + workers)

**Frontend (nuevos):**
- `frontend/Dockerfile.prod` — Multi-stage build Node 22 + Nginx Alpine
- `frontend/nginx.conf` — Configuracion interna del contenedor frontend (SPA routing)

**Infraestructura (nuevos):**
- `infrastructure/cronos-pos.conf` — Nginx proxy inverso del host con HTTPS + WSS

---

## 29. Reparacion del Build de Produccion (COPY Context Alignment) [🟢 Completado]

### Problema Diagnosticado
El `docker-compose.prod.yml` define `context: .` (raiz del monorepo), pero el Stage 1 del `backend/Dockerfile.prod` usaba `COPY composer.json composer.lock ./` sin prefijo, buscando en la raiz donde no existen. Error: `"/composer.lock": not found` en la capa 11.

### Correccion Aplicada

#### backend/Dockerfile.prod (Stage 1)
- Linea 11 corregida de `COPY composer.json composer.lock ./` a `COPY backend/composer.json backend/composer.lock ./`
- Todas las instrucciones COPY ya usaban prefijo `backend/` (lineas 49, 53, 60) excepto la del Stage 1

#### .dockerignore (Raiz — Nuevo)
- Creado archivo `.dockerignore` en la raiz del monorepo (el contexto de build real)
- Excluye: `.git`, IDE configs, documentacion, `backend/vendor/`, `frontend/node_modules/`, `frontend/dist/`, archivos `.env`, tests
- Verificado que NO excluye: `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, codigo fuente, configs de produccion

### Validacion de Integridad
- 11 instrucciones COPY verificadas contra el filesystem: todas resuelven correctamente
- 19 archivos criticos de build verificados contra `.dockerignore`: ninguno excluido
- Ambos Dockerfiles (backend + frontend) alineados con `context: .` del compose

## 29. SambaAuthPrintConnector — Conector Custom con Autenticación SMB [🟢 COMPLETADO Y OPERATIVO]

### Problema Original
El `WindowsPrintConnector` nativo de `mike42/escpos-php` valida URIs con regex estricta que rechaza credenciales embebidas (caracteres `:` y `@`). Al requerir autenticación local de Windows desde el contenedor Docker, era imposible pasar usuario/contraseña en la URI SMB.

### Solución Implementada
Conector personalizado `SambaAuthPrintConnector` que implementa la interfaz `PrintConnector` de la librería y despacha los datos ESC/POS via `smbclient` con credenciales separadas.

### Archivos Creados
- `backend/app/Services/PrintConnectors/SambaAuthPrintConnector.php` — Conector custom que acumula buffer ESC/POS y lo envía via `smbclient` con autenticación opcional

### Archivos Modificados
- `backend/app/Services/PrinterService.php` — Factory refactorizado: el caso `windows_share/smb/windows` ahora instancia `SambaAuthPrintConnector` en lugar de `WindowsPrintConnector`. Return type actualizado a `PrintConnector` (interfaz).
- `backend/config/printer.php` — Variables SMB granulares: `smb_host`, `smb_share`, `smb_user`, `smb_pass` (reemplaza `windows_share` monolítica)
- `backend/.env.example` — Variables actualizadas: `PRINTER_SMB_HOST`, `PRINTER_SMB_SHARE`, `PRINTER_SMB_USER`, `PRINTER_SMB_PASS`

### Variables de Entorno Soportadas
| Variable | Valores Válidos | Conector |
| :--- | :--- | :--- |
| `PRINTER_CONNECTION_TYPE` | `windows_share`, `smb`, `windows` | `SambaAuthPrintConnector` (lee `PRINTER_SMB_HOST` + `PRINTER_SMB_SHARE` + `PRINTER_SMB_USER` + `PRINTER_SMB_PASS`) |
| `PRINTER_CONNECTION_TYPE` | `linux_file`, `usb`, `file` | `FilePrintConnector` (lee `PRINTER_FILE_PATH`) |
| `PRINTER_CONNECTION_TYPE` | `network` (default) | `NetworkPrintConnector` (lee `PRINTER_IP_ADDRESS` + `PRINTER_PORT`) |

### Nota de Despliegue
El paquete `samba-client` esta instalado de forma permanente en ambos Dockerfiles (Dev y Prod) como dependencia del sistema en el bloque `apk add --no-cache`. No requiere instalacion manual adicional.

---

## 30. Bypass UTF-8 en PrinterService & Paridad samba-client en Docker [🟢 COMPLETADO]

### Problema Diagnosticado
El metodo `$printer->text()` de `mike42/escpos-php` valida que el input sea UTF-8 valido. Al convertir strings con caracteres espanoles (acentos, ene) a CP858 via `iconv()`, los bytes resultantes (ej: 0xA0 para 'a') son rechazados con la excepcion `Input must be UTF-8`, deteniendo fisicamente la impresion en textos como la direccion ("Morelia, ").

### Solucion Implementada

#### 1. Metodo writeRaw() — Bypass del Validador UTF-8
- **Ubicacion**: `App\Services\PrinterService::writeRaw(Printer $printer, string $text): void`
- Convierte texto UTF-8 a CP858 via `iconv('UTF-8', 'CP858//TRANSLIT//IGNORE', $text)`
- Escribe los bytes crudos directamente al conector fisico via `$printer->getPrintConnector()->write()`, evadiendo completamente la validacion de encoding de la libreria
- Fallback seguro: si `iconv()` retorna `false`, envia el texto original sin conversion

#### 2. Eliminacion del Metodo encode()
- Removido el metodo privado `encode(string $text): string` que era el helper anterior
- Toda la logica de conversion de encoding esta ahora encapsulada en `writeRaw()`

#### 3. Reemplazo Sistematico en Todos los Metodos de Impresion
- `printHeader()`: 7 llamadas migradas de `$printer->text($this->encode(...))` a `$this->writeRaw($printer, ...)`
- `printMetadata()`: 6 llamadas migradas
- `printItems()`: 2 llamadas migradas + loop interno migrado
- `printTotals()`: 10 llamadas migradas (incluyendo descuento, recibido, cambio)
- `printFooter()`: 3 llamadas migradas + loop internos migrados
- Comandos de control fisico preservados intactos: `setJustification()`, `setEmphasis()`, `feed()`, `cut()`, `qrCode()`, `selectCharacterTable()`

#### 4. Consolidacion de samba-client en Infraestructura Docker
- **Dockerfile.dev** (Alpine): `samba-client` agregado al bloque `apk add --no-cache` permanentemente
- **Dockerfile.prod** (Alpine multi-stage): `samba-client` agregado al bloque `apk add --no-cache` del Stage 2 (runtime)
- Garantiza que el binario `smbclient` este siempre en el `$PATH` del sistema al reconstruir contenedores locales o desplegar en DigitalOcean via CI/CD

### Paridad de Entornos Verificada
| Entorno | Dockerfile | samba-client | writeRaw() | Estado |
| :--- | :--- | :--- | :--- | :--- |
| Desarrollo Local | `backend/Dockerfile.dev` | [🟢 Instalado] | [🟢 Activo] | Operativo |
| Produccion DigitalOcean | `backend/Dockerfile.prod` | [🟢 Instalado] | [🟢 Activo] | Operativo |

### Archivos Modificados en esta Fase
**Backend (modificados):**
- `app/Services/PrinterService.php` — Removido `encode()`, agregado `writeRaw()`, reemplazadas todas las llamadas `$printer->text($this->encode(...))` por `$this->writeRaw($printer, ...)`

**Infraestructura (modificados):**
- `backend/Dockerfile.dev` — Agregado `samba-client` a `apk add --no-cache`
- `backend/Dockerfile.prod` — Agregado `samba-client` a `apk add --no-cache` (Stage 2 runtime)

---

## 30. Limpieza de Flujo de Checkout (Eliminacion window.print) & Correccion Modal Reimpresion [🟢 Completado]

### Tarea 1: Eliminacion Definitiva de window.print() del Flujo de Checkout [🟢 Completado]

#### Problema
El flujo de cobro en el POS disparaba `window.print()` via `setTimeout(350ms)` despues de confirmar la venta, abriendo el dialogo de impresion nativo del navegador. Esto era un comportamiento heredado anterior al motor ESC/POS que ya opera de forma directa y silenciosa contra la ticketera fisica.

#### Solucion Implementada

##### POSPage.jsx
- **Eliminado** `window.print()` de `handleCheckoutSuccess()` — el callback ahora solo cierra la modal, limpia el carrito y refresca datos
- **Eliminados** los estados `activeOrderForPrinting` y `activeTicketConfigForPrinting` (ya no se necesita renderizar ticket oculto para impresion CSS)
- **Eliminado** el `<div className="hidden print:block">` con TicketPreview que servia exclusivamente para la impresion via navegador
- **Eliminado** el import de `TicketPreview` (ya no se usa en este componente)
- **Simplificada** la firma de `handleCheckoutSuccess` a `(order)` — ya no recibe `ticketConfig`

##### CheckoutModal.jsx
- **Eliminado** import de `useCallback` (no utilizado)
- **Actualizado** flujo post-venta exitosa: el `axios.post(/orders/${orderId}/print)` sigue siendo fire-and-forget contra el backend ESC/POS
- **Toasts mejorados**: Exito de impresion muestra "Venta procesada e impresa con exito!". Si la ticketera falla, muestra "Venta procesada con exito!" + warning "No se pudo enviar a la ticketera. Puedes reimprimir desde el historial."
- **Simplificada** la invocacion de `onSuccess` a `(order)` en las 3 rutas (online exitoso, offline intencional, offline por error de red)

##### Flujo Resultante (Post-Cobro)
1. Usuario confirma cobro → `POST /api/orders` crea la orden
2. Backend ESC/POS recibe `POST /api/orders/{id}/print` de forma asincrona (fire-and-forget)
3. Toast de confirmacion muestra exito (con o sin impresion fisica)
4. Modal se cierra, carrito se limpia, catalogo se refresca
5. **Ninguna ventana emergente del sistema operativo se abre**

### Tarea 2: Correccion de Maquetacion en Modal de Reimpresion [🟢 Completado]

#### Problema
En la modal de reimpresion de SalesHistoryPage, los botones "Ticketera" y "Navegador" se encimaban sobre el titulo "Reimprimir Ticket" debido a `flex items-center justify-between` en una sola fila que colapsaba en pantallas medianas.

#### Solucion Implementada (SalesHistoryPage.jsx)
- **Contenedor principal**: Migrado de `<div className="p-6">` a `<div className="flex flex-col gap-4 p-6">` para flujo vertical con espaciado uniforme
- **Cabecera**: El titulo "Reimprimir Ticket" ocupa su propio bloque sin competir por espacio horizontal
- **Fila de acciones**: Los botones "Ticketera" y "Navegador" agrupados en `<div className="flex items-center justify-end gap-2 print:hidden">`, posicionados debajo del titulo y arriba del preview
- **Cuerpo del ticket**: El TicketPreview con borde discontinuo mantiene margen superior limpio respecto a los botones via `gap-4` del contenedor padre
- Ambos bloques (cabecera y acciones) ocultos en impresion con `print:hidden`

### Tarea 3: Compilacion y Verificacion [🟢 Completado]
- Build de Vite ejecutado exitosamente sin errores de CSS ni de JavaScript
- La modal se adapta de forma responsiva gracias al flujo de columna vertical con `gap-4`

### Archivos Modificados en esta Fase
**Frontend (modificados):**
- `src/components/pos/CheckoutModal.jsx` — Eliminado useCallback, toasts mejorados, onSuccess simplificado
- `src/pages/pos/POSPage.jsx` — Eliminado window.print(), estados de impresion, div print:block, import TicketPreview
- `src/pages/sales/SalesHistoryPage.jsx` — Modal reimpresion reestructurada con flex-col gap-4, titulo y botones en bloques separados

---

## 31. Migracion Definitiva a QZ Tray con Firma Digital RSA [🟢 COMPLETADO Y OPERATIVO]

### Contexto de la Migracion
Se migro el sistema de impresion de Cronos POS del esquema server-side (SambaAuthPrintConnector via smbclient) al esquema client-side con QZ Tray via WebSockets. La impresion ahora se procesa integramente del lado del cliente, eliminando la necesidad de que el servidor en la nube se comunique con el hardware local.

### Tarea 1: Saneamiento de Infraestructura Docker (Dev y Prod) [🟢 Completado]

#### Dockerfiles
- **Dockerfile.dev**: Removido `samba-client` del bloque `apk add --no-cache`
- **Dockerfile.prod**: Removido `samba-client` del bloque `apk add --no-cache` (Stage 2 runtime)
- Las imagenes de contenedor quedan mas ligeras sin dependencias de Samba

#### Variables de Entorno Obsoletas Purgadas
- Eliminadas de `.env.example`: `PRINTER_CONNECTION_TYPE`, `PRINTER_SMB_HOST`, `PRINTER_SMB_SHARE`, `PRINTER_SMB_USER`, `PRINTER_SMB_PASS`, `PRINTER_IP_ADDRESS`, `PRINTER_PORT`, `PRINTER_FILE_PATH`
- Eliminadas de `.env.production.example`: Mismas variables de impresora legacy
- Eliminadas de `docker-compose.yml`: 8 variables `PRINTER_*` del servicio backend
- Nuevas variables: `QZ_PRIVATE_KEY_PATH`, `QZ_CERTIFICATE_PATH`

#### Archivos Eliminados
- `app/Services/PrintConnectors/SambaAuthPrintConnector.php` — Conector SMB custom obsoleto

### Tarea 2: Refactorizacion del Backend en Memoria (PrinterService) [🟢 Completado]

#### PrinterService.php — DummyPrintConnector + Base64
- Metodo `print()` reemplazado por `generateBase64(TicketDTO $dto): string`
- Usa `DummyPrintConnector` de mike42/escpos-php para generar comandos ESC/POS en memoria
- Retorna `base64_encode($connector->getData())` con todos los bytes ESC/POS
- Preserva intacto: bypass UTF-8 (`writeRaw`), code page CP858, alineacion 32 chars, QR nativo, corte de papel
- Eliminadas todas las dependencias de conectores fisicos (Network, File, Samba)

#### PrintTicketController — Retorna printer_data
- Ya no intenta imprimir fisicamente desde el servidor
- Genera Base64 ESC/POS via PrinterService y lo retorna en `response.printer_data`

#### OrderController::store — Incluye printer_data en respuesta de venta
- Al crear una orden exitosa, genera automaticamente los datos ESC/POS en Base64
- Los retorna en el campo `printer_data` de la respuesta JSON
- El frontend intercepta este campo y lo despacha a QZ Tray

### Tarea 3: Endpoint de Firma Criptografica (QzSecurityController) [🟢 Completado]

#### Controlador: QzSecurityController
- **Ubicacion**: `App\Http\Controllers\Admin\QzSecurityController`
- **Endpoint sign**: `POST /api/printer/sign` — Recibe `requestString` (challenge QZ Tray), firma con SHA512+RSA via `openssl_sign()`, retorna Base64
- **Endpoint certificate**: `GET /api/printer/certificate` — Retorna texto plano del certificado digital
- Ambos protegidos por middleware `auth:sanctum` + `user.active`

#### Llave Privada y Certificado
- Directorio: `storage/app/certs/` con `.gitkeep`
- Archivos esperados: `private-key.pem` (RSA), `digital-certificate.txt` (certificado publico QZ)
- Las llaves NO se commitean al repositorio — se configuran manualmente en cada entorno

#### Config actualizado
- `config/printer.php` simplificado: solo `qz_private_key_path` y `qz_certificate_path`

### Tarea 4: Frontend — QZ Tray SDK + Hook useQzPrinter [🟢 Completado]

#### Dependencia: qz-tray ^2.2.6
- Instalado via npm en `frontend/package.json`

#### Hook: useQzPrinter.js
- **Seguridad automatica**: Configura `qz.security.setCertificatePromise` (fetches certificado via API) y `qz.security.setSignaturePromise` (firma via API POST)
- **Conexion WebSocket**: Auto-conecta al montar, auto-desconecta al desmontar
- **Estado reactivo**: `connected` (boolean), `printerName` (persistido en localStorage bajo `qz_printer_name`)
- **Metodos expuestos**: `connect()`, `disconnect()`, `listPrinters()`, `printRaw(base64Data)`, `savePrinterName(name)`
- `printRaw()` usa la impresora guardada o la default del sistema

#### Integracion en POSPage
- Hook `useQzPrinter` inicializado en POSPage
- Objeto `qzPrinter` pasado como prop a CheckoutModal
- Conexion WebSocket se establece silenciosamente en segundo plano al cargar el POS

### Tarea 5: Refactorizacion de Checkout y Modal de Reimpresion [🟢 Completado]

#### CheckoutModal.jsx
- Recibe prop `qzPrinter` desde POSPage
- Tras cobro exitoso: intercepta `printer_data` de la respuesta API
- Si QZ Tray esta conectado: despacha datos ESC/POS directamente via `qzPrinter.printRaw(printerData)`
- Si no esta conectado: toast informativo "QZ Tray no conectado"
- Eliminada dependencia de `api.post(/orders/${id}/print)` fire-and-forget del flujo anterior

#### SalesHistoryPage.jsx — Modal Reimpresion
- Hook `useQzPrinter` inicializado en SalesHistoryPage
- Boton "Ticketera" ahora obtiene `printer_data` del endpoint `/orders/{id}/print` y lo despacha via QZ Tray
- Layout del modal corregido con `flex flex-col gap-4 p-6`:
  - Titulo en bloque independiente
  - Fila de botones (Ticketera + Navegador) en bloque horizontal separado
  - TicketPreview envuelto en tarjeta con `border-2 border-dashed border-slate-300` para previsualización limpia

### Paridad de Entornos Verificada
| Entorno | samba-client | QZ Tray | Firma Digital | Estado |
| :--- | :--- | :--- | :--- | :--- |
| Desarrollo Local (Docker) | [❌ Eliminado] | [🟢 Frontend] | [🟢 Backend RSA] | Operativo |
| Produccion DigitalOcean | [❌ Eliminado] | [🟢 Frontend] | [🟢 Backend RSA] | Operativo |

### Archivos Creados en esta Fase
**Backend (nuevos):**
- `app/Http/Controllers/Admin/QzSecurityController.php` — Endpoint firma RSA SHA512 + certificado
- `storage/app/certs/.gitkeep` — Directorio para llaves de QZ Tray

**Frontend (nuevos):**
- `src/hooks/useQzPrinter.js` — Hook de conexion y despacho QZ Tray con firma automatica

### Archivos Modificados en esta Fase
**Backend (modificados):**
- `app/Services/PrinterService.php` — Reescrito: DummyPrintConnector→Base64, eliminados conectores fisicos
- `app/Http/Controllers/Admin/PrintTicketController.php` — Retorna printer_data Base64 en lugar de imprimir
- `app/Http/Controllers/Sales/OrderController.php` — store() incluye printer_data en respuesta
- `config/printer.php` — Simplificado a paths de QZ Tray
- `routes/api.php` — 2 rutas nuevas: POST /api/printer/sign, GET /api/printer/certificate

**Frontend (modificados):**
- `src/components/pos/CheckoutModal.jsx` — Prop qzPrinter, despacho ESC/POS via QZ Tray post-cobro
- `src/pages/pos/POSPage.jsx` — useQzPrinter hook, prop qzPrinter a CheckoutModal
- `src/pages/sales/SalesHistoryPage.jsx` — useQzPrinter hook, reimpresion via QZ Tray, modal con borde discontinuo

**Infraestructura (modificados):**
- `backend/Dockerfile.dev` — Removido samba-client
- `backend/Dockerfile.prod` — Removido samba-client
- `backend/.env.example` — Purgadas variables PRINTER_*, agregadas QZ_*
- `.env.production.example` — Purgadas variables PRINTER_*, agregadas QZ_*
- `docker-compose.yml` — Eliminadas 8 variables PRINTER_* del servicio backend

**Archivos Eliminados:**
- `app/Services/PrintConnectors/SambaAuthPrintConnector.php` — Conector SMB obsoleto

---

## 32. Optimizacion de Estatus Inline (Toggle Switch en DataTables) [🟢 COMPLETADO Y OPERATIVO]

### Objetivo
Reemplazar los Badges/Tags de texto estatico en las columnas "Estatus" de los DataTables por componentes InputSwitch interactivos con actualizacion optimista, eliminando la necesidad de abrir modales completas para activar/desactivar registros.

### Backend — Metodo toggleStatus por Controlador (Independiente)

#### ProductController::toggleStatus()
- **Endpoint**: `PATCH /api/products/{product}/toggle-status`
- Invierte `$product->is_active` y guarda en PostgreSQL
- Retorna JSON homogeneo: `{ status, message, is_active }`

#### CategoryController::toggleStatus()
- **Endpoint**: `PATCH /api/categories/{category}/toggle-status`
- Invierte `$category->is_active` y guarda en PostgreSQL
- Retorna JSON homogeneo: `{ status, message, is_active }`

#### PromotionController::toggleStatus()
- **Endpoint**: `PATCH /api/promotions/{promotion}/toggle-status`
- Middleware `role:admin,manager` protege la ruta
- Invierte `$promotion->is_active` y guarda en PostgreSQL
- Retorna JSON homogeneo: `{ status, message, is_active }`

#### UserController::toggleStatus() (Existente — Extendido)
- **Endpoint**: `PATCH /api/admin/users/{user}/toggle-status` (nuevo) + `POST` (backward compat)
- Middleware `role:admin,manager` protege la ruta
- Conmuta entre `active` y `suspended` (ENUM nativo PostgreSQL)
- Kill-Switch: revoca todos los tokens si se suspende
- Guardia de auto-accion: bloquea operacion sobre el usuario autenticado
- Respuesta extendida incluye `is_active` boolean para consistencia con el frontend

### Rutas API Registradas
| Metodo | Ruta | Middleware | Controlador |
| :--- | :--- | :--- | :--- |
| PATCH | /api/products/{product}/toggle-status | auth, user.active | ProductController@toggleStatus |
| PATCH | /api/categories/{category}/toggle-status | auth, user.active | CategoryController@toggleStatus |
| PATCH | /api/promotions/{promotion}/toggle-status | auth, user.active, role:admin,manager | PromotionController@toggleStatus |
| PATCH | /api/admin/users/{user}/toggle-status | auth, user.active, role:admin,manager | UserController@toggleStatus |

### Frontend — InputSwitch con Actualizacion Optimista

#### Componente Utilizado
- `InputSwitch` de PrimeReact (`primereact/inputswitch`) — compatible con PrimeReact v10.9.x
- Estilizado via CSS global en `index.css`: Verde Esmeralda (#059669) para activo, Gris Pizarra (#94a3b8) para inactivo
- Animacion fluida de deslizamiento via `transition: background-color 0.2s ease`

#### Paginas Modificadas

##### ProductsPage (`/products`)
- Columna "Estatus": Tag estatico reemplazado por InputSwitch
- `handleToggleStatus()`: Actualiza visualmente de inmediato (optimista), dispara `axios.patch` en background
- Rollback automatico con `toast.error("Error: No se pudo actualizar el estatus del registro.")` si la API falla

##### CategoriesPage (`/categories`)
- Columna "Estatus": Tag estatico reemplazado por InputSwitch
- Misma logica optimista con rollback

##### PromotionsPage (`/promotions`)
- Columna "Estatus": Tag estatico reemplazado por InputSwitch + label contextual (Vigente/Programada/Inactiva)
- Switch deshabilitado para usuarios sin rol admin/manager (vendor solo lectura)
- Misma logica optimista con rollback

##### UsersPage (`/admin/usuarios`)
- Columna "Estatus": Tag estatico reemplazado por InputSwitch
- Switch deshabilitado para el usuario autenticado (self-guard)
- Al interactuar, abre dialogo de confirmacion de impacto (suspender/reactivar) antes de ejecutar
- El dialogo existente de doble confirmacion se preserva intacto

#### Mecanismo de Resiliencia (UX Avanzado)
1. Click en InputSwitch → Estado visual cambia inmediatamente (Optimista)
2. `axios.patch` al endpoint especifico del recurso se dispara en background
3. Si la API responde con exito: estado confirmado, sin accion adicional
4. Si la API falla (error de red, validacion, permisos): Rollback automatico del switch a su posicion original + Toast rojo con leyenda de error

### CSS Global (index.css)
- `.p-inputswitch.p-inputswitch-checked .p-inputswitch-slider`: Verde Esmeralda `#059669`
- `.p-inputswitch:not(.p-inputswitch-checked) .p-inputswitch-slider`: Gris Pizarra `#94a3b8`
- Transiciones suaves con `transition: background-color 0.2s ease, box-shadow 0.2s ease`

### Archivos Modificados en esta Fase
**Backend (modificados):**
- `app/Http/Controllers/Catalog/ProductController.php` — Metodo toggleStatus()
- `app/Http/Controllers/Catalog/CategoryController.php` — Metodo toggleStatus()
- `app/Http/Controllers/Promotion/PromotionController.php` — Metodo toggleStatus()
- `app/Http/Controllers/Admin/UserController.php` — Respuesta extendida con is_active boolean
- `routes/api.php` — 4 rutas PATCH toggle-status + 1 PATCH backward compat para users

**Frontend (modificados):**
- `src/pages/catalog/ProductsPage.jsx` — InputSwitch con optimistic update + rollback
- `src/pages/catalog/CategoriesPage.jsx` — InputSwitch con optimistic update + rollback
- `src/pages/promotions/PromotionsPage.jsx` — InputSwitch con optimistic update + rollback + label contextual
- `src/pages/admin/UsersPage.jsx` — InputSwitch con dialogo de confirmacion + self-guard
- `src/index.css` — Estilos globales InputSwitch (Emerald/Slate)

---

## 33. Enriquecimiento ToggleSwitch + Panel de Configuracion de Impresora Local [🟢 COMPLETADO Y OPERATIVO]

### Tarea 1: Etiquetas Dinamicas y Toast en ToggleSwitch Inline [🟢 Completado]

#### Cambios en Todas las DataTables
- **ProductsPage, CategoriesPage, PromotionsPage, UsersPage**: El `bodyTemplate` de la columna "Estatus" fue reestructurado a un layout vertical centrado (`flex flex-col items-center justify-center text-center w-full mx-auto p-1`)
- Columna `<Column>` con `bodyClassName="text-center"` para forzar centrado absoluto de la celda
- Debajo de cada `<InputSwitch>`, se agrego una etiqueta `<span>` con texto dinamico:
  - Activo: `text-emerald-600` con texto "Activo"
  - Inactivo: `text-slate-500` con texto "Inactivo"
  - Promociones: Labels contextuales "Vigente" (emerald), "Programada" (blue), "Inactiva" (slate)
- Animacion suave via `transition-colors duration-200` en la etiqueta
- **Toast contextual de activacion**: `toast.success('¡Registro activado con éxito!')` cuando el registro se enciende
- **Toast contextual de desactivacion**: `toast.info('¡Registro desactivado con éxito!')` cuando el registro se apaga (estilo informativo neutral)
- **Toast de error**: `toast.error('Error: No se pudo cambiar el estado del registro.')` en el bloque `catch` ANTES del rollback visual

### Tarea 2: Panel de Configuracion de Impresora Local (PrinterSetupPanel) [🟢 Completado]

#### Componente: PrinterSetupPanel.jsx
- **Ubicacion**: `src/components/settings/PrinterSetupPanel.jsx`
- Integrado como seccion inferior en `SystemSettingsPage` (`/admin/configuracion`)
- **Conexion QZ Tray**: Al montar, verifica `qz.websocket.isActive()` y auto-conecta si esta desconectado
- **Indicador de estado**: Tag PrimeReact en header — "QZ Tray Conectado" (success), "Conectando..." (warning), "QZ Tray Desconectado" (danger)
- **Boton "Escanear Impresoras Locales"**: Icono `pi pi-refresh`, invoca `qz.printers.find()` de forma asincrona, mapea resultado en Dropdown PrimeReact
- **Dropdown de impresoras**: Muestra nombres reales de impresoras fisicas detectadas por QZ Tray
- **Persistencia**: Al guardar, almacena en `localStorage.setItem('cronos_active_printer', selectedPrinter)` y sincroniza con el hook `useQzPrinter`
- **Inicializacion**: Lee `localStorage.getItem('cronos_active_printer')` al montar para mostrar la impresora previamente guardada
- **Toast de confirmacion**: "Impresora [Nombre] configurada como predeterminada"
- **Banner de advertencia**: Si QZ Tray no esta conectado, muestra banner amber informativo

### Tarea 3: Sincronizacion del Checkout con LocalStorage + Seguridad QZ Tray [🟢 Completado]

#### Migracion de Clave localStorage
- Clave migrada de `qz_printer_name` a `cronos_active_printer` en `useQzPrinter.js`
- El hook `printRaw()` lee `printerName` del state (inicializado desde `localStorage.getItem('cronos_active_printer')`)
- Al momento de invocar `qz.configs.create(target)`, usa la impresora guardada o fallback a `qz.printers.getDefault()`
- **CheckoutModal**: Tras cobro exitoso, `qzPrinter.printRaw(printerData)` despacha al hardware correcto seleccionado por el usuario
- **SalesHistoryPage**: Reimpresion via QZ Tray tambien usa la misma impresora guardada

#### Promesas de Seguridad QZ Tray (Certificado + Firma RSA-SHA512)
- `qz.security.setCertificatePromise()` consume `GET /api/printer/certificate` para obtener el certificado publico
- `qz.security.setSignaturePromise()` consume `POST /api/printer/sign` para firmar cada request con RSA-SHA512
- **Fallback desarrollo**: En localhost/127.0.0.1, si los endpoints fallan (archivos .pem no configurados), resuelve con `null` permitiendo modo unsigned sin alertas bloqueantes
- **Produccion**: Si los endpoints fallan fuera de localhost, rechaza la promesa con mensaje descriptivo para deteccion de errores

### Archivos Creados en esta Fase
**Frontend (nuevos):**
- `src/components/settings/PrinterSetupPanel.jsx` — Panel de configuracion de impresora local con QZ Tray SDK

### Archivos Modificados en esta Fase
**Frontend (modificados):**
- `src/pages/catalog/ProductsPage.jsx` — Centrado absoluto de celda + toast contextual activado/desactivado
- `src/pages/catalog/CategoriesPage.jsx` — Centrado absoluto de celda + toast contextual activado/desactivado
- `src/pages/promotions/PromotionsPage.jsx` — Centrado absoluto de celda + toast contextual activado/desactivado
- `src/pages/admin/UsersPage.jsx` — Centrado absoluto de celda con bodyClassName
- `src/pages/admin/SystemSettingsPage.jsx` — Importa PrinterSetupPanel + useQzPrinter, renderiza panel de impresora debajo de TabView
- `src/hooks/useQzPrinter.js` — Promesas de seguridad con fallback graceful para localhost, clave localStorage cronos_active_printer

---

## 28. Resiliencia Entrypoint Produccion & Titulos Dinamicos de Ventana [🟢 COMPLETADO Y OPERATIVO]

### Tarea 1: Entrypoint de Produccion Resiliente [🟢 COMPLETADO Y OPERATIVO]

#### Problema
El script `docker-entrypoint.prod.sh` ejecutaba `php artisan storage:link` condicionalmente (`if [ ! -L "public/storage" ]`), pero si existia un enlace corrupto, un directorio residual, o un problema de permisos en la carpeta `public/`, el contenedor entraba en Crash Loop eterno por el `set -e` del shell.

#### Solucion
- **Limpieza proactiva**: `rm -rf public/storage` antes de crear el symlink, eliminando enlaces viejos, corruptos o directorios residuales
- **Salida segura**: `php artisan storage:link --force || true` asegura que si el comando falla por cualquier razon, el script continua con el arranque de los servicios (cache, queue worker, Reverb, artisan serve)
- El flag `--force` de artisan recrea el enlace aunque ya exista

### Tarea 2: Optimizacion de Permisos en Dockerfile.prod [🟢 COMPLETADO Y OPERATIVO]

#### Problema
En Alpine Linux, las subcarpetas de `storage/` (app/public, framework/cache, framework/sessions, framework/views, logs) podian no existir si el `COPY backend/ .` no las incluia (por .dockerignore o por estar vacias en el repo), causando errores de permisos en runtime.

#### Solucion
- `mkdir -p` crea explicitamente todas las subcarpetas necesarias de storage y bootstrap/cache antes del `chown`
- `chown -R www-data:www-data` usa rutas absolutas `/var/www/html/storage /var/www/html/bootstrap/cache` para evitar ambiguedades en Alpine
- `chmod -R 775` mantiene permisos de grupo para compatibilidad con PHP-FPM

### Tarea 3: Titulos Dinamicos de Ventana (React) [🟢 COMPLETADO Y OPERATIVO]

#### Hook: usePageTitle (`src/hooks/usePageTitle.js`)
- Hook personalizado que escucha `location.pathname` via `useLocation()` de React Router
- Mapeo exhaustivo de rutas a nombres de modulo (21 rutas mapeadas + deteccion dinamica de `/products/:id/edit`)
- Formato estricto: `"Amaranto POS - {Nombre del Modulo}"`
- Fallback por defecto: `"Amaranto POS"` cuando la ruta no coincide con ningun modulo
- Integrado en `App.jsx` via componente `PageTitleManager` renderizado dentro del `BrowserRouter`

#### Ejemplos de Titulos
| Ruta | Titulo de Pestana |
| :--- | :--- |
| `/dashboard` | Amaranto POS - Dashboard |
| `/pos` | Amaranto POS - Punto de Venta |
| `/admin/ventas` | Amaranto POS - Historial de Tickets |
| `/admin/usuarios` | Amaranto POS - Gestion de Usuarios |
| `/products/abc-123/edit` | Amaranto POS - Editar Producto |
| `/ruta-desconocida` | Amaranto POS |

#### index.html
- Titulo estatico cambiado de `"frontend"` a `"Amaranto POS"` (visible antes de que React hidrate)

### Archivos Creados en esta Fase
**Frontend (nuevos):**
- `src/hooks/usePageTitle.js` — Hook de titulo dinamico con mapeo de rutas y fallback

### Archivos Modificados en esta Fase
**Backend (modificados):**
- `docker-entrypoint.prod.sh` — Limpieza proactiva + salida segura en storage:link
- `Dockerfile.prod` — mkdir -p subcarpetas storage + chown con rutas absolutas

**Frontend (modificados):**
- `index.html` — Titulo estatico "Amaranto POS"
- `src/App.jsx` — Import usePageTitle, componente PageTitleManager dentro de BrowserRouter

## Sprint de Correccion: Conector PHP 8, Auto-Impresion, Deploy y Titulos

### Tarea 1: Conector de Memoria Seguro para PHP 8 [🟢 COMPLETADO Y OPERATIVO]

#### Problema
La libreria `mike42/escpos-php` lanzaba `TypeError: implode()` en `DummyPrintConnector.php` (linea 65) debido a incompatibilidad con el tipado estricto de PHP 8.x. Esto causaba que `printer_data` siempre fuera `null` en la respuesta de `POST /orders`, impidiendo la impresion automatica.

#### Solucion
- Creado `app/PrintConnectors/SafeDummyPrintConnector.php` implementando `Mike42\Escpos\PrintConnectors\PrintConnector` con tipado estricto de arrays compatible con PHP 8.x
- Buffer interno `private array $buffer = []` con metodos `write()`, `getData()` (usa `implode('', $this->buffer)`), `clear()`, `finalize()`, `read()`
- Refactorizado `app/Services/PrinterService.php` para instanciar `SafeDummyPrintConnector` en lugar del `DummyPrintConnector` del vendor

### Tarea 2: Automatizacion de Impresion Post-Venta [🟢 COMPLETADO Y OPERATIVO]

#### Problema
Al registrar una venta con exito en el POS, el sistema guardaba la compra pero no disparaba la impresion del ticket de forma automatica debido a que `printer_data` era siempre `null` (causado por el TypeError de la Tarea 1).

#### Solucion
- Con el `SafeDummyPrintConnector` operativo, el `OrderController::store()` ahora genera correctamente el Base64 del ticket ESC/POS via `PrinterService::generateBase64()`
- El flujo automatico en `CheckoutModal.jsx` ya existia (lineas 193-205): al recibir `printer_data` del backend, invoca `qzPrinter.printRaw(printerData)` enviando el Base64 a QZ Tray
- Mejorada la condicion de impresion: ahora intenta imprimir si `qzPrinter` existe (no solo si esta conectado), ya que `printRaw()` auto-conecta via `useQzPrinter`
- Mensajes de feedback mejorados: distingue entre falta de `printer_data` y falta de QZ Tray

#### Flujo Completo Operativo
1. Usuario confirma cobro en `CheckoutModal`
2. `POST /orders` → `OrderController::store()` procesa venta en transaccion atomica
3. Backend genera `printer_data` (Base64 ESC/POS) via `SafeDummyPrintConnector` + `PrinterService`
4. Frontend recibe `res.data.printer_data`, invoca `qzPrinter.printRaw(printerData)`
5. `useQzPrinter.printRaw()` auto-conecta QZ Tray si necesario, envia datos a impresora seleccionada
6. Toast de exito confirma impresion o muestra fallback con opcion de reimprimir desde historial

### Tarea 3: Titulos Dinamicos de Ventana [🟢 PREVIAMENTE COMPLETADO — VERIFICADO OPERATIVO]

- Hook `usePageTitle` ya implementado con formato `"Amaranto POS - {Nombre del Modulo}"`
- 21 rutas mapeadas + deteccion dinamica de edicion de productos
- Fallback a `"Amaranto POS"` para rutas no mapeadas
- Integrado via `PageTitleManager` en `App.jsx`

### Tarea 4: Optimizacion de deploy.sh [🟢 COMPLETADO Y OPERATIVO]

#### Problemas Resueltos
1. **TIMESTAMP estatico**: La variable `TIMESTAMP` se evaluaba una sola vez al inicio del script, repitiendo el mismo valor en todos los logs
2. **BuildKit no habilitado**: Sin `DOCKER_BUILDKIT=1` el build de Docker no aprovechaba cache de capas optimizado

#### Cambios
- Exportadas variables `DOCKER_BUILDKIT=1` y `COMPOSE_DOCKER_CLI_BUILD=1` al inicio del script
- Refactorizada funcion `log()`: reemplazada variable estatica `$TIMESTAMP` por evaluacion en tiempo real `$(date '+%Y-%m-%d %H:%M:%S')` dentro de la funcion
- Entrypoint de produccion (`docker-entrypoint.prod.sh`) ya contenia `rm -rf public/storage && php artisan storage:link --force || true` — verificado operativo

### Archivos Creados
**Backend (nuevos):**
- `app/PrintConnectors/SafeDummyPrintConnector.php` — Conector de memoria PHP 8 compatible

### Archivos Modificados
**Backend (modificados):**
- `app/Services/PrinterService.php` — Usa SafeDummyPrintConnector en lugar de DummyPrintConnector

**Frontend (modificados):**
- `src/components/pos/CheckoutModal.jsx` — Condicion de impresion mejorada, mensajes de feedback refinados

**Infraestructura (modificados):**
- `deploy.sh` — DOCKER_BUILDKIT=1, COMPOSE_DOCKER_CLI_BUILD=1, funcion log() con timestamp en tiempo real

---

## 34. Migracion Definitiva de QZ Tray a Cronos POS Agent (Frontend) [🟢 COMPLETADO Y OPERATIVO]

### Contexto de la Migracion
Se elimino por completo la dependencia de QZ Tray (SDK WebSocket client-side + firma RSA SHA512) y se adopto el agente nativo "Cronos POS Agent" que opera como servicio local HTTP en `http://127.0.0.1:9100`. La impresion ahora se comunica via fetch HTTP directo en lugar de WebSockets QZ Tray, simplificando la arquitectura y eliminando la dependencia de un software de terceros.

### Tarea 1: Depuracion y Eliminacion de QZ Tray [🟢 Completado]

#### Dependencia npm eliminada
- `qz-tray` ^2.2.6 removido de `frontend/package.json`
- `package-lock.json` regenerado sin la dependencia

#### Archivo eliminado
- `src/hooks/useQzPrinter.js` — Hook QZ Tray con firma RSA, WebSocket, certificado digital

#### Referencias purgadas
- Todos los imports `useQzPrinter` reemplazados por `useCronosAgent`
- Todas las props `qzPrinter` renombradas a `cronosAgent`
- Todos los logs, tooltips y mensajes de error actualizados de "QZ Tray" a "Cronos Agent"
- Archivos afectados: POSPage, CheckoutModal, SalesHistoryPage, SystemSettingsPage, PrinterSetupPanel

### Tarea 2: Hook useCronosAgent (Nuevo) [🟢 Completado]

#### Ubicacion: `src/hooks/useCronosAgent.js`
- **Base URL**: `http://127.0.0.1:9100` (API local del agente nativo)
- **Autenticacion**: Lee `api_token` de `localStorage('cronos_agent_token')` e inyecta en header `X-Cronos-Agent-Token` en cada peticion
- **Persistencia de impresora**: Clave `cronos_active_printer` en localStorage

#### Metodos expuestos
| Metodo | Endpoint | Descripcion |
| :--- | :--- | :--- |
| `getAvailablePrinters()` | `GET /api/printers` | Retorna lista de impresoras detectadas por el agente |
| `printTicket(printerName, base64Data)` | `POST /api/print` | Envia datos ESC/POS Base64 a la impresora especificada |
| `printRaw(base64Data)` | `POST /api/print` | Wrapper que usa la impresora guardada en localStorage |
| `printPDFDocument(printerName, base64Pdf)` | `POST /api/print/pdf` | Envia documento PDF en Base64 a la impresora convencional especificada. Usado para reportes de cierre de caja, inventario y formatos de cocina. Inyecta `X-Cronos-Agent-Token` en headers. Si el agente responde con error, propaga el mensaje JSON del agente (`message` o `error`) |
| `getPrinterQueue(printerName)` | `GET /api/printers/queue?printer_name=...` | Consulta estado de la cola de impresion |
| `checkConnection()` | `GET /api/printers` | Verifica conectividad con el agente |

#### Estado reactivo
- `connected` (boolean) — true si el agente responde en 127.0.0.1:9100
- `printerName` (string) — Nombre de la impresora activa (persistido en localStorage)
- `savePrinterName(name)` — Guarda/limpia la impresora seleccionada

### Tarea 3: Componente PrinterQueueMonitor (Nuevo) [🟢 Completado]

#### Ubicacion: `src/components/pos/PrinterQueueMonitor.jsx`
- **Polling**: Consulta `GET /api/printers/queue` cada 9 segundos cuando el agente esta conectado y hay impresora configurada
- **Estados visuales**:
  - **Desconectado**: Indicador gris con texto "Cronos Agent desconectado"
  - **Sin impresora**: Banner amber "Sin impresora configurada"
  - **Cola OK (0 trabajos)**: Badge emerald "Cola OK" con indicador verde solido
  - **Trabajos retenidos (>0)**: Badge amber con conteo exacto de jobs, indicador amber pulsante, boton de refresco
- **Integracion**: Renderizado dentro de PrinterSetupPanel cuando hay impresora configurada y agente conectado
- **Props**: Recibe `cronosAgent` (instancia del hook useCronosAgent)

### Tarea 4: Actualizacion de PrinterSetupPanel [🟢 Completado]

#### Cambios en `src/components/settings/PrinterSetupPanel.jsx`
- Prop renombrada de `qzPrinter` a `cronosAgent`
- Escaneo de impresoras via `cronosAgent.getAvailablePrinters()` (HTTP GET) en lugar de `qz.printers.find()` (WebSocket)
- Indicador de estado: "Cronos Agent Conectado/Desconectado" (era "QZ Tray")
- Banner de desconexion actualizado con URL del agente: `http://127.0.0.1:9100`
- Requisitos actualizados: "Cronos POS Agent debe estar instalado y ejecutandose en esta computadora (puerto 9100)"
- Integra `PrinterQueueMonitor` como seccion de monitoreo cuando hay impresora activa

### Tarea 5: Actualizacion de Flujos de Impresion [🟢 Completado]

#### CheckoutModal.jsx
- Prop `qzPrinter` renombrada a `cronosAgent`
- Post-cobro: `cronosAgent.printRaw(printerData)` en lugar de `qzPrinter.printRaw(printerData)`
- Mensajes de error actualizados: "Cronos POS Agent" en lugar de "QZ Tray"

#### POSPage.jsx
- Hook `useQzPrinter()` reemplazado por `useCronosAgent()`
- Variable `qzPrinter` renombrada a `cronosAgent`
- Prop a CheckoutModal: `cronosAgent={cronosAgent}`

#### SalesHistoryPage.jsx
- Hook `useQzPrinter()` reemplazado por `useCronosAgent()`
- Variable `qzPrinter` renombrada a `cronosAgent`
- Tooltip de reimpresion: "Imprimir via Cronos Agent (ESC/POS directo)"
- Logs de error actualizados

#### SystemSettingsPage.jsx
- Hook `useQzPrinter()` reemplazado por `useCronosAgent()`
- Variable `qzPrinter` renombrada a `cronosAgent`
- Prop a PrinterSetupPanel: `cronosAgent={cronosAgent}`

### Matriz de Modulos (Actualizacion Fila 44 y 47)
| Modulo | Estado Backend | Estado Frontend | Observaciones |
| :--- | :--- | :--- | :--- |
| Motor Impresion Cronos POS Agent | [🟢 COMPLETADO Y OPERATIVO] | [🟢 COMPLETADO Y OPERATIVO] | SafeDummyPrintConnector (PHP 8 compatible), auto-impresion post-venta, Cronos POS Agent HTTP local (127.0.0.1:9100), hook useCronosAgent, eliminada dependencia QZ Tray |
| Selector de Impresoras Locales (Cronos Agent) | [🟢 COMPLETADO Y OPERATIVO] | [🟢 COMPLETADO Y OPERATIVO] | PrinterSetupPanel en Configuracion del Sistema, escaneo via GET /api/printers, Dropdown PrimeReact, persistencia localStorage (cronos_active_printer), PrinterQueueMonitor con polling 9s |

### Archivos Creados en esta Fase
**Frontend (nuevos):**
- `src/hooks/useCronosAgent.js` — Hook de comunicacion con Cronos POS Agent via HTTP (127.0.0.1:9100)
- `src/components/pos/PrinterQueueMonitor.jsx` — Widget de monitoreo de cola de impresion con polling 9s

### Archivos Eliminados en esta Fase
**Frontend (eliminados):**
- `src/hooks/useQzPrinter.js` — Hook QZ Tray obsoleto (WebSocket + firma RSA SHA512)

### Archivos Modificados en esta Fase
**Frontend (modificados):**
- `package.json` — Eliminada dependencia `qz-tray` ^2.2.6
- `src/components/pos/CheckoutModal.jsx` — Prop qzPrinter → cronosAgent, mensajes actualizados
- `src/components/settings/PrinterSetupPanel.jsx` — Reescrito: Cronos Agent HTTP en lugar de QZ Tray WebSocket, integra PrinterQueueMonitor
- `src/pages/pos/POSPage.jsx` — Hook useCronosAgent, prop cronosAgent
- `src/pages/sales/SalesHistoryPage.jsx` — Hook useCronosAgent, tooltips actualizados
- `src/pages/admin/SystemSettingsPage.jsx` — Hook useCronosAgent, prop cronosAgent

---

## 35. Impresion Directa de PDFs via Cronos POS Agent [🟢 COMPLETADO Y OPERATIVO]

### Contexto
Se extendio el hook `useCronosAgent` con soporte para impresion de documentos PDF en impresoras convencionales (no termicas) a traves del endpoint `POST /api/print/pdf` del agente local. Esto permite enviar reportes de cierre de caja, listados de inventario y formatos de cocina directamente a la impresora sin abrir ventanas del navegador.

### Tarea 1: Nueva Funcion printPDFDocument en useCronosAgent [🟢 Completado]

#### Ubicacion: `src/hooks/useCronosAgent.js`
- **Firma**: `printPDFDocument(printerName, base64Pdf)` — Funcion asincrona exportable
- **Endpoint**: `POST http://127.0.0.1:9100/api/print/pdf`
- **Payload JSON**: `{ printer_name: string, data: string (Base64 del PDF) }`
- **Cabeceras**: `Content-Type: application/json` + `X-Cronos-Agent-Token` (leido de `localStorage('cronos_agent_token')`)
- **Manejo de errores**: Si el agente responde con HTTP no-200, parsea el JSON de error y propaga `message` o `error` del body para mostrar al usuario exactamente que fallo (ej: impresora desconectada, nombre invalido, cola llena)
- **Fallback de impresora**: Si `printerName` es falsy, usa la impresora activa guardada en localStorage (`cronos_active_printer`)

### Tarea 2: Integracion en CashRegisterClosingsPage (Cierres de Caja) [🟢 Completado]

#### Cambios en `src/pages/admin/CashRegisterClosingsPage.jsx`
- **Hook**: Importa y usa `useCronosAgent()` para acceso al agente local
- **Estado**: `printingPdfId` (UUID) para tracking de impresion en progreso por cierre individual
- **Funcion `handlePrintPdf(closing)`**:
  1. Valida conexion del agente y existencia de impresora configurada con mensajes descriptivos
  2. Muestra toast de carga "Cocinando impresion..." (via `toast.loading`)
  3. Descarga el PDF del backend Laravel como `arraybuffer` (`GET /cash-registers/closings/{id}/pdf`)
  4. Convierte el ArrayBuffer a Base64 string via `btoa` + `Uint8Array.reduce`
  5. Envia a `cronosAgent.printPDFDocument(printerName, base64Pdf)`
  6. En exito: toast success "Reporte de cierre enviado a la impresora"
  7. En error: toast error con mensaje exacto del agente local (no generico)
- **Boton en DataTable**: Icono `pi pi-print` (severity success) en la columna de acciones, junto al boton existente de descarga PDF. Deshabilitado si el agente no esta conectado o si hay impresion en progreso para ese cierre. Tooltip contextual indica estado del agente.
- **Boton en Modal Detalle**: Boton "Imprimir PDF" junto a "Descargar PDF" en el modal de detalle del arqueo, con label dinamico "Cocinando impresion..." durante el envio.

### Tarea 3: Politicas de Manejo de Respuestas del Agente Local [🟢 Documentado]

#### Respuestas del Agente (POST /api/print/pdf)
| HTTP Status | Significado | Accion Frontend |
| :--- | :--- | :--- |
| 200 | PDF enviado a la cola de impresion | `toast.success` con confirmacion |
| 400 | Payload invalido (falta printer_name o data) | `toast.error` con `body.message` |
| 401 | Token de agente invalido o ausente | `toast.error` con `body.message` |
| 404 | Impresora no encontrada en el sistema | `toast.error` con `body.message` |
| 500 | Error interno del agente (impresora desconectada, driver, etc.) | `toast.error` con `body.message` o `body.error` |
| Network Error | Agente no ejecutandose o puerto 9100 inaccesible | `toast.error` con mensaje de verificacion de servicio |

#### Flujo de Impresion PDF (Diagrama Secuencial)
1. Usuario hace clic en boton "Imprimir PDF" en Cierres de Caja
2. Frontend valida: agente conectado? impresora configurada?
3. Frontend descarga PDF del backend Laravel (`GET /api/cash-registers/closings/{id}/pdf` → arraybuffer)
4. Frontend convierte arraybuffer a Base64 string
5. Frontend envia `POST http://127.0.0.1:9100/api/print/pdf` con `{ printer_name, data }` + header `X-Cronos-Agent-Token`
6. Agente local recibe, decodifica Base64, envia al spooler de impresion del SO
7. Agente responde JSON con resultado
8. Frontend muestra toast segun resultado

#### UX durante la Impresion
- **Esperando respuesta**: Toast loading "Cocinando impresion..." (reemplaza spinner generico)
- **Exito**: Toast success con confirmacion limpia
- **Error de agente**: Toast error con mensaje exacto del JSON de error del agente (no generico)
- **Agente desconectado**: Boton deshabilitado con tooltip "Cronos Agent desconectado"
- **Sin impresora**: Toast error con instruccion de ir a Configuracion del Sistema

### Componentes React que Consumen printPDFDocument
| Componente | Ruta | Uso |
| :--- | :--- | :--- |
| `CashRegisterClosingsPage` | `/admin/cierres` | Impresion directa de reportes PDF de arqueo de caja (DataTable + Modal Detalle) |

### Archivos Modificados en esta Fase
**Frontend (modificados):**
- `src/hooks/useCronosAgent.js` — Nueva funcion `printPDFDocument(printerName, base64Pdf)` con POST a `/api/print/pdf`, manejo de errores JSON del agente, exportada en el return del hook
- `src/pages/admin/CashRegisterClosingsPage.jsx` — Import useCronosAgent, estado printingPdfId, funcion handlePrintPdf con toast loading/success/error, boton pi-print en DataTable acciones y en modal detalle

## 36. FASE 7: DEPLOY ÁGIL Y DOCKER OPTIMIZADO [🟢 COMPLETADO Y OPERATIVO]

### Contexto del Problema
El despliegue en produccion tardaba mas de 50 minutos en el Droplet de DigitalOcean por dos cuellos de botella en el hardware limitado del servidor:
1. **Backend**: `backend/Dockerfile.prod` compilaba las extensiones de PHP desde cero (`apk add` + `docker-php-ext-install` + `pecl install redis`) sobre `php:8.4-fpm-alpine` limpia — **~50 minutos**.
2. **Frontend**: `npm run build` de Vite dentro del Dockerfile transformaba 771 modulos en el Droplet — **~32 minutos**.

### Solucion Implementada: Cero Compilacion en el Servidor

#### Tarea 1: Backend con Imagen Pre-Compilada [🟢 Completado]
`backend/Dockerfile.prod` reescrito sobre **`serversideup/php:8.4-fpm-alpine`** (imagen de produccion de la comunidad optimizada para Laravel):
- Extensiones ya incluidas de forma nativa: `pdo_pgsql`, `pgsql`, `zip`, `gd`, `intl`, `bcmath`, `opcache`, `mbstring`, `pcntl`, `redis` — se eliminaron por completo `apk add`, `docker-php-ext-install` y `pecl install`.
- **Cache de Docker eficiente en Composer**: la etapa `vendor` copia UNICAMENTE `composer.json` + `composer.lock` antes de `composer install --no-dev --optimize-autoloader`; la capa solo se invalida cuando cambian dependencias, no el codigo fuente. Al correr sobre la misma imagen serversideup, se elimino `--ignore-platform-reqs` (validacion real de plataforma PHP + extensiones).
- **Nota de version**: se eligio el tag `8.4-fpm-alpine` (no `8.3`) porque `composer.lock` resuelve componentes Symfony que requieren `php >= 8.4.1` — el runtime anterior ya era `php:8.4-fpm-alpine`, por lo que se mantiene paridad exacta de version.
- Se conservan: `opcache.ini` custom (JIT 1255, validate_timestamps=0), `docker-entrypoint.prod.sh` (storage:link, caches, queue worker, Reverb, artisan serve), `USER www-data`, `EXPOSE 8000 8080`.

#### Tarea 2: Frontend Nginx Etapa Unica (Build Local Pre-Construido) [🟢 Completado]
`frontend/Dockerfile.prod` rediseñado como **etapa unica de `nginx:alpine`** — ya NO contiene Node.js, `npm install` ni `npm run build`:
- `COPY frontend/nginx.conf` (SPA try_files + cache estaticos 1y + gzip) + `COPY frontend/dist/ /usr/share/nginx/html`.
- El bundle de Vite se compila en la **maquina del desarrollador** via `build-frontend.sh` (nuevo script raiz: `npm ci` + `npm run build` + instrucciones de commit).
- `frontend/dist/` ahora **SE VERSIONA en git** (removido de `.gitignore` raiz y de `frontend/.gitignore`) para viajar al Droplet con `git pull`.

#### Tarea 3: deploy.sh con Verificacion de Build Pre-Compilado [🟢 Completado]
- Nuevo **Paso 2/7**: verifica que `frontend/dist/index.html` exista tras el `git pull`; si falta, aborta con instrucciones exactas (`bash build-frontend.sh` + commit + push en local).
- Header del script documenta el flujo completo: (Local) build → commit dist → push; (Droplet) `deploy.sh` solo empaqueta estaticos y levanta imagenes pre-compiladas.
- El resto del pipeline se conserva: healthcheck backend, `migrate --force`, caches (config/route/event/view), `queue:restart`, `docker image prune`.

### Flujo de Despliegue Resultante
1. **(Local)** `bash build-frontend.sh` → genera `frontend/dist/`
2. **(Local)** `git add frontend/dist && git commit && git push origin main`
3. **(Droplet)** `bash deploy.sh` → `git pull` + `docker compose -f docker-compose.prod.yml up --build -d` (solo descarga imagenes base y copia archivos)

### Imagenes Base Utilizadas
| Servicio | Imagen Anterior | Imagen Nueva | Compilacion en Droplet |
| :--- | :--- | :--- | :--- |
| backend | `php:8.4-fpm-alpine` + build nativo de 10 extensiones | `serversideup/php:8.4-fpm-alpine` (pre-compilada) | Ninguna (solo composer install cacheado + COPY) |
| frontend | `node:22-alpine` (build Vite) → `nginx:alpine` | `nginx:alpine` etapa unica | Ninguna (COPY de dist/ pre-construido) |
| redis | `redis:7-alpine` | `redis:7-alpine` (sin cambios) | Ninguna |

### Tiempos de Build Estimados
| Fase | Antes | Despues |
| :--- | :--- | :--- |
| Backend (extensiones PHP) | ~50 min | ~30-60 seg (pull de imagen + composer cacheado) |
| Frontend (Vite 771 modulos) | ~32 min | ~5-10 seg (COPY de estaticos) — el build Vite corre en local |
| **Total en Droplet** | **~82 min** | **< 2 min** |

### Archivos Creados en esta Fase
- `build-frontend.sh` — Script local de compilacion Vite con validacion de dist/index.html e instrucciones de publicacion

### Archivos Modificados en esta Fase
- `backend/Dockerfile.prod` — Base serversideup/php:8.4-fpm-alpine sin compilacion nativa, etapa vendor cacheada solo con composer.json/lock
- `frontend/Dockerfile.prod` — Etapa unica nginx:alpine con COPY directo de frontend/dist/
- `deploy.sh` — Paso 2/7 de verificacion de build pre-compilado, header con flujo local→droplet, renumeracion 7 pasos
- `.gitignore` — Removido frontend/dist/ (ahora versionado)
- `frontend/.gitignore` — Removido dist (ahora versionado)

---

## 37. Flujo Post-Venta Manual y Configuracion Inicial del Agente (Frontend) [🟢 COMPLETADO Y OPERATIVO]

### Contexto del Problema
El POS disparaba impresion automatica al confirmar el cobro y varias vistas consultaban al agente local en cuanto se montaban (el hook `useCronosAgent` ejecutaba un `useEffect` de verificacion y `PrinterQueueMonitor` sondeaba la cola cada 9 segundos). En terminales sin agente instalado esto generaba llamadas en bucle a `http://127.0.0.1:9100`, parpadeo de estado en el Historico de Ventas y en Configuracion, y un navegador nuevo mostraba el agente como "Desconectado" antes de que el usuario hubiera intentado configurarlo.

**Principio adoptado:** ninguna vista habla con el agente local por su cuenta. Toda comunicacion con `127.0.0.1:9100` nace de una accion explicita del usuario (detectar, imprimir, consultar cola).

### Tarea 1: PrintConfirmationModal (Nuevo) [🟢 Completado]

#### Ubicacion: `src/components/pos/PrintConfirmationModal.jsx`
Modal que sustituye a la impresion automatica post-venta. Se abre desde `POSPage` una vez que `POST /api/orders` respondio con exito.

**Resumen visual del ticket (representacion en pantalla, no ESC/POS):**
- Encabezado con razon social y RFC de la configuracion de ticket activa
- **Folio** (8 primeros caracteres del UUID en mayusculas) y **Fecha** localizada (es-MX)
- **Metodo de Pago** en mayusculas
- **Lista de Productos** con cantidad, precio unitario, descuento por partida, promocion aplicada e importe de linea
- **Totales**: descuento global, subtotal, IVA (tasa dinamica), TOTAL, y si el pago fue en efectivo: Recibido y Cambio
- **Mensaje de Agradecimiento**: `footer_message` de la configuracion de ticket con fallback "¡Gracias por su compra!"
- Badge con el nombre de la impresora activa, o Tag "Sin impresora" si la terminal no tiene configuracion

**Acciones:**
| Boton | Comportamiento |
| :--- | :--- |
| **Imprimir Ticket** (primario) | Invoca `cronosAgent.printTicket(printerName, printerData)` con el `printer_name` guardado en localStorage. Toast de exito y cierre; ante error muestra el mensaje real del agente y mantiene el modal abierto para reintentar |
| **Omitir / No imprimir** (secundario) | Cierra el modal limpiamente. **Cero peticiones HTTP al agente.** El carrito ya quedo vacio, por lo que el POS queda listo para la siguiente venta |

- El boton de impresion se deshabilita si el backend no devolvio `printer_data` (banner informativo que remite al Historial de Ventas para reimprimir)
- El modal no es descartable por click en la mascara mientras hay una impresion en curso

#### Integracion en el flujo de venta
- `CheckoutModal.jsx`: eliminado el bloque de auto-impresion (`cronosAgent.printRaw(...)` tras el POST). Ahora entrega el control con `onSuccess(order, { printerData, ticketConfig })` y deja de recibir la prop `cronosAgent`
- `POSPage.jsx`: `handleCheckoutSuccess(order, meta)` limpia el carrito, refresca el catalogo y guarda `pendingPrint = { order, printerData, ticketConfig }` para abrir el `PrintConfirmationModal`. Las ordenes offline (`order._offline`) no abren el modal, ya que no generan `printer_data`

### Tarea 2: Eliminacion de Polling y Consultas Automaticas [🟢 Completado]

#### Hook `useCronosAgent.js`
- **Removido** el `useEffect` de montaje que llamaba a `checkConnection()` (`GET /api/printers`). Era la causa raiz de las llamadas en bucle: se ejecutaba en POSPage, SalesHistoryPage, SystemSettingsPage y CashRegisterClosingsPage con solo entrar a la vista
- **Removido** el metodo `checkConnection()`; su reemplazo explicito es `detectAgent()`
- Nuevo estado de tres valores exportado como `AGENT_STATUS`: `unknown` (sin verificar, estado inicial), `online`, `offline`. `unknown` **no** equivale a desconectado
- `saveAgentToken()` ya no dispara ninguna verificacion en cadena; solo persiste el valor
- Accesos a `localStorage` protegidos con try/catch (modo privado del navegador)

#### `PrinterQueueMonitor.jsx` (reescrito)
- **Eliminado** el `setInterval` de 9 segundos y el `useEffect` de arranque
- La cola se consulta **unicamente** con el boton **"Consultar Estado de Cola"** (`GET /api/printers/queue`), disponible en la vista de Configuracion
- Muestra la marca de tiempo de la ultima consulta, badge "Cola OK" o conteo de trabajos retenidos, y el error real del agente si la consulta falla

#### Historico de Ventas (`SalesHistoryPage.jsx`)
- Sin cambios de codigo necesarios: al desaparecer el efecto del hook, entrar al Historico ya no genera trafico hacia el agente. La reimpresion manual (`POST /api/orders/{id}/print` + `printRaw`) sigue siendo la unica ruta que contacta al agente desde esta vista

#### Cierres de Caja (`CashRegisterClosingsPage.jsx`)
- El boton "Imprimir PDF" ya no depende del flag `connected` (que sin sondeo automatico seria siempre falso). Ahora se habilita cuando existe impresora configurada (`cronosAgent.printerName`) y el error real del agente se reporta al intentar imprimir

### Tarea 3: Deteccion Manual del Agente en Configuracion [🟢 Completado]

#### `PrinterSetupPanel.jsx` (reescrito)
Flujo pensado para **navegadores nuevos sin configuracion previa en localStorage**:

1. **Estado inicial neutro** — La cabecera muestra `⚪ Agente sin verificar` (nunca "Desconectado") y un texto que aclara que esta terminal aun no tiene impresora guardada. No se ejecuta ninguna peticion al montar el panel
2. **Boton destacado "Detectar Agente Local"** — Ejecuta `cronosAgent.detectAgent()` → `GET http://127.0.0.1:9100/api/health`
3. **Si el agente responde:**
   - Estado visual `🟢 Agente Detectado vX.X` (version leida de `version` / `agent_version` / `data.version` del JSON de salud)
   - Se cargan y muestran **automaticamente** las impresoras de `GET /api/printers` en el Dropdown
   - Se muestra el campo **"Token de Seguridad del Agente"** (input password con toggle de visibilidad y boton "Guardar Token") y el enlace **"¿Donde encuentro mi token?"**, que despliega la instruccion: *"Haz clic derecho en el icono de Cronos junto al reloj para copiar tu token"* (con `config.json` como alternativa)
   - Si `/api/printers` responde con error de autenticacion, un banner ambar indica pegar el token y reintentar con "Actualizar Lista"
4. **Si el agente no responde:** estado `🔴 Agente No Detectado` con checklist de diagnostico: revisar que **`cronos-pos-agent.exe`** este en ejecucion, buscar el icono de Cronos junto al reloj y verificar que el firewall no bloquee el puerto 9100
5. **Terminal ya configurada** — Si localStorage tiene impresora o token, la seccion de impresoras/token se muestra directamente (sin obligar a re-detectar), junto al banner de impresora predeterminada guardada
6. **Cola de impresion** — Seccion con el boton manual "Consultar Estado de Cola" (`PrinterQueueMonitor`)

### API del Hook useCronosAgent (Actualizada)
| Miembro | Tipo | Descripcion |
| :--- | :--- | :--- |
| `status` | string | `unknown` \| `online` \| `offline` (constantes en `AGENT_STATUS`) |
| `agentVersion` | string | Version reportada por `/api/health` |
| `detecting` | boolean | Deteccion en curso |
| `detected` / `connected` | boolean | `status === 'online'` (`connected` se conserva por compatibilidad) |
| `isConfigured` | boolean | Existe impresora persistida en localStorage |
| `printerName` | string | Impresora activa (`cronos_active_printer`) |
| `detectAgent()` | `GET /api/health` | Deteccion **manual**; retorna `{ ok, version, error }` sin lanzar excepciones |
| `getAvailablePrinters()` | `GET /api/printers` | Lista de impresoras (invocado tras una deteccion exitosa o con "Actualizar Lista") |
| `printTicket(printer, base64)` | `POST /api/print` | Impresion ESC/POS con impresora explicita |
| `printRaw(base64)` | `POST /api/print` | Wrapper con la impresora guardada |
| `printPDFDocument(printer, base64Pdf)` | `POST /api/print/pdf` | Impresion de PDF (cierres de caja) |
| `getPrinterQueue(printer)` | `GET /api/printers/queue` | Consulta **manual** de la cola |
| `savePrinterName(name)` / `saveAgentToken(token)` / `getAgentToken()` | localStorage | Persistencia local de la configuracion de la terminal |

### Matriz de Peticiones al Agente Local (Post-Refactor)
| Vista | Peticiones automaticas | Peticiones manuales |
| :--- | :--- | :--- |
| POS (venta) | Ninguna | `POST /api/print` al pulsar "Imprimir Ticket" en el PrintConfirmationModal |
| Historico de Ventas | Ninguna | `POST /api/print` al reimprimir un ticket |
| Configuracion del Sistema | Ninguna | `GET /api/health` (Detectar Agente Local), `GET /api/printers` (tras detectar o "Actualizar Lista"), `GET /api/printers/queue` (Consultar Estado de Cola) |
| Cierres de Caja | Ninguna | `POST /api/print/pdf` al imprimir un arqueo |

### Archivos Creados en esta Fase
**Frontend (nuevos):**
- `src/components/pos/PrintConfirmationModal.jsx` — Modal de confirmacion de impresion post-venta con resumen visual del ticket

### Archivos Modificados en esta Fase
**Frontend (modificados):**
- `src/hooks/useCronosAgent.js` — Eliminado el useEffect de verificacion automatica y `checkConnection()`; agregados `detectAgent()` (`/api/health`), `AGENT_STATUS`, `agentVersion`, `detecting`, `isConfigured`
- `src/components/pos/PrinterQueueMonitor.jsx` — Reescrito sin polling: consulta manual via boton "Consultar Estado de Cola"
- `src/components/settings/PrinterSetupPanel.jsx` — Reescrito: boton "Detectar Agente Local", estados ⚪/🟢/🔴, listado automatico de impresoras tras deteccion, campo de Token de Seguridad con ayuda contextual, sin peticiones al montar
- `src/components/pos/CheckoutModal.jsx` — Eliminada la impresion automatica post-venta; entrega `printerData` y `ticketConfig` via `onSuccess`; removida la prop `cronosAgent`
- `src/pages/pos/POSPage.jsx` — Estado `pendingPrint` y render de `PrintConfirmationModal` tras cada venta exitosa
- `src/pages/admin/CashRegisterClosingsPage.jsx` — Impresion de PDF condicionada a impresora configurada en lugar del flag de conexion sondeada
- `frontend/dist/` — Build de produccion regenerado

## 38. Optimizacion de Rendimiento de la Modal de Cobro (Cache Redis + Input de Efectivo en Tiempo Real) [🟢 COMPLETADO Y OPERATIVO]

Correccion de dos cuellos de botella en el flujo de cobro del POS: la latencia al abrir el `CheckoutModal` (consulta a PostgreSQL por el catalogo de metodos de pago) y la friccion del input de "Dinero Recibido", que solo calculaba el cambio y habilitaba el boton al perder el foco (`onBlur`).

### 38.1 Backend — Cache de Alto Rendimiento para Metodos de Pago

El catalogo de metodos de pago es un dato de baja cardinalidad y muy baja tasa de cambio, pero se consultaba a PostgreSQL en **cada apertura** de la modal de cobro. Ahora se sirve desde Redis (`CACHE_STORE=redis`).

#### Modelo: `PaymentMethod` (Cache de primer nivel)
| Miembro | Tipo | Descripcion |
| :--- | :--- | :--- |
| `CACHE_TTL` | const `3600` | Vigencia de 60 minutos; actua solo como red de seguridad porque la invalidacion real es por evento |
| `CACHE_PREFIX` | const `payment_methods:list:` | Prefijo de las llaves en Redis |
| `CACHE_STATUSES` | const `['all','active','inactive']` | Unicas variantes de filtro que acepta el endpoint, y por tanto las unicas llaves cacheadas |
| `cachedList(string $status)` | static | `Cache::remember()` sobre `payment_methods:list:{status}`. Normaliza cualquier status desconocido a `all` para impedir envenenamiento de llaves con input arbitrario del cliente |
| `flushCache()` | static | `Cache::forget()` sobre las 3 variantes |
| `booted()` | protected static | Registra `saved` y `deleted` -> `flushCache()` |

- **Se cachea el arreglo ya serializado** (`->get()->toArray()`), no la coleccion Eloquent: la respuesta del POS no paga hidratacion de modelos ni deserializacion de objetos. El JSON emitido es identico al anterior (los casts se aplican al construir el arreglo), por lo que **no hay cambio de contrato para el frontend**.
- **Invalidacion automatica por eventos del modelo**: cualquier alta, edicion (incluido el cambio de estatus) o baja hecha desde `PaymentMethodController` — o desde seeders, tinker o cualquier otro punto que use Eloquent — dispara `flushCache()`. No existe ventana de datos rancios tras una mutacion; el TTL de 60 minutos solo cubre escrituras que evadan Eloquent.

#### Controlador: `PaymentMethodController@index`
```php
$status = $request->filled('status') ? (string) $request->status : 'all';

return response()->json([
    'status' => 'success',
    'data'   => PaymentMethod::cachedList($status),
]);
```
- Eliminada la construccion del query builder en el controlador; toda la logica de lectura vive en el modelo.
- Los metodos `store()`, `update()` y `destroy()` quedan **sin cambios**: la invalidacion es transparente via los eventos del modelo.

#### Impacto
| Consumidor | Endpoint | Efecto |
| :--- | :--- | :--- |
| `CheckoutModal` (apertura de cobro) | `GET /api/payment-methods?status=active` | Hit de Redis; desaparece la latencia percibida al abrir la modal |
| `SalesHistoryPage` (filtro) | `GET /api/payment-methods` | Hit de Redis |
| `PaymentMethodsPage` (admin CRUD) | `GET /api/payment-methods?status=all` | Hit de Redis, invalidado en cada mutacion |

### 38.2 Frontend — Sanitizacion en Tiempo Real y Calculo Sincrono del Cambio

`src/components/pos/CheckoutModal.jsx` — Se **elimino el `InputNumber` de PrimeReact** (modo `currency`), cuyo `onValueChange` no confirmaba el valor hasta perder el foco o pulsar Enter, obligando al cajero a hacer un clic extra para que se calculara el cambio y se habilitara "Confirmar Cobro".

#### Helpers puros (fuera del componente)
| Funcion | Responsabilidad |
| :--- | :--- |
| `sanitizeAmount(raw)` | Filtro estricto ejecutado en cada tecla: normaliza coma a punto (teclados numericos latinos), descarta **todo** caracter que no sea digito o punto, conserva un unico separador decimal, recorta a 2 decimales y 9 enteros, y elimina ceros a la izquierda preservando el `0` de `0.50` |
| `parseAmount(value)` | Convierte el texto sanitizado a numero; retorna `null` mientras no haya monto valido (`''`, `'.'`) |

#### Nuevo modelo de estado
- Estado unico: `amountReceivedInput` (string). El numero `amountReceived` es **derivado** en cada render (`parseAmount(amountReceivedInput)`), no un segundo estado — no existe posibilidad de desincronizacion entre lo que se ve y lo que se calcula.
- El caracter invalido **nunca llega al estado**: la sanitizacion ocurre dentro del propio `onChange`, por lo que si el cajero teclea una letra o un simbolo, el input simplemente no lo refleja.

```jsx
<input
  type="text"
  inputMode="decimal"
  autoFocus
  value={amountReceivedInput}
  onChange={(e) => setAmountReceivedInput(sanitizeAmount(e.target.value))}
/>
```
- `type="text"` + `inputMode="decimal"`: abre el teclado numerico en terminales tactiles sin heredar el comportamiento de `type="number"` (spinners, `valueAsNumber` vacio ante entradas parciales).
- `autoFocus`: el cajero teclea el monto en cuanto aparece el campo, sin clic previo.
- El simbolo `$` se renderiza como adorno absoluto (`pointer-events-none`) en lugar de formatear el valor, para no pelear con la escritura en curso.

#### Calculo y habilitacion instantaneos
Todo se deriva de forma sincrona en el mismo render de cada pulsacion:

| Derivado | Formula |
| :--- | :--- |
| `totalCents` / `receivedCents` | `Math.round(x * 100)` — comparacion en enteros para evitar falsos "insuficiente" por error de punto flotante (ej. `116.00` vs `115.999...`) |
| `amountChange` | `Math.max(0, receivedCents - totalCents) / 100` |
| `amountMissing` | `Math.max(0, totalCents - receivedCents) / 100` |
| `cashInsufficient` | `isCash && (receivedCents == null \|\| receivedCents < totalCents)` |

- El boton **"Confirmar Cobro"** conserva su `disabled={... \|\| (isCash && cashInsufficient)}`, pero ahora `cashInsufficient` se recalcula en cada tecla: se habilita/deshabilita en vivo, sin clics adicionales ni perdida de foco.
- El panel verde "Cambio a devolver" y el banner rojo "Faltan $X" reaccionan desde el primer digito introducido.
- El `TicketPreview` de la derecha refleja `amount_received` / `amount_change` en tiempo real via `previewOrder`.

#### Otros ajustes
- Se elimino el `useEffect` que limpiaba el monto al dejar de ser efectivo; ahora la limpieza ocurre en el `onChange` del Dropdown de metodo de pago (evento de origen en lugar de efecto en cascada). Esto tambien resuelve un error de la regla `react-hooks/set-state-in-effect` del linter.
- El payload envia `amount_received: Math.round(amountReceived * 100) / 100`, alineado con `amount_change`.
- `InputNumber` sigue en uso para el campo de descuento directo — el cambio esta acotado al input de efectivo.

### Archivos Modificados en esta Fase
**Backend (modificados):**
- `app/Models/PaymentMethod.php` — Constantes de cache, `cachedList()`, `flushCache()` y `booted()` con invalidacion automatica en `saved`/`deleted`
- `app/Http/Controllers/Catalog/PaymentMethodController.php` — `index()` servido desde Redis via `PaymentMethod::cachedList()`

**Frontend (modificados):**
- `src/components/pos/CheckoutModal.jsx` — Helpers `sanitizeAmount()` / `parseAmount()`, estado `amountReceivedInput` (string) con `amountReceived` derivado, input nativo con sanitizacion en `onChange`, calculo del cambio y validacion de suficiencia en enteros de centavos, limpieza del monto en el Dropdown de metodo de pago
- `frontend/dist/` — Build de produccion regenerado

## 39. FASE 7: SISTEMA DE GESTIÓN DE MESAS (DINE-IN) [🟢 IMPLEMENTADO Y OPERATIVO]

Incorporacion del flujo de restaurante al POS: una mesa se abre, acumula consumo en rondas sucesivas y se cobra al final, liberandose sola. El modulo es transaccional de extremo a extremo y no altera el flujo de mostrador, que sigue funcionando exactamente igual.

### 39.1 Decision de Arquitectura: la cuenta de mesa ES una orden

En lugar de inventar una tabla paralela de consumos, la cuenta viva de una mesa **es una `order` real en el nuevo estado `open`**. Esto evita duplicar la maquinaria de precios, IVA, promociones, ticket e impresion, y hace que el cobro sea una transicion de estado en lugar de una migracion de datos entre tablas.

La viabilidad se apoya en un hecho verificado del codigo existente: **todas** las agregaciones financieras del sistema (Dashboard, Analytics, Daily Summary, Cierres de Caja) ya filtraban explicitamente `status = 'completed'`, por lo que las cuentas abiertas quedan fuera de los reportes sin tocar una sola consulta.

Los dos unicos puntos que leian ordenes sin filtrar estatus se blindaron con el nuevo scope `Order::settled()`:

| Punto | Antes | Ahora |
| :--- | :--- | :--- |
| `OrderController@index` (Historial) | `Order::with(...)` | `Order::settled()->with(...)` |
| `SalesExportController@export` (Excel) | `Order::with(...)` | `Order::settled()->with(...)` |

Ademas, `OrderController@cancel` rechaza con `ERR_ORDER_STILL_OPEN` la cancelacion de una cuenta de mesa: cancelarla ahi dejaria la mesa ocupada para siempre, porque no liberaria la sesion.

### 39.2 Base de Datos (4 migraciones)

| # | Migracion | Contenido |
| :--- | :--- | :--- |
| `2026_07_31_000001` | `add_open_to_order_status_enum` | `ALTER TYPE order_status ADD VALUE 'open'`. Corre con `$withinTransaction = false` (PostgreSQL prohibe `ALTER TYPE ... ADD VALUE` dentro de un bloque transaccional). El `down()` recrea el tipo, ya que PostgreSQL no permite eliminar valores de un ENUM. |
| `2026_07_31_000002` | `create_tables_table` | ENUM nativo `table_status ('available','occupied','reserved')` + tabla `tables` |
| `2026_07_31_000003` | `add_dine_in_columns_to_orders_table` | `table_id`, `table_name_at_sale`, `waiter_id`, `waiter_name_at_sale` en `orders`; `payment_method_id` y `cash_register_id` pasan a NULLABLE |
| `2026_07_31_000004` | `create_table_sessions_table` | ENUM nativo `table_session_status ('open','closed','canceled')` + tabla `table_sessions` + indice unico parcial |

#### Tabla `tables`
| Columna | Tipo | Notas |
| :--- | :--- | :--- |
| `id` | UUID PK | |
| `name` | VARCHAR(60) UNIQUE | "Mesa 12", "Barra 3" |
| `capacity` | SMALLINT | Comensales, default 4 |
| `zone` | VARCHAR(60) NULL | Salón / Terraza / Barra (indexada) |
| `status` | `table_status` | available / occupied / reserved |
| `is_active` | BOOLEAN | Baja logica del catalogo sin perder historico |

#### Tabla `table_sessions`
| Columna | Tipo | Notas |
| :--- | :--- | :--- |
| `id` | UUID PK | |
| `table_id` | UUID FK → tables | `restrict` |
| `order_id` | UUID FK → orders | `restrict` — la orden base generada en la apertura |
| `user_id` | UUID FK → users | Mesero que abrio |
| `closed_by` | UUID FK → users NULL | Quien ejecuto el cobro |
| `guests`, `notes` | SMALLINT / TEXT | Comensales y nota de servicio |
| `status` | `table_session_status` | open / closed / canceled |
| `opened_at`, `closed_at` | TIMESTAMPTZ | Ciclo de vida real de la mesa |

#### Garantia de concurrencia a nivel de motor
```sql
CREATE UNIQUE INDEX table_sessions_one_open_per_table
  ON table_sessions (table_id) WHERE status = 'open';
```
Un indice **unico parcial**: una mesa no puede tener dos sesiones abiertas simultaneas aunque dos meseros pulsen "Abrir" en el mismo milisegundo, y a la vez admite cuantas sesiones cerradas historicas haga falta. La defensa aplicativa (`lockForUpdate` + verificacion de estatus) va por delante, pero **la garantia real la da el motor**: el controlador captura la violacion `23505` y la traduce a `ERR_TABLE_ALREADY_OPEN`.

#### Por que `payment_method_id` y `cash_register_id` pasan a NULLABLE
Una cuenta abierta todavia no tiene forma de pago ni cajon asignado: ambos se resuelven en el cobro. Modelarlos como NULL es mas honesto que sembrar un valor falso en la apertura. El `byPayment` del Daily Summary usa un `INNER JOIN` contra `payment_methods`, de modo que las cuentas abiertas tampoco se cuelan por ahi.

### 39.3 Motor Unico de Calculo: `App\Services\OrderCalculator`

La aritmetica monetaria de `OrderController@store` se **extrajo tal cual** a un servicio compartido por mostrador y comedor. Un centavo de divergencia entre ambos flujos rompe el arqueo de caja, y mantener dos copias de la formula es como se llega a esa divergencia.

| Metodo | Responsabilidad |
| :--- | :--- |
| `promotionDiscount(Promotion, float)` | Descuento de una promocion sobre el bruto de la linea (percentage / fixed_amount / freebie_100) |
| `line(Product, ?Promotion, int)` | Construye la linea cruda de un producto |
| `linesFromOrderItems(Collection)` | Reconstruye lineas crudas desde items ya persistidos |
| `compose(lines, taxRate, discountType, discountValue)` | Prorratea el descuento global y despeja subtotal e IVA (tax-inclusive) |

**Invariante que hace posible el comedor:** `line_gross = final_price_at_sale + discount_amount_at_sale`, exacto por construccion de `compose()`. Permite recomponer la cuenta desde los items guardados sin columnas auxiliares, mientras la orden no arrastre un descuento global previo — que es justo la invariante de una cuenta abierta (`discount_type = 'none'` hasta el cobro).

**Verificacion del refactor:** se comparo el algoritmo original contra el extraido sobre **7,200 casos generados** (3 tasas de IVA × 6 configuraciones de descuento × 400 ordenes aleatorias con promociones por linea): **0 divergencias** y **0 fallos de reconstruccion** de `line_gross`.

> Nota de redondeo **preexistente** (identica antes y despues del refactor): al prorratear un descuento global, la suma de los `final_price_at_sale` de los items puede diferir del `total` de la orden hasta en **2 centavos**, en el 0.14% de las ordenes con descuento. El `total` de la orden es el valor autoritativo para el cobro y el arqueo.

### 39.4 Endpoints del Backend (7 rutas)

| Metodo | Ruta | Middleware | Descripcion |
| :--- | :--- | :--- | :--- |
| GET | /api/tables | auth, user.active | Plano de mesas con estatus, sesion activa, consumo acumulado y resumen por estatus. Filtros: `zone`, `status`, `include_inactive` |
| GET | /api/tables/{table} | auth, user.active | Detalle de la mesa con el desglose completo de su cuenta viva |
| POST | /api/tables/{table}/open | auth, user.active | Abre la mesa, la vincula al mesero y genera la orden base en estado `open` |
| POST | /api/tables/{table}/items | auth, user.active | Agrega una comanda a la cuenta activa |
| POST | /api/tables/{table}/close | auth, user.active | Cobra la cuenta, sella la venta y libera la mesa |
| POST/PUT/DELETE | /api/tables[/{table}] | **role:admin,manager** | Catalogo de mesas (alta, edicion, baja) |

#### `TableSessionController` — las tres operaciones criticas
Las tres corren dentro de `DB::transaction` con `lockForUpdate()` sobre la fila de la mesa, que actua como **punto unico de serializacion del comedor**: dos meseros que tocan la misma mesa a la vez se forman uno detras del otro en lugar de duplicar sesiones o comandas.

**`open()`** — Valida ticket activo y mesa dada de alta → bloquea la fila → verifica `status = available` → crea la orden base (totales en 0, `discount_type = 'none'`, snapshot de `table_name_at_sale` y `waiter_name_at_sale`) → crea la sesion → marca la mesa `occupied` → `AuditLog: table_session_opened`.

**`addItems()`** — Bloquea mesa, sesion, orden y **cada producto** (`lockForUpdate`) → valida stock disponible (`ERR_POS_INSUFFICIENT_STOCK`) → valida el limite de 1 promocion sobre la cuenta **completa** (existentes + nuevos) → descuenta inventario → inserta los items nuevos y recompone los totales.
> Los items previos **no se reescriben**: sin descuento global, la aritmetica de cada linea es independiente, asi que su `created_at` queda intacto — y con el, la trazabilidad ronda por ronda.

**`close()`** — Exige caja abierta **del cobrador** (`ERR_POS_CASH_REGISTER_REQUIRED`) → bloquea sesion y orden → rechaza cuentas vacias (`ERR_TABLE_EMPTY_ORDER`) → recompone la cuenta aplicando el descuento global y **actualiza los items en sitio** (via la clave `ref`, sin borrarlos) → sella la orden como `completed` con metodo de pago, cajon del cobrador y ticket vigente → abona `expected_closing_balance` → cierra la sesion → libera la mesa → `AuditLog: table_session_closed` → devuelve `printer_data` para el `PrintConfirmationModal`.

> **Reconocimiento del ingreso:** en el cobro se resella `orders.created_at` con la hora del pago. En todo el resto del sistema `created_at` ES el instante del cobro (las ordenes de mostrador nacen en el checkout); preservar esa invariante mantiene correctos los reportes por fecha y los cortes del dia. La hora de apertura de la mesa queda registrada en `table_sessions.opened_at`.

#### Catalogo de errores del modulo
| Codigo | HTTP | Situacion |
| :--- | :--- | :--- |
| `ERR_TABLE_ALREADY_OPEN` | 422 | La mesa ya tiene cuenta abierta (incluye la carrera perdida contra el indice unico) |
| `ERR_TABLE_NOT_AVAILABLE` | 422 | La mesa esta reservada |
| `ERR_TABLE_INACTIVE` | 422 | Mesa dada de baja del catalogo |
| `ERR_TABLE_NO_OPEN_SESSION` | 422 | Se intento comandar o cobrar una mesa libre |
| `ERR_TABLE_EMPTY_ORDER` | 422 | Cobro de una mesa sin consumos |
| `ERR_TABLE_OCCUPIED` | 422 | Cambio de estatus o baja de una mesa con cuenta viva |
| `ERR_TABLE_HAS_SESSIONS` | 422 | Baja de una mesa con historico (sugiere desactivar) |
| `ERR_POS_INSUFFICIENT_STOCK` | 422 | Stock insuficiente al agregar la comanda |
| `ERR_ORDER_STILL_OPEN` | 422 | Cancelacion de una cuenta de mesa desde el historial |

`App\Exceptions\TableConflictException` permite abortar la transaccion desde dentro del closure sin dejar escrituras a medias, traduciendose al catalogo de errores corporativo homogeneo.

### 39.5 Histórico de Auditoría y Trazabilidad

- `orders.table_id` (FK) + `orders.table_name_at_sale` — el **snapshot del nombre** sigue la convencion `_at_sale` de la casa: si la mesa se renombra o se da de baja, el ticket historico conserva el nombre con el que se consumio.
- `orders.waiter_id` (FK) + `orders.waiter_name_at_sale` — mesero que abrio la cuenta, con snapshot equivalente.
- `order_items.created_at` — **se sella explicitamente** al insertar (el modelo tiene `$timestamps = false`, por lo que Eloquent no lo hacia y la columna quedaba NULL). Es la hora de comanda de cada producto y sostiene la trazabilidad por rondas. Se le agrego el cast `datetime` y `serializeDate` a zona `America/Mexico_City`.
- `AuditLog` registra las tres transiciones (`table_session_opened`, `table_items_added`, `table_session_closed`) con mesa, mesero, cobrador, productos, total y minutos de ocupacion.

**Vista de detalle del histórico** — Para ordenes de comedor aparece un panel indigo con: mesa consumida, mesero que atendio, comensales, hora de apertura de la mesa, quien cobró y la nota de servicio. La tabla de productos suma una columna **"Comandado"** con la hora de cada alta, y el DataTable principal suma una columna **"Origen"** que distingue mesa+mesero de "Mostrador". El **Excel** de exportacion suma las columnas **Mesa** y **Mesero** (rango A..K).

**Ticket impreso** — `TicketPreview` imprime las lineas `Mesa:` y `Atendio:` unicamente cuando la venta proviene del comedor.

### 39.6 Interfaz de Usuario (React)

#### Botón de Mesas en el header del POS — a la izquierda del reloj
En la "Shift Status Card" de `POSPage`, el bloque del reloj se envolvio junto al nuevo boton en un contenedor `ml-auto flex items-center gap-3`, quedando el boton **estrictamente a la izquierda del reloj** y ambos anclados a la derecha de la barra. Icono SVG de mesa con cubiertos, etiqueta "Mesas" (oculta en movil), hover indigo. Navega a `/mesas`.

#### `TablesFloorPlanPage` (`/mesas`) — Plano de Mesas
- **Codigo de color por estatus** (`components/dining/tableStatus.js`, lenguaje visual unico): verde esmeralda = Disponible, rojo rosa = Ocupada, ambar = Reservada. Punto de color, badge y borde de tarjeta comparten la paleta para que el salon se lea de un vistazo.
- Tarjeta de mesa: nombre, capacidad, zona, estatus y —si esta ocupada— mesero, comensales, **tiempo transcurrido** ("1h 25m"), numero de productos y **consumo acumulado**.
- Chips de resumen (disponibles / ocupadas / reservadas), filtro por zona y boton de actualizacion manual.
- **Sin polling**: se revalida al recuperar el foco de la ventana y tras cada operacion, respetando la decision de la casa de no sondear en bucle.
- Clic en mesa disponible → modal de apertura (comensales precargados con la capacidad + nota opcional) → al abrir, **encadena directo al detalle** para tomar la orden.
- Clic en mesa ocupada → detalle de la cuenta.

#### `TableDetailModal` — Consumo al instante
- Cabecera con mesero, comensales, tiempo de ocupacion y consumo acumulado en grande.
- Columna izquierda: buscador y catalogo de productos. **Cada clic dispara `POST /tables/{id}/items` al instante** — el endpoint es transaccional, de modo que la comanda queda firme antes de que el mesero levante el dedo, y la respuesta trae la sesion recalculada (sin segunda peticion).
- Columna derecha: cuenta viva en orden de comanda con **hora de alta por partida**, desglose de subtotal/IVA/total y boton **"Cobrar Cuenta"**.

#### Cobro: `CheckoutModal` reutilizado en modo mesa
Se agrego la prop opcional `tableSession`. Cuando viene informada, la cuenta ya existe en el servidor y el submit va a `POST /tables/{id}/close` en lugar de crear una orden nueva; el resto —descuentos directos, cupones, efectivo con calculo en tiempo real, preview de ticket e impresion post-venta— es **identico al mostrador**. La cuenta del servidor se traduce a la forma de carrito que el modal ya entendia usando la invariante `line_gross = final + descuento`. La ruta offline queda desactivada en modo mesa: un cobro de mesa exige servidor.

#### `TablesPage` (`/admin/mesas`) — Catálogo en el sidebar
Entrada **"Mesas"** en el grupo **ADMINISTRACIÓN** del sidebar. DataTable con nombre, capacidad, zona, estatus, cuenta abierta (mesero + consumo), alta y acciones. Alta/edicion en modal; el estatus se oculta y se explica cuando la mesa esta ocupada, porque lo gobierna su sesion. La baja solo procede si la mesa nunca tuvo consumos; en caso contrario se sugiere desactivarla.

### 39.7 Seeder
`DatabaseSeeder` siembra un plano base de 8 mesas en 3 zonas: Salón (4), Terraza (3) y Barra (1).

### Archivos Creados en esta Fase
**Backend (nuevos):**
- `database/migrations/2026_07_31_000001_add_open_to_order_status_enum.php`
- `database/migrations/2026_07_31_000002_create_tables_table.php`
- `database/migrations/2026_07_31_000003_add_dine_in_columns_to_orders_table.php`
- `database/migrations/2026_07_31_000004_create_table_sessions_table.php`
- `app/Models/Table.php`, `app/Models/TableSession.php`
- `app/Services/OrderCalculator.php`
- `app/Exceptions/TableConflictException.php`
- `app/Http/Controllers/Dining/TableController.php`
- `app/Http/Controllers/Dining/TableSessionController.php`
- `app/Http/Requests/Table/StoreTableRequest.php`, `UpdateTableRequest.php`
- `app/Http/Requests/TableSession/OpenTableRequest.php`, `AddTableItemsRequest.php`, `CloseTableRequest.php`

**Frontend (nuevos):**
- `src/pages/dining/TablesFloorPlanPage.jsx`
- `src/pages/admin/TablesPage.jsx`
- `src/components/dining/TableDetailModal.jsx`
- `src/components/dining/tableStatus.js`

### Archivos Modificados en esta Fase
**Backend (modificados):**
- `app/Models/Order.php` — Constantes de estatus, campos dine-in en fillable, relaciones `table()`/`waiter()`/`tableSession()`, scope `settled()`, `$table` explicito
- `app/Models/OrderItem.php` — `created_at` en fillable, cast `datetime` y `serializeDate`
- `app/Http/Controllers/Sales/OrderController.php` — Usa `OrderCalculator`, `settled()` en el historial, eager loading de mesa/mesero, filtros `table_id`/`dine_in_only`, guarda `created_at` en los items, bloqueo de cancelacion de cuentas abiertas
- `app/Http/Controllers/Sales/SalesExportController.php` — `settled()` y columnas Mesa/Mesero en el Excel
- `routes/api.php` — 7 rutas nuevas bajo `tables`
- `database/seeders/DatabaseSeeder.php` — Plano base de 8 mesas

**Frontend (modificados):**
- `src/pages/pos/POSPage.jsx` — Boton de mesas a la izquierda del reloj en el header
- `src/components/layout/Sidebar.jsx` — NavLink "Mesas" en ADMINISTRACIÓN
- `src/components/pos/CheckoutModal.jsx` — Prop `tableSession` y cobro contra `/tables/{id}/close`
- `src/components/pos/TicketPreview.jsx` — Lineas `Mesa:` y `Atendio:` en ventas de comedor
- `src/pages/sales/SalesHistoryPage.jsx` — Columna "Origen", panel de trazabilidad de comedor y columna "Comandado" en el detalle
- `src/App.jsx` — Rutas `/mesas` y `/admin/mesas`
- `src/hooks/usePageTitle.js` — Titulos de las dos vistas nuevas
- `frontend/dist/` — Build de produccion regenerado

## 40. FASE 8: SISTEMA ENTERPRISE — CIERRES AUTOMÁTICOS, NOTIFICACIONES TAGGEADAS, ANALÍTICA FINANCIERA Y AUDITORÍA INMUTABLE [🟢 COMPLETADO Y OPERATIVO]

Suite corporativa de cuatro módulos entrelazados: el scheduler cierra las cajas olvidadas a las 21:00 bajo una identidad de sistema, ese cierre dispara una notificación estructurada que la campana del header renderiza con plantilla propia, y todo cierre —humano o automático— queda expuesto en una vista de auditoría forense exclusiva de administradores. La analítica financiera mensual completa la toma de decisiones desde el Dashboard.

### 40.1 Motor de Cierre Automatizado de Caja (Laravel Scheduler)

#### Estructura del Schedule (`routes/console.php`)
```php
Schedule::command('cronos:auto-close-registers')
    ->dailyAt('21:00')
    ->timezone('America/Mexico_City')
    ->withoutOverlapping()
    ->onOneServer();
```
El cron de produccion ya invoca `php artisan schedule:run` cada minuto (FASE 7, crontab del Droplet), por lo que el schedule engancha sin cambios de infraestructura. `withoutOverlapping` evita dobles corridas si una ejecucion se alarga; `onOneServer` cubre despliegues multi-contenedor.

#### Comando: `app/Console/Commands/AutoCloseCashRegisters.php`
- Firma: `cronos:auto-close-registers {--dry-run}` (el flag lista las cajas candidatas sin cerrar nada).
- **Alcance**: toda caja con `closed_at IS NULL` y sin arqueo (`whereDoesntHave('closing')`) — incluidas las **rezagadas de dias anteriores** que un cajero olvido cerrar, exactamente para evitar inconsistencias por dias sin cierre.
- **Transaccionalidad**: cada caja se cierra en su **propia** `DB::transaction` con `lockForUpdate` sobre la fila de la caja (dentro de `CashClosingService::close`). Un fallo en una caja no bloquea el cierre de las demas, y la carrera contra un cierre manual simultaneo (cajero cerrando a las 20:59) se resuelve limpiamente: el perdedor recibe `ERR_REGISTER_ALREADY_CLOSED` y el comando la omite sin duplicar.
- **Identidad**: firma como usuario **System Automated Process** (`User::systemProcess()`).
- **Convencion contable del cierre automatico**: el sistema no cuenta dinero fisico, por lo que asienta `declared = expected` (diferencia declarada cero) y lo deja explicito en `notes`: *"Montos declarados no verificados fisicamente... Requiere conciliacion del efectivo al siguiente turno"*. La marca `is_automated = true` distingue estos cierres en toda la auditoria.
- Al terminar, si cerro al menos una caja, emite la notificacion `auto_cash_closing` a todos los administradores activos.

#### Usuario de Sistema (migracion `2026_08_01_000003_create_system_process_user`)
- `system@cronos.pos` / "System Automated Process". Sembrado por **migracion idempotente** (no seeder) para que exista tambien en las bases de produccion ya desplegadas.
- Inoperable por diseño: password aleatorio de 64 caracteres que nadie conoce, **sin rol asignado** (el RBAC le niega todo endpoint), sin 2FA. Solo existe como FK de auditoria (`closed_by`).
- Constantes en el modelo: `User::SYSTEM_EMAIL`, `User::systemProcess()`, `User::isSystemProcess()`.
- El `down()` de la migracion NO lo elimina: los cierres historicos lo referencian con FK `restrict`.

#### Servicio: `App\Services\CashClosingService` (motor unico del arqueo)
La aritmetica del arqueo (`Esperado = Fondo + Ventas('completed') − Caja Chica`, breakdown por metodo de pago) se **extrajo** de `CashRegisterClosingController::store` a este servicio, compartido por el cierre manual y el automatico — la misma filosofia que `OrderCalculator` en Fase 7: dos copias de la formula es como se llega a un arqueo que no cuadra.
- `snapshot(CashRegister)` — radiografia financiera sin efectos secundarios.
- `close(CashRegister, User $closedBy, ?array $declarations, bool $automated, ?string $notes)` — transaccional con `lockForUpdate`; `declarations = null` activa la convencion del cierre automatico. Lanza `ERR_REGISTER_ALREADY_CLOSED` si pierde la carrera.
- El controller manual ahora traduce esa excepcion al catalogo corporativo con el mensaje "posiblemente por el cierre automatico de las 21:00".

#### Inmutabilidad tipo Ledger (insert-only)
`cash_register_closings` ya bloqueaba `updating`/`deleting` a nivel Eloquent con `RuntimeException` (`ERR_CLOSING_IMMUTABLE`). Fase 8 agrega las columnas `is_automated` (boolean, indexada) y `notes` (text, nace con el registro y nunca se edita) via migracion `2026_08_01_000002`.

### 40.2 Sistema de Notificaciones Estructuradas por Tags JSON

#### Tabla `system_notifications` (migracion `2026_08_01_000001`)
| Columna | Tipo | Notas |
| :--- | :--- | :--- |
| `id` | UUID PK | |
| `user_id` | UUID FK → users | `cascade` |
| `type` | VARCHAR(60) indexado | **Etiqueta de renderizado**: el frontend la evalua para elegir plantilla |
| `data` | JSONB | **Snapshot inmutable** del evento; nunca se reconstruye desde tablas vivas |
| `read_at` | TIMESTAMPTZ NULL | Acuse de lectura — la UNICA columna mutable |
| `created_at` | TIMESTAMPTZ | |

Indice parcial `system_notifications_unread ON (user_id) WHERE read_at IS NULL`: el badge de no-leidas (la consulta mas frecuente) se sirve sin recorrer el historico leido.

#### Modelo `SystemNotification` — contrato de inmutabilidad
El guard de `booted()` permite el update **solo si** las columnas dirty ⊆ `{read_at}`; cualquier otro update y todo delete lanzan `ERR_NOTIFICATION_IMMUTABLE`. Helper `notifyAdmins(type, data)` emite a todos los admins activos.

#### Esquema JSON del tag `auto_cash_closing`
```json
{
  "executed_at": "2026-08-01T21:00:03-06:00",
  "executed_by": "System Automated Process",
  "registers_closed": 2,
  "registers_failed": 0,
  "total_expected": 15230.50,
  "total_declared": 15230.50,
  "total_difference": 0,
  "closings": [
    {
      "closing_id": "uuid", "cash_register_id": "uuid",
      "register_folio": "A1B2C3D4",
      "operator_id": "uuid", "operator_name": "Juan Perez",
      "opened_at": "2026-08-01T09:12:00-06:00",
      "opening_balance": 500.00,
      "expected_amount": 7615.25, "declared_amount": 7615.25,
      "difference_amount": 0,
      "was_stale": false
    }
  ]
}
```
`was_stale = true` marca cajas rezagadas de dias anteriores.

#### Endpoints REST
| Metodo | Ruta | Middleware | Descripcion |
| :--- | :--- | :--- | :--- |
| GET | /api/notifications | auth, user.active | `{unread[≤50], recent[≤10 leidas], unread_count}` del usuario autenticado |
| POST | /api/notifications/{id}/read | auth, user.active | Marca leida; **403 `ERR_NOTIFICATION_FORBIDDEN`** si la notificacion es de otro usuario |

#### Frontend — Campana conectada y Modal de Plantillas Dinamicas
- **`NotificationBell`** (header): reemplaza el boton muerto. Badge de no-leidas, polling cada 60s (mismo cadence que las quick-stats del header) + refresh al abrir. Panel OverlayPanel con la lista catalogada por tipo y **chips de filtro por tag**.
- **"Ver Detalles"** marca la notificacion como leida y abre **`NotificationDetailModal`**, que evalua `type` contra el **registro de tipos** (`notificationTypes.js`) e inyecta dinamicamente la plantilla correspondiente.
- **Registro extensible**: agregar un nuevo tipo = registrar `{label, icon, chip, iconBox, summary(data), Detail}` en `NOTIFICATION_TYPES`; la campana y la modal no se tocan. Tipo desconocido → `DEFAULT_TYPE` con render generico key-value (nunca rompe la campana).
- Plantilla `auto_cash_closing` (`NotificationDetailTemplates.jsx`): KPIs (cajas cerradas / total esperado / fallidas), tabla por caja (folio, operador, esperado, diferencia, tag "DIA PREVIO" para rezagadas) y banner de advertencia de conciliacion.

### 40.3 Modal de Analitica Financiera Mensual (Dashboard)

#### Backend — `GET /api/dashboard/monthly-analytics` (role:admin,manager)
Controlador invocable `MonthlyAnalyticsController`. Parametro `month` (`Y-m`, default mes corriente; el dia se ancla explicitamente al 1 para evitar el desborde de `createFromFormat` a fin de mes). Una sola respuesta con todas las agregaciones (PostgreSQL directo, `status='completed'`, hora local `America/Mexico_City`):
- `totals` — ventas totales, neto sin IVA, IVA, descuentos, ordenes, ticket promedio.
- `previous` + `comparison` — mismos totales del mes anterior y deltas porcentuales (null si no hay base de comparacion).
- `by_payment_method` — distribucion por metodo (ordenes + total).
- `top_products` — top 10 por ingreso.
- `peak_hours` — 24 horas del dia con ordenes/total (horas pico).
- `daily_trend` — serie diaria del mes.

#### Frontend — `MonthlyAnalyticsModal`
- Boton destacado (gradiente indigo→violeta) en la cabecera del Dashboard, **visible solo para admin/manager** (`user.roles`), ademas del candado backend.
- **Estado de carga robusto**: spinner animado + **esqueleto visual** (4 KPI placeholders + 2 bloques de chart + 1 tabla, `animate-pulse`) para latencias con volumen alto.
- Navegacion de meses (← →, bloqueado a futuro), KPIs con chips de delta (verde sube / rojo baja), PieChart de metodos de pago, BarChart de horas pico, LineChart de tendencia diaria, tabla top 10 productos.
- **"Exportar Reporte"**: CSV estructurado generado client-side desde los mismos datos mostrados (lo que exportas es lo que ves), con BOM UTF-8 para Excel. Secciones: metricas + comparativa, distribucion por metodo, top productos, ventas por hora.

### 40.4 Vista de Auditoria Historica de Cierres (Admin Only)

#### Politica de seguridad (doble candado + triple inmutabilidad)
| Capa | Mecanismo |
| :--- | :--- |
| Backend | Rutas `GET /api/admin/cash-closings-audit[/{closing}]` bajo middleware **`role:admin`** (exclusivo: manager NO entra) |
| Frontend | `/admin/cash-closings-audit` redirige a `/dashboard` a cualquier no-admin; entrada del sidebar con flag `adminOnly` visible solo para admin |
| Inmutabilidad 1 | El controlador no expone NINGUNA ruta de escritura sobre cierres |
| Inmutabilidad 2 | El modelo `CashRegisterClosing` lanza `RuntimeException` ante todo update/delete |
| Inmutabilidad 3 | La UI es de solo lectura: no existe boton de edicion ni borrado; sello visual "REGISTRO INMUTABLE" |

#### Endpoint `audit()` — filtros y resumen
`date_from`/`date_to`, `type` (`manual`/`automated`), `difference=nonzero`, `search` (nombre/email del operador, ilike). Paginado server-side. `metadata.summary` con conteos globales: automaticos, manuales y con diferencia.

#### Frontend — `CashClosingsAuditPage`
- DataTable **lazy paginada** con: folio de caja, fecha/hora, operador, "Cerrado por" (humano con email, o **System Job** con icono de engrane y leyenda "Proceso automatizado 21:00"), tag AUTOMÁTICO/MANUAL, esperado, declarado, diferencia coloreada (rojo faltante / verde sobrante).
- Chips de resumen global y filtros (tipo, rango de fechas, busqueda de operador).
- Click en fila → **modal de radiografia forense** (solo lectura): dinero calculado vs declarado vs diferencia en tarjetas, desglose completo por metodo de pago desde el JSONB `payment_breakdown`, responsable, y la nota del sistema si fue cierre automatico.

### Sidebar con RBAC visual
`Sidebar.jsx` ahora filtra items con flag `adminOnly` contra `user.roles` (via `useAuth`). Primera entrada que lo usa: "Auditoría de Cierres" en el grupo LOGÍSTICA.

### Archivos Creados en esta Fase
**Backend (nuevos):**
- `app/Console/Commands/AutoCloseCashRegisters.php`
- `app/Services/CashClosingService.php`
- `app/Models/SystemNotification.php`
- `app/Http/Controllers/Notifications/SystemNotificationController.php`
- `app/Http/Controllers/Dashboard/MonthlyAnalyticsController.php`
- `database/migrations/2026_08_01_000001_create_system_notifications_table.php`
- `database/migrations/2026_08_01_000002_add_automation_columns_to_cash_register_closings.php`
- `database/migrations/2026_08_01_000003_create_system_process_user.php`

**Frontend (nuevos):**
- `src/components/notifications/NotificationBell.jsx`
- `src/components/notifications/NotificationDetailModal.jsx`
- `src/components/notifications/NotificationDetailTemplates.jsx`
- `src/components/notifications/notificationTypes.js`
- `src/components/dashboard/MonthlyAnalyticsModal.jsx`
- `src/pages/admin/CashClosingsAuditPage.jsx`

### Archivos Modificados en esta Fase
**Backend (modificados):**
- `routes/console.php` — Schedule del cierre automatico (21:00, timezone, withoutOverlapping, onOneServer)
- `routes/api.php` — Rutas de notificaciones, analitica mensual (role:admin,manager) y auditoria (role:admin)
- `app/Models/User.php` — `SYSTEM_EMAIL`, `systemProcess()`, `isSystemProcess()`
- `app/Models/CashRegisterClosing.php` — `is_automated` y `notes` en fillable/casts
- `app/Http/Controllers/Finance/CashRegisterClosingController.php` — `store()` delegado a `CashClosingService` (con manejo de `ERR_REGISTER_ALREADY_CLOSED`), nuevos `audit()` y `auditShow()`

**Frontend (modificados):**
- `src/components/layout/AppHeader.jsx` — Boton muerto reemplazado por `NotificationBell`; titulos de rutas nuevas
- `src/components/layout/Sidebar.jsx` — Filtrado `adminOnly` por rol + entrada "Auditoría de Cierres"
- `src/pages/DashboardPage.jsx` — Boton destacado "Analítica Financiera" (gate admin/manager) + montaje de la modal
- `src/App.jsx` — Ruta `/admin/cash-closings-audit`
- `src/hooks/usePageTitle.js` — Titulo de la vista de auditoria
- `frontend/dist/` — Build de produccion regenerado

## 41. Correccion de Peticiones Infinitas en Historico de Ventas & Auditoria Global de Red [🟢 COMPLETADO]

### 41.1 Causa Raiz del Bug (SalesHistoryPage.jsx)

El componente cargaba las ordenes con un efecto **reactivo a la identidad de un callback**:

```js
const getDateParams = useCallback(..., [quickFilter, dateRange]);
const fetchOrders  = useCallback(..., [getDateParams, perPage, selectedPaymentMethod,
                                       selectedUser, totalMin, totalMax, selectedStatus]);
useEffect(() => { setPage(0); fetchOrders(0); }, [fetchOrders]);
```

Ese patron encadena la peticion HTTP a la identidad de `fetchOrders`, que a su vez depende transitivamente de **8 estados**. Dos consecuencias:

1. **Ciclo descontrolado**: la propia peticion provoca renders (`setLoading` → `setOrders` → `setLoading`). Basta con que UNA dependencia de la cadena se recree en cualquiera de esos renders (contexto de autenticacion resolviendo el usuario, doble montaje de StrictMode, cualquier estado intermedio) para cerrar el bucle *fetch → render → nueva identidad del callback → el efecto vuelve a disparar el fetch*. La peticion no tenia un dueño explicito: se disparaba como efecto colateral de la identidad de una funcion.
2. **Peticion por tecla**: los `InputNumber` de monto actualizaban `totalMin` en cada digito; cada digito recreaba `fetchOrders` y el efecto lanzaba una consulta al backend. Escribir "$1,500" costaba 4+ peticiones; seleccionar un rango de fechas disparaba con la primera fecha y otra vez con la segunda. No existia ninguna accion de "aplicar".

### 41.2 Solucion Aplicada (ciclo de vida explicito)

Se elimino por completo el acoplamiento reactivo. La carga de ordenes ahora tiene **duenos explicitos** y ningun `useEffect` depende de la identidad de un callback:

| Momento | Mecanismo |
| :--- | :--- |
| Montaje de la vista | Efecto con deps `[]` + guard `useRef` (`didInitialFetch`): **estrictamente 1 peticion**, blindada contra el doble montaje de StrictMode y contra cualquier re-render posterior |
| Filtro rapido (Hoy/Semana/Mes) | Click deliberado → `applyFilters({quickFilter})` en el propio `onChange` (la deseleccion del SelectButton se ignora para no lanzar consultas sin acotar) |
| Filtros avanzados | Los inputs **solo actualizan estado local**; la peticion sale unicamente con los nuevos botones **"Aplicar Filtros"** / **"Limpiar Filtros"** |
| Paginacion | `onPageChange` → `fetchOrders(pagina)` |
| Post-cancelacion de orden | `fetchOrders(page)` explicito |

- `buildParams(overrides)` y `fetchOrders(page, overrides)` son **funciones normales, no useCallback**: su identidad no participa en ningun efecto, asi que recrearlas por render es inocuo. `overrides` resuelve el clasico desfase de los setters de React dentro del mismo evento (el handler pasa el valor recien elegido sin esperar al re-render).
- El export a Excel reutiliza `buildParams()`: el archivo exporta exactamente lo que la tabla filtra.
- De paso se agrego el campo **Monto Maximo** al panel avanzado (el estado `totalMax` existia y el backend ya soportaba `total_max`, pero el input nunca se habia montado).

### 41.3 Auditoria Global de Llamadas de Red (todo el frontend)

Barrido transversal de `pages/`, `components/`, `hooks/` y `context/`: **83 `useEffect` auditados**.

**Resultado sano (sin accion):**
- **0 efectos sin array de dependencias** (ninguno corre en cada render).
- Todos los `setInterval` tienen `clearInterval` en el cleanup y son sondeos acotados y deliberados: quick-stats del header (60s), campana de notificaciones (60s), reloj del POS (1s).
- Todos los `addEventListener` tienen `removeEventListener`: `useOnlineStatus` (online/offline) y el revalidado por foco del plano de mesas.
- Los `setTimeout` restantes son one-shot dentro de handlers (impresion 350ms, seleccion en plantillas de correo, perfil) — sin fugas.
- Efectos `[callback]` verificados con cadenas de identidad **estables** (deps `[]` o solo estados que cambian por click deliberado): POSPage, TablesFloorPlanPage, TablesPage, UsersPage (3 dropdowns), CashRegisterClosingsPage (calendario/quick filter), NotificationPreferencesPage, ProductFormPage, MonthlyAnalyticsModal, TableDetailModal, NotificationBell.

**Anomalias encontradas y corregidas:**

| Componente | Anomalia | Correccion |
| :--- | :--- | :--- |
| `pages/admin/TrashPage.jsx` | El buscador lanzaba **una peticion HTTP por cada tecla** (`search` en las deps de `fetchItems`, que era dependencia del efecto) | **Debounce de 400ms** con `setTimeout` + cleanup: el input actualiza `search` al teclear, pero la peticion usa `debouncedSearch` y solo sale al dejar de escribir |
| `pages/admin/CashClosingsAuditPage.jsx` | Mismo anti-patron que el Historico (introducido en Fase 8): `fetchClosings` con `search` en deps + efecto `[isAdmin, fetchClosings]` → peticion por tecla del buscador | Migrado al patron explicito de 41.2: funcion normal + carga inicial unica con guard `useRef` al confirmar rol admin + dropdown/calendario disparan `fetchClosings(0, overrides)` en su `onChange`; la busqueda ya solo dispara con Enter o el boton |

### Archivos Modificados en esta Fase
- `src/pages/sales/SalesHistoryPage.jsx` — Refactor completo del ciclo de carga (41.2) + campo Monto Maximo + botones Aplicar/Limpiar
- `src/pages/admin/TrashPage.jsx` — Debounce de busqueda (400ms)
- `src/pages/admin/CashClosingsAuditPage.jsx` — Patron de carga explicito con guard de montaje
- `frontend/dist/` — Build de produccion regenerado
