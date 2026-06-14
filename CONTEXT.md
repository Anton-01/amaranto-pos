# Estado Actual del Sistema POS - Cronos

## 1. Arquitectura General
- Backend: Laravel 13 (API-First), PHP 8.3 Alpine
- Frontend: React 18/19, Tailwind CSS v4, PrimeReact, Sonner
- Base de Datos: Managed PostgreSQL (DigitalOcean)
- Estado de Infraestructura: Docker Compose configurado y funcional.

## 2. Matriz de Módulos y Progreso
| Módulo | Estado Backend | Estado Frontend | Observaciones |
| :--- | :--- | :--- | :--- |
| Infraestructura & Docker | [🟢 Completado] | [🟢 Completado] | Dockerfiles ligeros listos |
| Migraciones & Modelos Base (BD) | [🟢 Completado] | N/A | 18 migraciones, 15 modelos, Trait AdvancedSoftDeletes, Seeder base |
| Autenticación & 2FA (Sanctum) | [⚪ Pendiente] | [⚪ Pendiente] | - |
| Catálogo, Categorías y Variaciones| [⚪ Pendiente] | [⚪ Pendiente] | - |
| Promociones e Históricos | [⚪ Pendiente] | [⚪ Pendiente] | - |
| Usuarios, Roles y Permisos (RBAC)| [⚪ Pendiente] | [⚪ Pendiente] | - |
| Caja Chica, Retiros e Integridad| [⚪ Pendiente] | [⚪ Pendiente] | - |
| Ventas, Ticket Config & Histórico | [⚪ Pendiente] | [⚪ Pendiente] | - |
| Finanzas Avanzadas (70/30) | [⚪ Pendiente] | [⚪ Pendiente] | - |
| Notificaciones Reverb (Push/Mail)| [⚪ Pendiente] | [⚪ Pendiente] | - |
| Papelera Global (Soft Deletes)| [⚪ Pendiente] | [⚪ Pendiente] | - |

## 3. Detalle del Módulo Completado: Migraciones & Modelos Base

### Migraciones (PostgreSQL)
Todas las tablas utilizan UUID como llave primaria. Tipos monetarios: `NUMERIC(12,2)`. Tipos JSONB para snapshots inmutables y configuraciones. ENUMs nativos de PostgreSQL para estatus y tipos.

| # | Migración | Tabla |
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
- `User` (HasUuids, AdvancedSoftDeletes, Notifiable)
- `Role`, `GlobalSetting`, `UserSessionLog`, `TicketConfig`
- `Category`, `Product`, `Promotion` (todos con AdvancedSoftDeletes)
- `CashRegister`, `Order`, `OrderItem`
- `StockMovement`, `PettyCashTransaction`
- `NotificationType`, `UserNotificationPreference`

### Trait: AdvancedSoftDeletes
- Extiende SoftDeletes nativo de Laravel
- Administra automáticamente: `deleted_at`, `deleted_by`, `deletion_reason`
- Métodos: `advancedDelete($deletedBy, $reason)`, `advancedRestore()`, `deletedByUser()`

### Seeder (DatabaseSeeder)
- 3 roles base: `admin`, `manager`, `vendor`
- Usuario Admin inicial: admin@cronos.pos / password
- 6 tipos de notificación base con roles permitidos configurados

### Restricciones de Integridad
- `order_items.product_id` → `onDelete('set null')` (preserva histórico si producto se purga)
- `order_items.order_id` → `onDelete('cascade')`
- `model_has_roles` → ambas FK con `onDelete('cascade')`
- `product_promotion` → ambas FK con `onDelete('cascade')`
- `user_notification_preferences` → ambas FK con `onDelete('cascade')`
- Tablas financieras (orders, cash_registers, stock_movements, petty_cash) → `onDelete('restrict')`

## 4. Última Acción Ejecutada
- Generación completa de migraciones PostgreSQL, modelos Eloquent, Trait AdvancedSoftDeletes y DatabaseSeeder.

## 5. Próximo Paso Inmediato
- Configurar Laravel Sanctum para autenticación API-First con soporte de 2FA.
