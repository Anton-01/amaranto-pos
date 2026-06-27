# Estado Actual del Sistema POS - Cronos

## 1. Arquitectura General
- Backend: Laravel 13 (API-First), PHP 8.4 Alpine
- Frontend: React 18/19, Tailwind CSS v4, PrimeReact, Sonner
- Base de Datos: Managed PostgreSQL (DigitalOcean)
- Cache / Colas: Redis 7 Alpine (cache global + queue worker)
- WebSockets: Laravel Reverb (puerto 8080, tiempo real)
- Estado de Infraestructura: Docker Compose multi-contenedor operativo (4 servicios: backend, frontend, postgres, redis).
- Despliegue Produccion: [🟢 COMPLETADA Y LISTA PARA PRODUCCION] — DigitalOcean Droplet + Managed PostgreSQL, Docker multi-stage, Nginx proxy inverso HTTPS/WSS, Certbot SSL, deploy.sh automatizado.

## 2. Matriz de Modulos y Progreso
| Modulo | Estado Backend | Estado Frontend | Observaciones |
| :--- | :--- | :--- | :--- |
| Infraestructura & Docker | [🟢 Completado y Operativo] | [🟢 Completado y Operativo] | Docker Compose multi-contenedor, Alpine, Hot-Reload, Reverb WS :8080 |
| Despliegue Produccion (DigitalOcean) | [🟢 COMPLETADA Y LISTA PARA PRODUCCION] | [🟢 COMPLETADA Y LISTA PARA PRODUCCION] | Multi-stage Docker, Nginx HTTPS/WSS proxy, Managed PostgreSQL SSL, Certbot, deploy.sh, cron scheduler |
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
| Metodos de Pago Dinamicos | [🟢 Completado] | [🟢 Completado] | CRUD payment_methods, FK restrictOnDelete, seeder base (cash/card/transfer), despliegue dinamico en POS/checkout/historial |
| Transformacion SKU Mayusculas | [🟢 Completado] | [🟢 Completado] | Mutadores en modelo Product + onChange uppercase en frontend |
| Cierre de Caja (Blind Closing) | [🟢 Completado] | [🟢 Completado] | Inmutabilidad DB (booted events), arqueo ciego, desglose por metodo pago JSONB, export PDF/Excel/Email batch, historial forense /admin/cierres |
| Track Stock (Paquetes/Combos) | [🟢 Completado] | [🟢 Completado] | Columna track_stock en products, InputSwitch en formulario, pipeline ventas omite decremento si false, cancel reversa condicionada |
| Apertura de Caja (Fondo de Caja) | [🟢 Completado] | [🟢 Completado] | Apertura obligatoria con fondo inicial, bloqueo POS reactivo, auditoria forense (IP/UA/token), validacion en ordenes y caja chica, cierre alineado con formula (Fondo + Ventas - Retiros) |
| Bugs Track Stock (Inventario Flexible) | [🟢 Completado] | [🟢 Completado] | DataTable muestra Tag ILIMITADO, POS no bloquea productos sin control stock, formulario edicion sincroniza boolean correctamente |
| Resiliencia Local-First (Offline) | [🟢 Completado] | [🟢 Completado] | Hook useOnlineStatus, buffer LocalStorage, background sync, indicador amber en TopBar |
| Correos Corporativos Centralizados | [🟢 Completado] | N/A | Master layout Blade, 4 Mailables ShouldQueue (Redis), preview routes local-only |
| Visualizador In-App Plantillas Email | [🟢 Completado] | [🟢 Completado] | MailPreviewController render HTML, iframe srcDoc aislado, viewport Desktop/Mobile |
| Motor Impresión Directa ESC/POS | [🟢 Completado y Operativo] | [🟢 Completado y Operativo] | mike42/escpos-php, DTO/Builder/Service SOLID, 58mm/32chars, QR nativo, multi-conector (network/file/windows) |
| Descuentos y Cupones en Checkout | [🟢 Completado] | [🟢 Completado] | Descuento directo (fijo/porcentual) + cupón por autocomplete, audit trail en orders, propagación completa (ticket, historial, finanzas, Excel, ESC/POS) |

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
- **Modos de conexión** (configurables via `.env`):
  - `NetworkPrintConnector` — Para impresoras en red (default: 192.168.1.100:9100)
  - `FilePrintConnector` — Para conexión directa USB (/dev/usb/lp0)
  - `WindowsPrintConnector` — Para entornos Windows (SMB share)
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
  - `PRINTER_CONNECTION_TYPE=network` (opciones: network, file, windows)
  - `PRINTER_IP_ADDRESS=192.168.1.100`
  - `PRINTER_PORT=9100`
  - `PRINTER_FILE_PATH=/dev/usb/lp0`
  - `PRINTER_WINDOWS_SHARE=smb://localhost/printer`
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
