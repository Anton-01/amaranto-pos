# Estado Actual del Sistema POS - Cronos

## 1. Arquitectura General
- Backend: Laravel 13 (API-First), PHP 8.4 Alpine
- Frontend: React 18/19, Tailwind CSS v4, PrimeReact, Sonner
- Base de Datos: Managed PostgreSQL (DigitalOcean)
- Cache / Colas: Redis 7 Alpine (cache global + queue worker)
- **Caché de lecturas pesadas: dinámico, gobernado por base de datos.** La ventana de refresco de cada módulo (`dashboard_stats`, `dashboard_hourly_trend`, `dashboard_top_products`, `monthly_analytics`, `product_catalog`) vive en la tabla `cache_configurations` y se administra desde `Configuración → Caché de Módulos` (15 min / 30 min / 1 h / 1 día / 2 días). `App\Support\ModuleCache` la resuelve en cada petición; `Order` y `Product` purgan sus módulos en `saved`/`deleted`, así que una venta nueva tira el Dashboard de "Hoy" al instante — ver sección 54.
- **Estándar de payload: prohibido el `SELECT *` implícito.** Toda vista principal proyecta columnas con `select()` y acota sus relaciones (`with('user:id,name')`). No es solo peso: `cost_price` y las cuentas completas de los cajeros dejaron de viajar al navegador — ver sección 54.
- WebSockets: Laravel Reverb (puerto 8080, tiempo real)
- **Estándar de Zona Horaria: `America/Mexico_City` en las tres capas (Docker `TZ` → Laravel `APP_TIMEZONE` → sesión de PostgreSQL).** Fuente única de verdad: `config('app.timezone')`, expuesta al código por `App\Support\Timezone`. **El código NO vuelve a escribir la zona a mano** — `Carbon::now()` / `Carbon::today()` sin argumento ya nacen en hora local. Única excepción deliberada: el `->timezone()` del arqueo de las 21:00 en `routes/console.php` (ver secciones 53 y 47).
- **Estándar de Fechas en el Cliente (4.ª capa): `toISOString()` está PROHIBIDO para fechas calendario.** Toda cadena `'YYYY-MM-DD'` que viaja a la API (`date_from`, `date_to`) se genera desde los componentes locales del navegador con `frontend/src/lib/dates.js` (`toLocalYmd` / `todayYmd`), nunca convirtiendo a UTC. `toISOString()` se conserva **solo** para instantes (`created_at`, `_queued_at`), que es su uso correcto — ver sección 55.
- **Estándar de Correo Saliente: puerto 2525, nunca 587.** Los proveedores de nube (DigitalOcean, AWS, GCP, Azure) bloquean el puerto 587 de salida por política anti-spam y **descartan los paquetes en silencio**, de modo que el envío no falla rápido: se cuelga hasta expirar (`TransportException: Operation timed out`). Todo despliegue en VPS/Droplet usa el puerto alterno de submission **2525** — tanto el relay de SendGrid del mailing dinámico (`config/mailing.php`) como el `MAIL_PORT` del mailer estático — ver sección 56. **Y cuando el host filtra también el 2525, la salida deja de ser SMTP: el proveedor `resend` entrega por su API HTTP en el puerto 443** a través de `ResendMailStrategy`. El envío se resuelve por **Patrón Strategy** (`MailStrategyInterface` + `MailStrategyFactory`, mapa en `config/mailing.php`) y **ninguna estrategia puede bloquear sin límite**: el presupuesto de `config('mailing.timeouts')` acota socket y cURL, que es lo que eliminó el cuelgue de 60 s de la prueba de conexión — ver sección 59.
- Último trabajo: [🟢 SECCIÓN 59: PATRÓN STRATEGY EN EL CORREO, RESEND POR HTTPS/443 Y TRAZA REAL DE ERRORES] — El subsistema de correo dejó de tener un solo camino de envío: cada proveedor es una clase (`SmtpMailStrategy`, `SendGridMailStrategy`, `ResendMailStrategy`) que la fábrica resuelve desde la configuración activa. **Resend entrega por API HTTP en el puerto 443**, así que un VPS que bloquea los puertos SMTP ya no tiene cómo detener el correo. La prueba de conexión ya no se congela 60 s: cada estrategia acota su tiempo de red (`timeout` del transporte + `default_socket_timeout` restaurado en SMTP; `connect_timeout` + `timeout` de cURL en HTTP) y cualquier fallo vuelve como **HTTP 422 con el mensaje textual del proveedor**, su clase de excepción, el código de estado y la traza resumida — ver sección 59.
- Trabajo reciente: [🟢 SECCIÓN 57: DIAGNÓSTICO SÍNCRONO DE CORREO] — Botón **Probar Conexión** en Configuración → Notificaciones / Emails: envía un correo real contra el proveedor **en la misma petición** y devuelve el mensaje textual del transporte. Valida credenciales, identidad del remitente y apertura del puerto 2525 **antes de guardar nada en la base**, montando el transporte desde un `EmailConfiguration` en memoria que recorre la fábrica de siempre. Es la única ruta del sistema que usa `send()` en vez de `queue()`, y la excepción es el punto: una prueba encolada contestaría "encolado", nunca "entregado" — ver sección 57.
- Trabajo previo: [🟢 SECCIÓN 56: EL RELAY DE SENDGRID SALE POR EL PUERTO 2525] — `SendConfiguredProcessMail` moría cada noche con `TransportException: Operation timed out` contra `smtp.sendgrid.net:587`, agotando sus 3 reintentos sin enviar el reporte del cierre de caja. No era SendGrid ni la API Key: el firewall de salida del Droplet descarta el tráfico al 587 (política anti-spam), así que el socket esperaba un banner SMTP que nunca llegaba. Corregido en la plantilla del proveedor —donde vive la infraestructura, no en las filas de `email_configurations`— para arreglar todas las configuraciones existentes sin tocar un registro; el `?? 587` de respaldo de la fábrica también cayó. Sin dependencias nuevas de Composer — ver sección 56.
- Trabajo anterior: [🟢 SECCIÓN 55: ESTANDARIZACIÓN DE FECHAS EN EL CLIENTE] — El filtro rápido "Hoy" devolvía la lista vacía después de las 18:00 CST: el frontend construía el día con `new Date().toISOString().split('T')[0]`, que imprime el día **UTC**, y a partir de las 6 PM pedía a la API las ventas de mañana. El backend nunca estuvo mal (sección 53); la pregunta que le llegaba sí. Creada `lib/dates.js` como fuente única, corregidas 7 pantallas (incluidas 3 que ya calculaban bien pero con su propia copia del formateador) y detectado de paso que la vigencia de las promociones se guardaba 6 horas tarde por el mismo motivo — ver sección 55.
- Trabajo más antiguo: [🟢 SECCIÓN 54: PROYECCIÓN DE COLUMNAS, CACHÉ DINÁMICO EN REDIS Y PERCEPCIÓN DE CARGA] — Cuatro capas del mismo problema. (1) Eliminado el `SELECT *` implícito de las vistas principales: el Historial de Ventas ya no publica `cost_price` de cada partida ni la cuenta completa del cajero, y el catálogo del POS dejó de exponer el margen del negocio. (2) El TTL salió de PHP a la tabla `cache_configurations`, con invalidación crítica colgada de los modelos `Order` y `Product` para que una venta o un movimiento de stock purguen el Dashboard de inmediato. (3) El spinner del Dashboard se sustituyó por un esqueleto que calca su geometría final, de modo que al llegar los datos nada se mueve. (4) El Sidebar centra su enlace activo tras el montaje, resolviendo la pérdida de posición al refrescar — ver sección 54.
- Corrección previa: [🟢 SECCIÓN 53: HOMOLOGACIÓN HORARIA DE TRES CAPAS] — Las ventas posteriores a las 18:00 CST se filtraban como del día siguiente porque la frontera del día se dibujaba en UTC. Alineados el reloj del SO de los contenedores (`TZ`), la zona de Laravel (`APP_TIMEZONE`, antes literal en `config/app.php`) y el servidor PostgreSQL de desarrollo (`-c timezone`, que `TZ` por sí solo no mueve en un volumen ya creado). Eliminadas ~40 cadenas `'America/Mexico_City'` escritas a mano que se habrían vuelto una segunda fuente de verdad, y corregidos 4 filtros que usaban la cadena cruda del request como límite de día — ver sección 53.
- Corrección anterior: [🟢 SECCIÓN 52: DESACOPLAMIENTO DEL ENTRYPOINT DE PRODUCCIÓN] — `docker-entrypoint.prod.sh` dejó de cablear `serve` + `queue:work` + `reverb:start` y ahora solo prepara (`storage:link` y las 4 cachés) antes de ceder el control con `exec "$@"`, de modo que el `command:` de `docker-compose.prod.yml` por fin manda: Reverb salió a su propio contenedor y el servicio `scheduler` ejecuta `schedule:work` por primera vez (antes levantaba un servidor HTTP duplicado y las tareas de las 21:00 dependían del cron del host, ahora eliminado) — ver sección 52.
- Corrección más antigua: [🟢 SECCIÓN 51: FLYSYSTEM V3 EN BACKUPS + BLINDAJE DEL CIERRE AUTOMÁTICO] — Eliminado el `assertDependenciesInstalled()` obsoleto que hacía pasar un error de código por una caída de GCP (503), verificación de disco reducida a `Storage::disk(…)->exists('/')` en try/catch con degradación reportada; zona horaria `America/Mexico_City` fijada explícitamente en el schedule de las 21:00; y `AutoCloseCashRegisters` envuelto en try/catch con bitácora propia en `job_execution_logs` para que nunca vuelva a morir en silencio (ver sección 51).
- Estado del Proyecto: [🟢 FASE 11: TRANSICIONES INSTANTÁNEAS POS ↔ MESAS COMPLETADO] — Caché SWR suscribible con render inmediato desde memoria y revalidación silenciosa, shell persistente que deja de desmontar sidebar y header, cascada serial del POS eliminada (4 lecturas en paralelo) y prefetch por intención en hover/focus. Medido con Playwright sobre el bundle de producción: parpadeo eliminado (7-8 → 0 frames con spinner), Mesas→POS de 241 ms a ~110 ms y 24 → 1 peticiones HTTP en cuatro idas y vueltas (ver sección 44).
- Fase previa: [🟢 FASE 10: TELEMETRÍA DE JOBS, HISTÓRICO DE EJECUCIÓN Y ECOSISTEMA DE ROLLBACK/BACKUPS AISLADOS EN GCP COMPLETADO] — Bitácora forense `job_execution_logs` alimentada por los eventos nativos de la cola (un renglón por intento, con traza y disparador), panel admin `/admin/jobs-monitor` con catálogo, histórico y reintento manual; y bóveda de respaldos cifrada AES-256 en Google Cloud Storage —aislada de la infraestructura primaria en DigitalOcean— con rollback transaccional validado por checksum SHA-256 (ver sección 43).
- Fase 9: [🟢 ESCUDO DE SEGURIDAD Y THROTTLING COMPLETADO] — Blindaje perimetral en cuatro capas: candado anti fuerza bruta en el login (5 fallos/min por email+IP → bloqueo de 15 min con `Retry-After`), cupo global de API (100 req/min por usuario/IP), middleware global de cabeceras de seguridad con CSP calibrada, y Cloudflare Turnstile invisible validado server-side antes de las credenciales (ver sección 42).
- Fase 8: [🟢 SISTEMA ENTERPRISE DE CIERRES AUTOMÁTICOS, NOTIFICACIONES TAGGEADAS, ANALÍTICA FINANCIERA Y AUDITORÍA INMUTABLE COMPLETADO] — Scheduler de cierre de caja 21:00 bajo usuario de sistema, notificaciones estructuradas por tags JSON con modal de plantillas dinámicas, modal de analítica financiera mensual con export CSV, y auditoría forense de cierres admin-only (ver sección 40).
- Fase 7: [🟢 SISTEMA DE GESTIÓN DE MESAS (DINE-IN) IMPLEMENTADO] — Comedor operativo: plano de mesas, cuentas vivas transaccionales, comandas incrementales y cobro con liberación automática (ver sección 39).
- Estado de Infraestructura: [🟢 SECCIÓN 52: UN CONTENEDOR, UN PROCESO] — Docker Compose multi-contenedor con roles desacoplados. En producción, 6 servicios: `backend` (:8000 API), `reverb` (:8080 WSS), `scheduler` (`schedule:work`), `queue-worker` (`queue:work`), `frontend` (:3000 SPA) y `redis`. Los cuatro servicios PHP comparten la imagen `cronos-backend:latest` y se diferencian solo por su `command:`, que el entrypoint respeta gracias a `exec "$@"`.
- Despliegue Produccion: [🟢 FASE 7: DEPLOY ÁGIL Y DOCKER OPTIMIZADO] — DigitalOcean Droplet + Managed PostgreSQL, imagenes base pre-compiladas (serversideup/php:8.4-fpm-alpine + nginx:alpine), frontend Vite pre-construido en local (frontend/dist versionado), Nginx proxy inverso HTTPS/WSS, Certbot SSL, deploy.sh automatizado. Tiempo estimado de build en el Droplet: < 2 minutos (antes: ~82 min).

## 2. Matriz de Modulos y Progreso
| Modulo | Estado Backend | Estado Frontend | Observaciones |
| :--- | :--- | :--- | :--- |
| Diagnóstico de Correo (Health Check SMTP) | [🟢 SECCIÓN 57: COMPLETADO] | [🟢 SECCIÓN 57: COMPLETADO] | Botón **Probar Conexión** junto a Guardar: `POST /api/admin/email-configurations/test-connection` (`role:admin` + `throttle:10,1`) arma el transporte desde un `EmailConfiguration` **en memoria** —validación *on-the-fly*, sin persistir— y envía `TestConnectionMail` de forma **síncrona** (`send()`, Mailable **sin** `ShouldQueue`). Única ruta no encolada del sistema: una prueba en cola contestaría "encolado", nunca "entregado". El `catch (Throwable)` devuelve `$e->getMessage()` **textual** con `400` porque ese string distingue un timeout de puerto de una API Key rechazada. La credencial vacía del formulario cae de vuelta a la guardada; nunca viaja a la respuesta ni al log. 8 pruebas — ver sección 57 |
| Puerto SMTP Saliente (Anti-Bloqueo Nube) | [🟢 SECCIÓN 56: CORREGIDO] | N/A | `SendConfiguredProcessMail` fallaba sistemáticamente con `TransportException: Operation timed out` en `smtp.sendgrid.net:587`: el proveedor de nube bloquea el 587 de salida por política anti-spam y **descarta** los paquetes (no los rechaza), así que el `SocketStream` esperaba el banner SMTP hasta expirar y quemaba los 3 reintentos en silencio. Relay movido al puerto alterno **2525** —mismas credenciales (`apikey` + API Key), mismo `STARTTLS`— en `config/mailing.php` (`SENDGRID_SMTP_PORT`), respaldo de `DynamicMailerFactory` alineado, y `MAIL_PORT=2525` como **requerimiento obligatorio** en VPS/Droplets. Sin dependencias nuevas (`symfony/sendgrid-mailer` documentado como evolución). Guardia de regresión sobre transporte y plantilla — ver sección 56 |
| Fechas en el Cliente (hora local) | N/A | [🟢 SECCIÓN 55: CORREGIDO] | `frontend/src/lib/dates.js` como fuente única: `toLocalYmd` / `todayYmd` construyen `'YYYY-MM-DD'` desde los componentes locales del navegador. `toISOString()` prohibido para fechas calendario (imprimía el día UTC y rompía el filtro "Hoy" a partir de las 18:00 CST) y conservado solo para instantes. Incluye `toLocalDateTime` para pickers con `showTime` y `parseLocalYmd` para el problema inverso — ver sección 55 |
| Caché Dinámico de Módulos (Redis) | [🟢 SECCIÓN 54: COMPLETADO] | [🟢 SECCIÓN 54: COMPLETADO] | Tabla `cache_configurations` (`module_name` único, `duration_minutes`, `is_active`) + `App\Support\ModuleCache` resolviendo el TTL en cada petición. 5 módulos cacheables y 5 ventanas cerradas (15 / 30 / 60 / 1440 / 2880 min) administradas desde `Configuración → Caché de Módulos` (admin-only). Sin *cache tags*: índice de claves por módulo, así funciona igual en Redis, `database` y `array`. Invalidación crítica en `Order::booted()` y `Product::booted()` — ver sección 54 |
| Reducción de Payload / Data Masking | [🟢 SECCIÓN 54: COMPLETADO] | N/A | `select()` explícito + *eager loading* acotado en Dashboard, Historial de Ventas, catálogo POS, productos, usuarios, cierres de caja, plano de mesas y export a Excel. `cost_price` fuera del POS y del historial; cuentas de cajero reducidas a `id,name(,email)` — ver sección 54 |
| Rendimiento Percibido (Skeletons + Sidebar) | N/A | [🟢 SECCIÓN 54: COMPLETADO] | `DashboardSkeleton` calca la geometría final (KPIs, gráfica, dona y leyenda) con `animate-pulse`, solo en la primera carga; el Sidebar centra su enlace activo con `scrollIntoView({behavior:'auto',block:'center'})` sin mover el documento — ver sección 54 |
| Infraestructura & Docker | [🟢 Completado y Operativo] | [🟢 Completado y Operativo] | Docker Compose multi-contenedor, Alpine, Hot-Reload, Reverb WS :8080 |
| Desacoplamiento del Entrypoint (Docker) | [🟢 SECCIÓN 52: CORREGIDO] | N/A | `docker-entrypoint.prod.sh` era rígido (`queue:work &` + `reverb:start &` + `exec artisan serve`) y, como `ENTRYPOINT` gana sobre `command:`, los tres contenedores levantaban la pila completa: 3 servidores HTTP, 3 Reverb peleando por :8080, 3 consumidores de la misma cola y **cero** `schedule:work`. Ahora el script solo prepara (`storage:link`, `config/route/event/view:cache`) y termina en `exec "$@"`; Reverb pasó a un 4.º contenedor propio, `REVERB_HOST` apunta al servicio interno con `REVERB_SCHEME=http`, y el cron `schedule:run` del host se eliminó para no duplicar el arqueo de las 21:00 — ver sección 52 |
| Despliegue Produccion (DigitalOcean) | [🟢 FASE 7: DEPLOY ÁGIL Y DOCKER OPTIMIZADO] | [🟢 FASE 7: DEPLOY ÁGIL Y DOCKER OPTIMIZADO] | Imagenes pre-compiladas (serversideup/php + nginx:alpine), frontend pre-construido en local, build en Droplet < 2 min, Nginx HTTPS/WSS proxy, Managed PostgreSQL SSL, Certbot, deploy.sh, scheduler residente en contenedor propio (`schedule:work`, **sin** cron en el host — ver sección 52) |
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
| Homologación Horaria Global (3 Capas) | [🟢 SECCIÓN 53: CORREGIDO] | N/A | Las ventas posteriores a las **18:00 CST** se filtraban como del día siguiente: la frontera del día se dibujaba en UTC. Estándar `America/Mexico_City` en las tres capas — `TZ` del SO en `backend`/`scheduler`/`queue-worker`/`postgres` (en producción, una sola vez en el ancla YAML que comparten los 4 contenedores PHP), `'timezone' => env('APP_TIMEZONE', 'America/Mexico_City')` en `config/app.php`, y `SET TIME ZONE` por sesión (sección 47). El contenedor de Postgres arranca además con `-c timezone` porque `TZ` no mueve un volumen ya inicializado. Nuevo `App\Support\Timezone` como fuente única (con `sqlLiteral()` validado contra el catálogo IANA para el `AT TIME ZONE` de SQL crudo): **~40 literales eliminados** del código. Corregidos 4 filtros que usaban la cadena cruda del request como límite (`StockMovement`, `PettyCash`, `JobMonitor`). Guardia estática `TimezoneAlignmentTest` — 5 mutaciones inyectadas, 5 detectadas — ver sección 53 |
| Desfase Horario timestamptz (6 h) | [🟢 CORREGIDO] | N/A | Eloquent escribia la hora local SIN offset y PostgreSQL (sesion en UTC) la guardaba como UTC: toda columna `timestamptz` quedaba 6 h adelantada. Corregido con `'timezone'` en la conexion pgsql. Solo aplica a registros NUEVOS; el historico de produccion no se migra y conserva su instante absoluto intacto (verificado: 0 s de diferencia). 5 pruebas de viaje redondo — ver seccion 47 |
| Seeder Operativo + Selector de Zonas | [🟢 CORREGIDO] | [🟢 CORREGIDO] | El seeder no creaba `ticket_config` activa y TODA instalacion nacia sin poder vender: `/api/orders` y `/api/tables/{id}/open` respondian 422 ERR_TICKET_NO_ACTIVE_CONFIG (verificado: ahora 201 en ambos). El selector de zonas del Plano de Mesas se pintaba vacio por falta de placeholder con `value=null`. 4 pruebas fijan el contrato minimo del seeder — ver seccion 46 |
| Migraciones Idempotentes (ENUMs PostgreSQL) | [🟢 CORREGIDO] | N/A | `migrate:fresh` borra tablas pero NO los tipos ENUM: el segundo `docker compose up` moria con `type "discount_type" already exists`. Corregido en dos capas: `--drop-types` en el entrypoint y `App\Support\Database\PostgresEnum` (crea si falta, recrea si esta huerfano, respeta si esta en uso — nunca CASCADE) en las 6 migraciones que crean tipos. Guardia estatica que falla si una migracion nueva usa `CREATE TYPE` crudo en `up()`. Suite completa en verde 74/74 — ver seccion 45 |
| Transiciones Instantáneas POS ↔ Mesas | N/A | [🟢 FASE 11: COMPLETADO] | Caché SWR (`readCache` + `useCachedResource` sobre `useSyncExternalStore`) con `staleTime` por volatilidad real del dato; `PersistentShell` como layout route que evita el derribo de sidebar/header; cascada serial del POS eliminada; prefetch `onMouseEnter`/`onFocus`; carrito que sobrevive a la navegación. Medido: spinner 7-8 → 0 frames, Mesas→POS 241 → ~110 ms, 24 → 1 peticiones — ver sección 44 |
| Telemetría de Jobs y Rollback/Backups GCP | [🟢 FASE 10: COMPLETADO] | [🟢 FASE 10: COMPLETADO] | Tabla `job_execution_logs` (una fila por intento) alimentada por JobQueued/JobProcessing/JobProcessed/JobFailed sin instrumentar ningún job; 3 endpoints admin + reintento vía `queue:retry`; vista `/admin/jobs-monitor` con catálogo, histórico forense, modal de traza y panel de puntos de restauración; bóveda `gcs_backups` cifrada AES-256+PBKDF2 aislada en GCP, rollback transaccional (`--single-transaction` + `ON_ERROR_STOP`) con checksum SHA-256 y respaldo de seguridad previo; scheduler 03:30 respaldo / 04:15 poda; 30 pruebas en verde — ver sección 43 |
| Escudo de Seguridad y Throttling | [🟢 FASE 9: COMPLETADO] | [🟢 FASE 9: COMPLETADO] | Candado de login 5 fallos/min por email+IP → bloqueo 15 min con `Retry-After`; cupo global 100 req/min (guard sanctum explícito + `trustProxies`); middleware global `SecurityHeaders` (XFO DENY, nosniff, HSTS, CSP calibrada para Turnstile / agente local 9100 / Reverb WSS); Cloudflare Turnstile invisible validado server-side antes de las credenciales; interceptor Axios 429 + alerta de bloqueo con cuenta regresiva en LoginPage; 18 pruebas en verde — ver sección 42 |
| Optimizacion Modal de Cobro (Rendimiento) | [🟢 COMPLETADO Y OPERATIVO] | [🟢 COMPLETADO Y OPERATIVO] | Catálogo de métodos de pago servido desde Redis (`Cache::remember`, TTL 60 min, invalidación automática en alta/edición/baja); input de "Dinero Recibido" con sanitización estricta en `onChange` (sin `onBlur`), cálculo del cambio y habilitación de "Confirmar Cobro" en tiempo real desde la primera tecla |
| Mailing Dinamico (SendGrid) por Proceso | [🟢 COMPLETADO Y OPERATIVO] | [🟢 COMPLETADO Y OPERATIVO] | Tabla `email_configurations` (una fila por `process_type`, API Key cifrada, destinatarios JSONB); transporte armado al vuelo con `Config::set()` sobre el relay SMTP de SendGrid (**puerto 2525**, no 587 — ver seccion 56); `SendConfiguredProcessMail` resuelve credenciales dentro del worker; cierre automatico de caja desacoplado (encola, no envia); reporte sin PDF ni diferencias y con membrete fiscal; pestana "Notificaciones / Emails" admin-only — ver seccion 48 |
| Flysystem V3 en la Bóveda de Respaldos | [🟢 CORREGIDO] | N/A | `/api/admin/backups` devolvía `503 ERR_BACKUP_VAULT_UNREACHABLE` culpando a GCP por un defecto propio: la clausura de `Storage::extend('gcs')` llamaba `$this->assertDependenciesInstalled()`, un método privado del proveedor que acababa resolviéndose contra `League\Flysystem\Filesystem` (donde no existe en V3) y lanzaba un fatal. Método eliminado, clausura `static`, sondeo `exists('/')` en try/catch y degradación reportada al disco local si falta el adaptador — ver sección 51 |
| Blindaje del Cierre Automático de Caja | [🟢 CORREGIDO] | N/A | `AutoCloseCashRegisters` es un comando de consola y la telemetría de la Fase 10 sólo escucha eventos de la COLA: la operación financiera más crítica del día no dejaba rastro y podía morir en silencio. Instrumentado a mano en `job_execution_logs` (`running` → `success`/`failed` con traza), `try/catch (Throwable)` envolvente, doble destino BD + `Log::error()`, opción `--source` y schedule 21:00 con `timezone('America/Mexico_City')` explícita — ver sección 51 |
| Control de Partidas y Cancelacion Segura de Mesas | [🟢 COMPLETADO Y OPERATIVO] | [🟢 COMPLETADO Y OPERATIVO] | `PUT`/`DELETE` de partidas en la cuenta viva bajo `role:admin,manager`, con reescalado desde el precio almacenado, reintegro de stock con `StockMovement` y `item_removed_from_table` en auditoria; `POST /tables/{id}/cancel` transaccional (sesion canceled + mesa available + items sellados con `canceled_at`) autorizado por rol admin o por contrasena hasheada en `global_settings.cancellation_authorization`; controles +/-/papelera y modal estricto de cancelacion en el detalle de mesa — ver seccion 49 |

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

## 42. FASE 9: ESCUDO DE SEGURIDAD Y THROTTLING [🟢 COMPLETADO Y OPERATIVO]

Blindaje perimetral del POS contra fuerza bruta, bots automatizados y saturacion
del servidor. Cuatro capas independientes: **candado de login**, **cupo global de
API**, **cabeceras HTTP endurecidas** y **escudo anti-bots invisible**.

### 42.1 Rate Limiting Especifico de Login (Fuerza Bruta)

**Componente:** `app/Support/LoginThrottle.php` (sobre `Illuminate\Support\Facades\RateLimiter`, respaldado en Redis).

Politica de **dos niveles** sobre la clave compuesta `sha1(email_minusculas + '|' + $request->ip())`:

| Nivel | Clave Redis | Politica | Efecto |
| :--- | :--- | :--- | :--- |
| 1 — Ventana de conteo | `auth:login:attempts:{sig}` | **5 intentos FALLIDOS / 60 s** | Solo cuenta; cada 401 incrementa |
| 2 — Candado | `auth:login:lock:{sig}` | **900 s (15 min)** | Al desbordar el nivel 1 se arma; mientras dure, ninguna credencial se compara contra PostgreSQL |

**Por que email + IP y no uno solo:** por IP pura, un cajero torpe bloquearia a
toda la sucursal detras del mismo NAT. Por email puro, un atacante podria
bloquear a voluntad la cuenta del administrador (DoS de cuenta) y el rociado de
credenciales desde botnet quedaria impune. La combinacion cierra ambos flancos.

**Respuesta HTTP 429** (`ERR_AUTH_TOO_MANY_ATTEMPTS`):

```
HTTP/1.1 429 Too Many Requests
Retry-After: 900

{
  "status": "error",
  "code": "ERR_AUTH_TOO_MANY_ATTEMPTS",
  "message": "Por seguridad, tu acceso ha sido bloqueado temporalmente. Intenta nuevamente en 15 minutos.",
  "errors": [],
  "metadata": { "retry_after_seconds": 900, "retry_after_minutes": 15, "max_attempts": 5 }
}
```

**Detalles operativos:**
- Un login con credenciales **correctas** libera contador y candado — aunque el flujo continue hacia el reto 2FA.
- Cada 401 devuelve `metadata.remaining_attempts` para que el cajero vea cuantos intentos le restan antes del bloqueo.
- El intento bloqueado se registra en el log con nivel `warning` (email, IP, segundos restantes) para auditoria forense.
- **`POST /api/auth/2fa/verify` lleva `throttle:6,1`**: un TOTP son 6 digitos y el token temporal vive 5 minutos — sin freno el espacio de claves es agotable.

### 42.2 Rate Limiting Global de la API (Anti-Saturacion)

**Componente:** `AppServiceProvider::configureRateLimiting()` + `bootstrap/app.php`.

```php
// bootstrap/app.php — prepend al grupo `api`
$middleware->api(prepend: [ThrottleRequests::class.':api']);
```

| Parametro | Valor | Nota |
| :--- | :--- | :--- |
| Limite | **100 peticiones / minuto** | `THROTTLE_API_MAX_ATTEMPTS` |
| Identidad | `$request->user('sanctum')?->id` ?: `$request->ip()` | Guard **explicito** |
| Respuesta | 429 `ERR_API_RATE_LIMIT_EXCEEDED` + `Retry-After` | Catalogo corporativo |

**Trampa evitada — el guard explicito:** el middleware de throttle corre *antes*
del `auth:sanctum` de la ruta. Con `$request->user()` a secas se consultaria el
guard por defecto (`web`, basado en sesion), que devuelve `null` para peticiones
con Bearer token: todo el trafico autenticado habria colapsado silenciosamente
en un unico cubo por IP, y una sucursal con 5 cajas se habria auto-bloqueado.

**Trampa evitada — proxies de confianza:** se activo
`$middleware->trustProxies(at: '*')`. La API solo es alcanzable a traves del
Nginx del host (`127.0.0.1:8000`); sin confiar en ese proxy, `$request->ip()`
devolveria siempre la IP del proxy y **todo** el throttling por IP —login
incluido— habria degenerado en un unico cubo global. De paso, `X-Forwarded-Proto`
permite que `$request->isSecure()` detecte TLS y emita HSTS.

### 42.3 Middleware de Cabeceras de Seguridad

**Componente:** `app/Http/Middleware/SecurityHeaders.php`, registrado como
middleware **global** (`$middleware->append(...)`) — se aplica a toda respuesta:
API, `/storage`, previews de correo y health check.

| Cabecera | Valor | Proposito |
| :--- | :--- | :--- |
| `X-Frame-Options` | `DENY` | Anti clickjacking |
| `X-XSS-Protection` | `1; mode=block` | Filtro XSS legado |
| `X-Content-Type-Options` | `nosniff` | Prohibe adivinar el MIME |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` | Fuerza HTTPS 1 año |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Fuga de URLs |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=(), usb=()` | Desactiva APIs sensibles |
| `X-Permitted-Cross-Domain-Policies` | `none` | Bloquea policies Flash/PDF |
| `Content-Security-Policy` | ver abajo | Anti XSS / inyeccion |

**HSTS solo se emite sobre TLS** (`$request->isSecure()`), o forzado con
`SECURITY_HSTS_FORCE=true`. Anunciarlo en `http://` no tiene efecto y en
desarrollo local llegaria a dejar el dominio inaccesible por http.

**Content Security Policy** — calibrada contra las necesidades reales del stack:

```
default-src 'self'; base-uri 'self'; form-action 'self';
frame-ancestors 'none'; object-src 'none';
script-src 'self' https://challenges.cloudflare.com;
style-src 'self' 'unsafe-inline';
img-src 'self' data: blob:;
font-src 'self' data:;
connect-src 'self' https://challenges.cloudflare.com
            http://127.0.0.1:9100 http://localhost:9100
            wss://<dominio>;
frame-src 'self' https://challenges.cloudflare.com;
worker-src 'self' blob:; manifest-src 'self'
```

| Directiva | Por que asi |
| :--- | :--- |
| `style-src 'unsafe-inline'` | **Inevitable**: Tailwind v4 y PrimeReact escriben estilos en linea en runtime |
| `script-src` SIN `'unsafe-inline'` | El bundle de Vite es externo; no hay excusa para relajarlo |
| `connect-src http://127.0.0.1:9100` | **Cronos POS Agent** — impresion ESC/POS en el equipo del cajero |
| `connect-src wss://<dominio>` | Laravel Reverb. El host se deriva de `REVERB_HOST`, y si vale `0.0.0.0` (direccion de *bind*, no publica) cae a `APP_URL` |
| `frame-src 'self'` | iframe `srcDoc` del visor de plantillas de correo |
| `frame-ancestors 'none'` | Equivalente moderno de `X-Frame-Options: DENY` |

**Reparto de responsabilidad en Nginx (fuente unica de verdad):** la CSP de
Laravel viaja en respuestas **JSON**; quien protege al navegador es la del
**documento HTML**. Por eso:

- `frontend/nginx.conf` (contenedor de la SPA) → cabeceras + CSP del documento.
- `App\Http\Middleware\SecurityHeaders` → `/api`, `/sanctum`, `/storage`.
- `infrastructure/cronos-pos.conf` (Nginx del host) → **se le retiro el bloque
  `add_header` a nivel `server`**. Duplicaba cada cabecera sobre la respuesta del
  upstream: el navegador recibia dos `Content-Security-Policy` y aplicaba la
  **interseccion** de ambas, lo que habria roto Turnstile o el agente local en
  cuanto las dos politicas divergieran. Tambien se corrigio `X-Frame-Options`,
  que estaba en `SAMEORIGIN` en lugar de `DENY`.

> Nota de Nginx: un `add_header` dentro de un `location` **descarta** todos los
> heredados del nivel `server`. Por eso el bloque de seguridad se repite integro
> dentro del `location` de assets estaticos.

### 42.4 Escudo Anti-Bots: Cloudflare Turnstile

Invisible para el cajero: **cero puzzles**. El widget se monta en modo
`appearance: 'interaction-only'` y resuelve el reto en segundo plano; solo se
hace visible si Cloudflare exige interaccion humana explicita.

**Backend** — `app/Services/Security/TurnstileVerifier.php`:
- `POST https://challenges.cloudflare.com/turnstile/v0/siteverify` con `secret`, `response` (token) y `remoteip`.
- Se valida **antes** de consultar credenciales en la base de datos.
- **Activacion automatica**: el escudo solo opera si `TURNSTILE_SECRET_KEY` esta configurada. Sin llaves, el login sigue protegido por el resto de capas — asi el entorno local y los tests no requieren cuenta de Cloudflare.
- **Falla cerrado** si Cloudflare no responde (`TURNSTILE_FAIL_OPEN=false`): es un POS financiero y un atacante podria provocar el corte de red a proposito. Invertible para sucursales con conectividad inestable.

| Escenario | HTTP | Codigo |
| :--- | :--- | :--- |
| Token ausente | 422 | `ERR_AUTH_CAPTCHA_REQUIRED` |
| Token rechazado por Cloudflare | 403 | `ERR_AUTH_CAPTCHA_FAILED` |
| Cloudflare inalcanzable (fail-closed) | 503 | `ERR_AUTH_CAPTCHA_UNAVAILABLE` |

**Frontend** — `src/lib/turnstile.js` + `src/components/auth/TurnstileWidget.jsx`:
- Carga `api.js?render=explicit` **una sola vez por documento** (promesa memoizada a nivel de modulo: React StrictMode duplica los efectos en desarrollo).
- El token viaja en el payload del login como `cf_turnstile_token`.
- **Los tokens son de un solo uso**: tras cada intento fallido `resetSignal` fuerza uno nuevo, o el backend rechazaria el reenvio como token ya consumido.
- Los callbacks se guardan en `ref` para que el widget no se re-monte en cada tecla del formulario.
- Si `VITE_TURNSTILE_SITE_KEY` esta vacia el componente no renderiza nada y el escudo queda inactivo.

> **Guardia de despliegue** (`build-frontend.sh`): el build avisa si
> `VITE_TURNSTILE_SITE_KEY` no esta definida. Un bundle sin site key contra un
> backend **con** secreto configurado rechazaria **todos** los logins con
> `ERR_AUTH_CAPTCHA_REQUIRED`. Ver `frontend/.env.example`.

### 42.5 Manejo Elegante del 429 en el Frontend

**Interceptor global** (`src/api/axios.js`):
- El 429 se resuelve **antes** que el resto de estados: nunca debe interpretarse como sesion invalida ni arrastrar al cajero a `/login` perdiendo su trabajo.
- `parseRetryAfter(error)` soporta las **dos** formas del RFC para `Retry-After` (entero de segundos y fecha HTTP) y cae a `metadata.retry_after_seconds`.
- Emite el evento global `cronos:rate-limited` y adjunta `error.retryAfter`.
- Muestra toast en cualquier ruta **salvo** `/auth/login`, que pinta su propia alerta.

**Hook** `src/hooks/useRateLimitLockout.js`: temporizador regresivo sobre una
**marca de tiempo absoluta**, no un contador decreciente — los navegadores
estrangulan `setInterval` en pestañas de fondo y un contador ingenuo se
desincronizaria del `Retry-After` real mientras el cajero mira otra ventana.
Expone `locked`, `countdown` (`14:52`), `humanized` (`15 minutos`), `start`, `clear`.

**LoginPage** (`src/pages/auth/LoginPage.jsx`) al recibir 429:
- Alerta rose con icono de candado, `role="alert"` + `aria-live="assertive"`:
  *"Por seguridad, tu acceso ha sido bloqueado temporalmente. Intenta nuevamente en 15 minutos."*
- Cuenta regresiva en monoespaciada tabular (`14:52`).
- **Boton desactivado** con etiqueta `Acceso bloqueado — 14:52`; email y password tambien se bloquean y el password se limpia.
- Al llegar a `00:00` la alerta desaparece y el formulario se rehabilita solo.

### 42.6 Configuracion (`config/security.php`)

Punto unico de verdad, todo parametrizable por entorno para que el hardening de
produccion no rompa el desarrollo local:

| Variable | Default | Descripcion |
| :--- | :--- | :--- |
| `THROTTLE_API_MAX_ATTEMPTS` | 100 | Peticiones/min por usuario o IP |
| `THROTTLE_LOGIN_MAX_ATTEMPTS` | 5 | Fallos tolerados por ventana |
| `THROTTLE_LOGIN_WINDOW` | 60 | Ventana de conteo (s) |
| `THROTTLE_LOGIN_LOCKOUT` | 900 | Candado al desbordar (s) |
| `SECURITY_HEADERS_ENABLED` | true | Interruptor maestro de cabeceras |
| `SECURITY_CSP_ENABLED` | true | Interruptor de la CSP |
| `SECURITY_CSP_REPORT_ONLY` | false | Reporta sin bloquear (calibracion) |
| `SECURITY_HSTS_MAX_AGE` | 31536000 | 1 año |
| `SECURITY_HSTS_FORCE` | false | Emitir HSTS tambien sobre http |
| `SECURITY_CSP_EXTRA_{SCRIPT,CONNECT,IMG}_SRC` | — | Origenes extra separados por espacio |
| `TURNSTILE_ENABLED` | true | Interruptor del escudo anti-bots |
| `TURNSTILE_SITE_KEY` / `VITE_TURNSTILE_SITE_KEY` | — | Llave publica (debe coincidir) |
| `TURNSTILE_SECRET_KEY` | — | Llave privada; **sin ella el escudo no opera** |
| `TURNSTILE_FAIL_OPEN` | false | Que hacer si Cloudflare no responde |

### 42.7 Cobertura de Pruebas

`backend/tests/Feature/Security/` — **18 pruebas, 77 aserciones, en verde**.
Ninguna requiere PostgreSQL: las capas verificadas se resuelven *antes* de tocar
la base de datos, que es justamente el diseño que se valida.

| Archivo | Cubre |
| :--- | :--- |
| `SecurityHeadersTest.php` | Cabeceras obligatorias; HSTS omitido en http y emitido en https; CSP habilita Turnstile y el agente local; `script-src` sin `'unsafe-inline'`; modo report-only |
| `ApiRateLimitTest.php` | Corte al superar el cupo; `Retry-After` presente y positivo; el 429 conserva las cabeceras de seguridad |
| `LoginThrottleTest.php` | 5 fallos tolerados y candado de 900 s al sexto; persistencia del candado; aislamiento por email **e** IP; normalizacion a minusculas; liberacion tras exito; `remaining_attempts` |
| `LoginShieldTest.php` | Contrato HTTP del 429 de login; Turnstile inactivo sin secreto; rechazo sin token y con token invalido; fail-closed 503; **el candado se evalua antes que el captcha** (un atacante bloqueado no gasta cuota de Cloudflare) |

### Archivos Creados en esta Fase
**Backend:**
- `config/security.php` — Politicas de throttling y cabeceras
- `app/Http/Middleware/SecurityHeaders.php` — Escudo de cabeceras global
- `app/Support/LoginThrottle.php` — Candado de fuerza bruta de dos niveles
- `app/Services/Security/TurnstileVerifier.php` — Verificacion server-side de Turnstile
- `tests/Feature/Security/{SecurityHeadersTest,ApiRateLimitTest,LoginThrottleTest,LoginShieldTest}.php`

**Frontend:**
- `src/lib/turnstile.js` — Carga memoizada del script de Cloudflare
- `src/components/auth/TurnstileWidget.jsx` — Widget invisible (render explicito)
- `src/hooks/useRateLimitLockout.js` — Temporizador regresivo de bloqueo
- `frontend/.env.example` — Variables de build documentadas

### Archivos Modificados en esta Fase
**Backend:**
- `bootstrap/app.php` — `trustProxies`, `SecurityHeaders` global, `throttle:api` en el grupo api, render JSON de `ThrottleRequestsException`
- `app/Providers/AppServiceProvider.php` — `RateLimiter::for('api', ...)` con guard sanctum explicito
- `app/Http/Controllers/Auth/AuthController.php` — Candado + Turnstile antes de las credenciales, `remaining_attempts` en el 401
- `config/services.php` — Bloque `turnstile`
- `routes/api.php` — `throttle:6,1` en `/auth/2fa/verify`
- `.env.example` — Bloque Fase 9

**Frontend / Infra:**
- `src/api/axios.js` — Rama 429 prioritaria, `parseRetryAfter`, `formatRetryAfter`, evento `cronos:rate-limited`
- `src/pages/auth/LoginPage.jsx` — Widget Turnstile, alerta de bloqueo con cuenta regresiva, boton desactivado
- `frontend/nginx.conf` — Cabeceras + CSP del documento HTML de la SPA
- `infrastructure/cronos-pos.conf` — Retirado el `add_header` duplicado a nivel server
- `build-frontend.sh` — Guardia de `VITE_TURNSTILE_SITE_KEY`
- `.env.production.example` — Bloque Fase 9
- `frontend/dist/` — Build de produccion regenerado

## 43. FASE 10: TELEMETRÍA DE JOBS, HISTÓRICO DE EJECUCIÓN Y ECOSISTEMA DE ROLLBACK/BACKUPS AISLADOS EN GCP [🟢 COMPLETADO Y OPERATIVO]

Dos subsistemas corporativos que se observan mutuamente: la **telemetría** audita
todo lo que corre en segundo plano —incluidos los respaldos—, y el **ecosistema de
rollback** deposita los snapshots en una bóveda cifrada y aislada en otra nube.

> **Nota de arquitectura.** La infraestructura primaria es DigitalOcean (Droplet +
> Managed PostgreSQL). Google Cloud Storage entra *exclusivamente* como bóveda de
> respaldos, y esa asimetría es el punto: un incidente que comprometa el Droplet,
> la cuenta de DigitalOcean o el clúster de PostgreSQL **no alcanza** los
> respaldos. Aislamiento inter-proveedor, no multi-cloud.

### 43.1 Tabla de Auditoría: `job_execution_logs`

Una fila por **INTENTO**, no por job: si la cola reintenta tres veces, quedan tres
filas y el histórico conserva por qué falló cada pasada.

| Columna | Tipo | Descripción |
| :--- | :--- | :--- |
| `id` | uuid PK | Identificador de la fila |
| `job_uuid` | uuid | UUID que Laravel asigna al payload. **Llave de correlación** entre eventos emitidos en procesos distintos |
| `job_name` | varchar(255) | FQCN del job |
| `display_name` | varchar(255) | Nombre legible del payload |
| `connection` / `queue` | varchar(60) | Conexión y cola de origen |
| `attempt` | smallint | `0` = encolado sin ejecutar; `1..n` = número de intento real |
| `status` | **enum nativo** `job_execution_status` | `pending` \| `running` \| `success` \| `failed` |
| `queued_at` / `started_at` / `finished_at` | timestamptz | Hitos del ciclo de vida |
| `duration_ms` | integer | Duración medida con cronómetro monotónico (ver 43.3) |
| `exception_class` | varchar(255) | Clase de la excepción |
| `exception_message` | text | Mensaje, recortado a 2 000 caracteres |
| `exception_trace` | text | Traza, recortada a `JOB_TELEMETRY_MAX_TRACE` (20 KB) |
| `context` | jsonb | Contexto libre (delay, id de cola) |
| `triggered_by` | uuid FK→users | Usuario disparador (`ON DELETE SET NULL`) |
| `trigger_source` | varchar(20) | `user` \| `system` \| `console` \| `scheduler` |

**Índices:** único `(job_uuid, attempt)` para la correlación evento→fila;
`(status, created_at DESC)` y `(job_name, status)` para el panel; `(job_name, created_at)`
y `(created_at)` para el histórico paginado.

### 43.2 Captura Automática vía Eventos Nativos

`App\Listeners\JobTelemetrySubscriber`, registrado con `Event::subscribe` en
`AppServiceProvider`. **Ningún job se instrumenta a mano**: cualquier clase con
`ShouldQueue` —incluidos Mailables y Notifications— queda auditada.

| Evento | Efecto |
| :--- | :--- |
| `JobQueued` | INSERT `pending`, `attempt=0`, captura `Auth::id()` y el origen |
| `JobProcessing` | UPDATE `running`, fija `attempt` real y arranca el cronómetro |
| `JobProcessed` | UPDATE `success` + `duration_ms` |
| `JobFailed` | UPDATE `failed` + clase, mensaje y traza |

`JobQueued` corre en el proceso que despacha (una petición HTTP con sesión), el
resto en el worker: por eso el usuario disparador solo puede capturarse en el
primero y los siguientes se correlacionan por UUID.

**Regla de oro:** la telemetría observa, nunca interfiere. Todo handler va
envuelto en `try/catch`; si la escritura falla, se registra en el log de la
aplicación y el job sigue su curso.

### 43.3 Tres Trampas Resueltas (documentadas para no repetirlas)

**1. Doble registro por auto-descubrimiento.** Laravel auto-descubre en
`app/Listeners` **todo método que empiece por `handle`** y lo registra contra el
evento de su firma. Con `handleQueued`, `handleProcessing`… el suscriptor quedaba
registrado dos veces —una por descubrimiento, otra por `subscribe()`— y **cada job
escribía su telemetría por duplicado**. Los métodos se llaman `recordQueued`,
`recordProcessing`, etc. Hay una prueba de regresión que cuenta los listeners.

> El mismo defecto existía **desde antes** en `NotifyPettyCashWithdrawal`: su
> método `handle` lo auto-descubría Laravel *y* un `Event::listen` explícito lo
> registraba de nuevo, de modo que **cada retiro de caja chica notificaba dos
> veces a los administradores**. Se eliminó el registro manual redundante.

**2. Sesgo de 6 horas en `duration_ms`.** Al escribir un `timestamptz`, Laravel
serializa el reloj de pared local (America/Mexico_City) y PostgreSQL lo almacena
como si fuera UTC. Restar `started_at` releído producía **exactamente 21 600 000 ms**
de sesgo. La duración se mide ahora con un cronómetro monotónico
(`microtime(true)`) en un mapa **estático** —Laravel resuelve una instancia nueva
del suscriptor por evento—, con respaldo al valor **crudo** de la columna (que sí
conserva el offset) si el worker se reinició a media ejecución.

**3. El rollback borraba su propia evidencia.** El dump **sobrescribe
`audit_logs`**: las entradas `restore.started` y `backup.created` escritas durante
la operación desaparecían con el resto del estado. Ahora la traza se acumula en
memoria y se **reinyecta** sobre la base ya restaurada (marcada con
`replayed_after_restore`), y toda entrada se escribe además al log de aplicación,
que vive en el sistema de archivos y ningún rollback puede tocar.

### 43.4 Endpoints de Telemetría (`role:admin`)

| Método | Ruta | Descripción |
| :--- | :--- | :--- |
| GET | `/api/admin/jobs` | Catálogo agregado por tipo de job: ejecuciones, éxitos/fallos, tasa de éxito, duración media y máxima, último estado. Incluye `summary` (24 h) |
| GET | `/api/admin/jobs/history` | Histórico paginado. Filtros: `status`, `job_name`, `trigger_source`, `date_from`, `date_to`, `search`, `per_page` |
| GET | `/api/admin/jobs/{job}` | Detalle de un intento con la traza completa |
| POST | `/api/admin/jobs/{job}/retry` | Reintento manual de un intento fallido |

El catálogo se agrega **sobre el histórico**, no sobre un registro estático: refleja
lo que el sistema realmente ejecuta. `DISTINCT ON` de PostgreSQL resuelve el último
estatus por job en una sola pasada indexada.

**El reintento delega en `queue:retry`**, que reconstruye el payload desde
`failed_jobs`. No se reconstruye el job a mano: el payload serializado es la única
copia fiel de sus argumentos. Errores tipados: `ERR_JOB_NOT_FAILED` (422),
`ERR_JOB_ALREADY_RETRIED` (409), `ERR_JOB_PAYLOAD_UNAVAILABLE` (410, el payload fue
purgado con `queue:flush`).

**`/api/admin/jobs/history` se declara ANTES de `/{job}`** o el router tomaría
"history" por un UUID.

### 43.5 Adaptador de Google Cloud Storage

Laravel no trae driver para GCS. `App\Providers\CloudStorageServiceProvider` lo
registra con `Storage::extend('gcs', …)` sobre
`league/flysystem-google-cloud-storage`. A partir de ahí **el resto del código habla
solo con la fachada `Storage`** y desconoce el proveedor: eso mantiene el motor
testeable con `Storage::fake()` y portable si algún día se migra la bóveda.

```php
// config/filesystems.php
'gcs_backups' => [
    'driver' => 'gcs',
    'project_id'    => env('GCP_PROJECT_ID'),
    'key_file_path' => env('GCP_KEY_FILE_PATH'),   // preferida
    'key_file_json' => env('GCP_KEY_FILE_JSON'),   // contenedores efímeros
    'bucket'        => env('GCP_BACKUPS_BUCKET', 'cronos-pos-backups-isolation'),
    'path_prefix'   => env('GCP_BACKUPS_PREFIX', 'snapshots'),
    'visibility'    => 'private',
    'throw'         => true,   // en DR, un fallo silencioso es peor que una excepción
],
```

Se prefiere `key_file_path` sobre `key_file_json`: la ruta evita que el JSON
completo quede volcado en la tabla de procesos o en un `docker inspect`. Sin
credenciales explícitas se delega en Application Default Credentials.

> ⚠️ **El paquete NO está instalado en el repositorio.** El proxy de egress de la
> sesión de desarrollo bloquea `repo.packagist.org`, y un `composer.lock`
> desincronizado rompería el build de Docker. Se declaró en la sección `suggest`
> de `composer.json` (no vinculante, no toca el lock). Para activar la bóveda:
> ```
> composer require league/flysystem-google-cloud-storage:^3.0
> ```
> Sin el paquete el sistema **no se rompe**: `BackupService` degrada al disco
> local y `CloudStorageServiceProvider` lanza un `RuntimeException` con el
> comando exacto solo si alguien usa el disco `gcs_backups`.

### 43.6 Motor de Respaldos Cifrados

`App\Services\Backup\BackupService`. Un snapshot son **dos objetos**:

```
<nombre>.tar.gz.enc      Artefacto: dump de PostgreSQL + configuración
<nombre>.manifest.json   Ficha con checksum SHA-256, tamaño, cifrado, autor
```

El manifiesto viaja **en claro a propósito**: hay que poder listar y auditar los
puntos de restauración sin descifrar nada.

**Secuencia de generación:**
1. `pg_dump --format=plain --no-owner --no-privileges --clean --if-exists` (dump idempotente).
2. Copia de `config/` y `database/migrations/`. El `.env` real queda **fuera** por defecto (`BACKUP_INCLUDE_ENV=false`): contiene secretos y un respaldo es, por definición, una copia que viaja.
3. `metadata.json` con entorno, versiones y autor.
4. `tar -czf`.
5. **Cifrado AES-256-CBC** con PBKDF2 (100 000 iteraciones) y sal aleatoria, vía el binario `openssl` — `openssl_encrypt()` de PHP exigiría cargar el dump entero en memoria. La llave viaja por entorno (`pass env:`), nunca como argumento.
6. `hash_file('sha256')` sobre el artefacto final.
7. Subida en **streaming** (`writeStream`). El manifiesto se escribe **después** del artefacto: si la subida falla, el snapshot no aparece en el catálogo y nadie intentará restaurar desde un punto incompleto.
8. Auditoría + limpieza del workspace **y** del artefacto local (un `finally` incondicional: el dump viaja en claro y su copia local llenaría el disco del Droplet en silencio).

**La llave de cifrado NO es `APP_KEY`.** Rotar `APP_KEY` es rutinario y dejaría
ilegibles todos los respaldos históricos de golpe; `BACKUP_ENCRYPTION_KEY` tiene
ciclo de vida propio y debe custodiarse **fuera del servidor**.

### 43.7 Rollback Manual (Disaster Recovery)

**Secuencia, cada paso condicionado al anterior:**

1. **Frase de confirmación** comparada con `hash_equals`.
2. **Snapshot de seguridad del estado actual** (`pre-restore`). Si falla, se aborta: sin él, un rollback a un punto equivocado sería irreversible.
3. Descarga del artefacto.
4. **Validación de integridad por checksum.** Si no coincide → aborta **antes de tocar la base**, con `backup.restore.integrity_failed` en auditoría.
5. Descifrado y extracción.
6. **Restauración TRANSACCIONAL:** `psql --single-transaction -v ON_ERROR_STOP=1`. Todo-o-nada: si algo falla, PostgreSQL revierte y la base queda exactamente como estaba.
7. Reinyección de la traza de auditoría (43.3) y notificación a administradores.

**Comandos protegidos:**

| Comando | Función |
| :--- | :--- |
| `cronos:backup-run [--trigger=] [--user=] [--queue]` | Genera un snapshot. Avisa si la bóveda no está aislada o el cifrado está apagado |
| `cronos:backup-list [--verify] [--json]` | Catálogo de puntos de restauración; `--verify` recalcula cada checksum |
| `cronos:backup-restore {snapshot} --user= [--phrase=] [--verify-only]` | Rollback |
| `cronos:telemetry-prune [--days=] [--backups] [--dry-run]` | Poda histórico y snapshots vencidos |

`cronos:backup-restore` exige **cuatro candados**: usuario con rol `admin`, frase
tecleada completa, confirmación interactiva adicional en producción, y checksum
válido. Avisa además si el snapshot proviene de otro entorno.

**Endpoints (`role:admin` — un manager puede purgar la papelera, pero no reescribir la base):**

| Método | Ruta | Descripción |
| :--- | :--- | :--- |
| GET | `/api/admin/backups` | Catálogo + diagnóstico de la bóveda. **503** `ERR_BACKUP_VAULT_UNREACHABLE` si es inalcanzable (una lista vacía se leería como "todo en orden") |
| POST | `/api/admin/backups` | Encola un respaldo (**202**). Un `pg_dump` agotaría el timeout de Nginx |
| GET | `/api/admin/backups/{snapshot}/verify` | Valida el checksum sin restaurar. **422** si está comprometido |
| POST | `/api/admin/backups/{snapshot}/restore` | Rollback. Requiere `confirmation_phrase` |

### 43.8 Scheduler

| Hora | Comando | Razón |
| :--- | :--- | :--- |
| 21:00 | `cronos:auto-close-registers` | (Fase 8) |
| **03:30** | `cronos:backup-run --trigger=scheduled` | POS cerrado y después del cierre automático: el snapshot captura la jornada completa, arqueos incluidos. `runInBackground` + `withoutOverlapping` |
| **04:15** | `cronos:telemetry-prune --backups` | Después del respaldo: primero se asegura la copia del día, luego se purgan las vencidas |

### 43.9 Vista React: `/admin/jobs-monitor` (admin only)

Doble candado: gate de frontend que redirige a cualquier no-admin y `role:admin`
en el backend. Tres pestañas:

- **Catálogo de Jobs** — un renglón por tipo: último estado con badge, ejecuciones, éxito/fallo, tasa de éxito coloreada por umbral (≥95 % verde, ≥75 % ámbar, resto rojo), duración media, última ejecución. Botón que filtra el histórico por ese job.
- **Histórico Forense** — tabla lazy paginada con filtros (estatus, job, rango de fechas, búsqueda por Enter/botón — nunca por tecla). Las filas fallidas van resaltadas. Acciones: ver detalle y reintentar.
- **Puntos de Restauración** — banner de diagnóstico de la bóveda (verde si aislada y cifrada, ámbar si degradada, con el motivo), botón para generar respaldo, y por snapshot: verificar integridad y ejecutar rollback.

**Modal de traza técnica:** hitos del ciclo de vida, clase y UUID, bloque de
excepción y la traza en `<pre>` monoespaciado sobre fondo oscuro con
`overflow-auto` propio —la traza nunca debe empujar el ancho del modal.

**Modal de rollback:** advertencia en rojo, ficha del snapshot y campo donde hay
que teclear `RESTAURAR PRODUCCION`. El botón permanece deshabilitado hasta que se
escribe algo, y el modal no se puede cerrar mientras la restauración corre.

### 43.10 Cobertura de Pruebas

**`backend/tests/Feature/` — 30 pruebas nuevas, 99 aserciones, en verde.**
La suite pasó de sqlite en memoria a **PostgreSQL real** (`cronos_pos_test`): el
esquema usa ENUMs nativos, JSONB, `ilike` y agregados con `FILTER (WHERE …)`, así
que sqlite nunca pudo ejecutar estas migraciones. El trait `RequiresPostgres` salta
las pruebas con un mensaje accionable si el servicio no está arriba.

| Archivo | Cubre |
| :--- | :--- |
| `Telemetry/JobTelemetryTest.php` (12) | Registro único de listeners (regresión del doble descubrimiento); ciclo pending→success con duración **sin el sesgo de 6 h**; captura de traza; una fila por reintento; recorte de traza; lista de exclusión; catálogo agregado y admin-only; filtros del histórico; los tres errores tipados del reintento y un reintento real con payload serializado auténtico |
| `Backup/BackupVaultTest.php` (18) | Generación con artefacto+manifiesto+checksum; correspondencia checksum↔bytes subidos; **cero residuos en disco local**; auditoría; orden del catálogo; manifiesto corrupto que no oculta los sanos; verify positivo y negativo; frase incorrecta; **checksum alterado que bloquea el rollback sin tocar la base**; **rollback real que revierte datos**; supervivencia de la traza forense; admin-only; diagnóstico degradado; poda por retención |

Detalles no obvios de la suite: `BackupVaultTest` usa `DatabaseMigrations` y **no**
`RefreshDatabase`, porque la transacción envolvente de esta última bloquea con
`ACCESS EXCLUSIVE` los `DROP TABLE` que `psql` ejecuta desde otra conexión y la
suite se cuelga hasta agotar el timeout. Ambas clases activan `$dropTypes = true`:
`migrate:fresh` no borra los ENUM nativos de PostgreSQL.

### 43.11 Imágenes Docker

`Dockerfile.prod` y `Dockerfile.dev` incorporan `postgresql16-client openssl tar gzip`.
**Sin `pg_dump`/`psql` la recuperación ante desastres sería inexistente en
producción**: la imagen base `serversideup/php:8.4-fpm-alpine` no los trae. La
versión del cliente debe ser ≥ la del servidor administrado.

### Archivos Creados en esta Fase
**Backend:**
- `database/migrations/2026_08_03_000001_create_job_execution_logs_table.php`
- `app/Models/JobExecutionLog.php`
- `app/Listeners/JobTelemetrySubscriber.php`
- `app/Http/Controllers/Admin/JobMonitorController.php`
- `app/Http/Controllers/Admin/BackupController.php`
- `app/Providers/CloudStorageServiceProvider.php`
- `app/Services/Backup/BackupService.php`, `app/Services/Backup/BackupManifest.php`
- `app/Jobs/CreateDatabaseBackup.php`
- `app/Console/Commands/{BackupRun,BackupList,BackupRestore,PruneTelemetry}.php`
- `config/telemetry.php`, `config/backup.php`
- `tests/Concerns/RequiresPostgres.php`
- `tests/Feature/Telemetry/JobTelemetryTest.php`, `tests/Feature/Backup/BackupVaultTest.php`

**Frontend:**
- `src/pages/admin/JobsMonitorPage.jsx`

### Archivos Modificados en esta Fase
**Backend:**
- `app/Providers/AppServiceProvider.php` — `Event::subscribe` de telemetría; eliminado el `Event::listen` duplicado de caja chica
- `app/Models/SystemNotification.php` — tags `backup_completed`, `backup_failed`, `backup_restored`
- `bootstrap/providers.php` — `CloudStorageServiceProvider`
- `config/filesystems.php` — discos `gcs_backups` y `backups_local`
- `routes/api.php` — 8 rutas nuevas bajo `role:admin`
- `routes/console.php` — respaldo diario 03:30 y poda 04:15
- `composer.json` — `suggest` del adaptador de GCS
- `phpunit.xml` — suite sobre PostgreSQL real
- `Dockerfile.prod`, `Dockerfile.dev` — `postgresql16-client openssl tar gzip`
- `.env.example` — bloque Fase 10

**Frontend / Infra:**
- `src/App.jsx` — ruta `/admin/jobs-monitor`
- `src/components/layout/Sidebar.jsx` — enlace "Monitor de Jobs" (adminOnly)
- `src/components/layout/AppHeader.jsx` — título de la ruta
- `.env.production.example` — bloque Fase 10
- `frontend/dist/` — build regenerado

## 44. FASE 11: TRANSICIONES INSTANTÁNEAS POS ↔ MESAS (CACHÉ SWR, SHELL PERSISTENTE Y PREFETCH) [🟢 COMPLETADO Y OPERATIVO]

Alternar entre el POS y el plano de Mesas parpadeaba en blanco y tardaba en
reconstruirse. La causa no era una sola: eran **tres defectos apilados**, y cada
uno exigía su propia corrección.

### 44.1 Diagnóstico: por qué parpadeaba

| # | Causa | Efecto |
| :--- | :--- | :--- |
| 1 | **Derribo del shell.** Cada una de las 24 páginas renderiza su propio `<AppLayout>`. Navegar desmontaba sidebar, header, reloj, campana y contador de ventas para volver a montarlos idénticos | El chrome completo se repintaba: eso *es* el parpadeo |
| 2 | **Cascada serial en el POS.** `useEffect` #1 pedía `/cash-registers/active`; solo cuando resolvía, el `useEffect` #2 disparaba `/products/grouped` + `/promotions/active` | Dos viajes de ida y vuelta ENCADENADOS antes de poder pintar nada |
| 3 | **Cero caché de vista.** Cada montaje arrancaba con `productGroups=[]` y `loading=true`, y volvía a pedir todo | Área de contenido en blanco con spinner en cada navegación |

A esto se sumaba que `/tax-rate` —configuración que cambia una vez al año— se
pedía en cada montaje de ambas vistas.

### 44.2 Estrategia de caché: por qué NO se agregó TanStack Query

El proyecto ya tenía `src/api/readCache.js`: una caché de proceso con TTL y
deduplicación de peticiones en vuelo, escrita en la Fase 8 para el header y
consumida por tres módulos. Tenía exactamente la semántica necesaria y le
faltaban tres piezas. Extenderla costó ~60 líneas; sustituirla por React Query
habría significado una dependencia nueva, reescribir la capa de datos de ambas
vistas y migrar a los tres consumidores existentes — más superficie de
regresión para el mismo resultado.

Piezas añadidas a `readCache`:

| API | Función |
| :--- | :--- |
| `subscribe(key, fn)` | Los componentes se enteran de los cambios sin sondear. Base de `useCachedResource` |
| `peek(key)` | Instantánea **síncrona**, sin disparar red. Permite que el primer render ya pinte datos |
| `prefetch(key, fetcher)` | Calienta una clave que nadie consume aún. Idempotente y silenciosa |
| `mutate(key, data)` | Siembra un dato ya conocido (respuesta de un POST) |
| `invalidatePrefix(p)` | Invalidación por familia de claves |

**Invariante crítica:** las entradas son inmutables; toda modificación reemplaza
el objeto entero. `useSyncExternalStore` compara la instantánea por identidad y
entraría en un bucle infinito de render si se mutara en sitio.

**Degradación ante fallo:** si una revalidación falla sobre un dato ya cargado,
la caché **conserva el dato viejo** y anota el error. En un POS, un catálogo de
hace un minuto es infinitamente más útil que una pantalla vacía por un blip de
red; la vista avisa con un toast y sigue operando.

### 44.3 `useCachedResource`: el hook que elimina el parpadeo

`src/hooks/useCachedResource.js`. El contrato es una sola frase: **si la clave
ya tiene dato, el primer render lo devuelve** —síncronamente, antes de cualquier
efecto— y la revalidación ocurre después, en segundo plano.

Se apoya en `useSyncExternalStore` y no en `useState` + `useEffect` porque es la
única forma de que React lea el valor externo *durante* el render. Con
`useState`, el primer render sería `undefined` y habría un frame en blanco:
justo lo que se quiere eliminar.

```js
isLoading: enabled && !hasData && !error   // sólo cuando no hay NADA que pintar
isValidating: !!entry.promise              // revalidación silenciosa en curso
```

Con caché caliente `isLoading` nace en `false`, y de ahí la transición
instantánea.

### 44.4 Registro central de recursos y ventanas de frescura

`src/api/resources.js` concentra claves, fetchers y `staleTime`. Con las
peticiones dispersas por los componentes, un `staleTime` distinto en cada sitio
reintroduce el problema que esta fase vino a resolver.

| Recurso | `staleTime` | Criterio |
| :--- | :--- | :--- |
| `pos:catalog` | 60 s | Cambia por una edición deliberada en administración |
| `pos:promotions` | 60 s | Idem |
| `pos:cash-register` | 20 s | Gobierna el bloqueo del POS: ventana corta |
| `dining:tables` | **10 s** | Varios meseros lo mueven a la vez; además revalida al recuperar el foco |
| `config:tax-rate` | 600 s | Configuración global; cambiarlo es un evento anual |

**Bug corregido de paso:** `/cash-registers/active` responde `200` con
`data: null` cuando no hay turno abierto, pero el código hacía
`catch { setCashRegister(null) }` — un fallo de red se leía como "no hay caja" y
mandaba al cajero a la pantalla de apertura. Ahora el error se propaga y la
caché conserva el estado previo.

`invalidateAfterSale()` descarta catálogo y mesas tras cobrar: el stock y el
estado del salón acaban de cambiar en el servidor, así que la siguiente lectura
va a la red aunque la ventana siga vigente.

### 44.5 Shell persistente (`PersistentShell`)

`/pos` y `/mesas` cuelgan ahora de un *layout route* común que renderiza
`<AppLayout/>` con `<Outlet/>`. React Router conserva el árbol del shell y sólo
intercambia el contenido: **no hay teardown de DOM, ni remonte de los widgets
del header, ni reinicio del estado del sidebar**.

`AppLayout` admite las dos formas de uso que conviven: `children` (el patrón
histórico de las otras 22 páginas, intacto) y `<Outlet/>` como layout route.

**Alcance deliberado:** sólo se migraron las dos vistas de alta rotación. Migrar
las 24 páginas es un refactor mayor con superficie de regresión desproporcionada
frente al beneficio — el cajero no alterna entre Papelera y Auditoría decenas de
veces por turno.

### 44.6 Carrito que sobrevive a la navegación

`src/pages/pos/cartStore.js`. El carrito vivía sólo en el `useState` de POSPage:
bastaba ir a Mesas a consultar una cuenta y volver para que la venta en curso se
evaporara — un cajero perdiendo un ticket a medio armar delante del cliente.

Un store de módulo lo resuelve sin arrastrar el estado a un contexto global. **No
se persiste en localStorage a propósito**: un carrito resucitado tras cerrar el
navegador o al día siguiente sería una fuente de errores de cobro, no una
comodidad.

### 44.7 Prefetch por intención

`prefetchRoute(path)` colgado de `onMouseEnter` y `onFocus`:

- **Sidebar** (`NavLink`) — cubre Mesas → POS.
- **Botón "Mesas" del header del POS** — cubre POS → Mesas (el plano no está en
  el sidebar; se llega por ese botón).

Entre que el usuario apunta y suelta el clic pasan 200-400 ms: tiempo de sobra
para que la petición vuelva. Es idempotente y silenciosa — sobre cualquier ruta
no crítica, o si el dato ya está fresco, no hace absolutamente nada; y si falla,
no molesta a nadie porque el usuario ni siquiera ha navegado.

### 44.8 Medición real (Chromium + Playwright, mismo equipo y datos)

Cuatro ciclos completos POS → Mesas → POS, contra el bundle de producción
servido por `vite preview` y la API Laravel real. `framesConSpinner` cuenta los
frames en que `.animate-spin` está presente tras la navegación: es el proxy
directo de "pantalla en blanco".

| Métrica (4 ciclos) | ANTES | DESPUÉS |
| :--- | ---: | ---: |
| Mesas → POS, promedio | **241 ms** | **104-116 ms** |
| Mesas → POS, frames con spinner | **7-8 por transición** | **0** |
| POS → Mesas, promedio | 107 ms | 106 ms |
| POS → Mesas, frames con spinner | 0 | 0 |
| **Peticiones HTTP totales** | **24** | **1** |

- **Parpadeo eliminado:** el spinner desapareció por completo (7-8 → 0 frames).
- **Latencia Mesas → POS: −52 %**, y la petición única que queda es la
  revalidación de mesas cuando su ventana de 10 s vence.
- **Tráfico: −96 %** (24 → 1 peticiones en cuatro idas y vueltas).

> Estas cifras son **conservadoras**: se midieron en localhost con RTT ≈ 1 ms.
> En producción sobre internet (RTT 50-150 ms) la cascada serial del POS costaba
> dos RTT encadenados y el camino cacheado cuesta **cero**, así que la mejora
> real es sensiblemente mayor que el 52 % medido aquí.

**Verificación funcional adicional (Playwright, sin errores de consola):**
- Carrito con dos productos (total `$57.00`) → ir a Mesas → volver: total
  intacto en `$57.00`.
- Sidebar colapsado a 72 px: conserva la anchura al cambiar de vista (antes se
  reiniciaba a expandido).
- Identidad del nodo `<aside>` preservada entre navegaciones: prueba directa de
  que el shell no se desmonta.
- Hover sobre el botón de Mesas con la ventana vencida dispara `/api/tables`; el
  clic posterior resuelve en 85 ms **sin ninguna petición**.

### Archivos Creados en esta Fase
- `src/api/resources.js` — Registro de recursos, `staleTime` y `prefetchRoute`
- `src/hooks/useCachedResource.js` — Hook SWR sobre `useSyncExternalStore`
- `src/pages/pos/cartStore.js` — Carrito que sobrevive a la navegación

### Archivos Modificados en esta Fase
- `src/api/readCache.js` — `subscribe`, `peek`, `prefetch`, `mutate`, `invalidatePrefix`; conservación del dato ante fallo de revalidación
- `src/App.jsx` — `PersistentShell` como layout route de `/pos` y `/mesas`
- `src/components/layout/AppLayout.jsx` — Soporte dual `children` / `<Outlet/>`
- `src/components/layout/Sidebar.jsx` — Prefetch en `onMouseEnter` / `onFocus`
- `src/pages/pos/POSPage.jsx` — Cascada serial eliminada (4 lecturas en paralelo desde caché), carrito persistente, prefetch en el botón de Mesas, invalidación post-venta
- `src/pages/dining/TablesFloorPlanPage.jsx` — Plano servido desde caché con revalidación silenciosa
- `vite.config.js` — Proxy `/api` también en `vite preview`, para validar el bundle compilado contra la API real
- `frontend/dist/` — Build de producción regenerado

## 45. CORRECCIÓN: MIGRACIONES IDEMPOTENTES ANTE ENUMS SUPERVIVIENTES (PostgreSQL) [🟢 CORREGIDO]

El contenedor `cronos-backend` moría con código 1 en el **segundo** `docker
compose up` y arrastraba el arranque completo:

```
cronos-postgres | ERROR:  type "discount_type" already exists
cronos-backend  | 2026_06_27_000002_add_discount_columns_to_orders ... FAIL
cronos-backend  | SQLSTATE[42710]: Duplicate object: 7 ERROR: type "discount_type" already exists
cronos-backend exited with code 1
```

### 45.1 Causa raíz

**`migrate:fresh` borra TABLAS, no TIPOS.** Los ENUM nativos de PostgreSQL
sobreviven al borrado, y en la corrida siguiente el `CREATE TYPE` de la
migración choca contra el superviviente.

La migración *sí* tenía un `DROP TYPE IF EXISTS discount_type`… pero en
`down()`, que `migrate:fresh` **nunca invoca**: elimina las tablas
directamente, sin revertir migración por migración.

Un detalle que explica por qué el fallo tardó en aparecer: la primera
migración (`create_enums`) sí soltaba sus tipos al inicio de `up()`, así que
los seis ENUM base eran inmunes. `discount_type` —añadido después, en otra
migración— no heredó esa precaución.

### 45.2 Corrección en dos capas

**Capa 1 — el comando.** `docker-entrypoint.sh` usaba
`migrate:fresh --seed --force`. Ahora pasa **`--drop-types`**, la bandera de
Laravel que sí elimina los tipos en PostgreSQL.

**Capa 2 — las migraciones.** La bandera por sí sola no basta: cualquiera que
ejecute `php artisan migrate:fresh` a mano, un script de CI o un entrypoint
futuro reintroduce el fallo. Se creó `App\Support\Database\PostgresEnum`, y
**las 6 migraciones que crean tipos pasan por él**.

| Situación al invocar `PostgresEnum::create()` | Comportamiento |
| :--- | :--- |
| El tipo no existe | Se crea |
| Existe y **nadie** lo usa | Se **recrea** — garantiza que la definición corresponde al archivo de migración y no a un residuo antiguo con otros valores |
| Existe y hay **columnas que dependen** de él | No se toca: recrearlo destruiría datos, y su presencia significa que la migración ya corrió |

**Nunca se usa `DROP TYPE ... CASCADE`**: eliminaría en silencio las columnas
que dependen del tipo. En una base financiera eso es inaceptable — por eso la
tercera fila de la tabla existe.

API del helper: `create`, `createMany`, `addValue` (con `IF NOT EXISTS`),
`drop`, `exists`, `isInUse`. Los identificadores se validan contra
`/^[a-z_][a-z0-9_]*$/` antes de interpolarse, porque un nombre de tipo no puede
parametrizarse en SQL.

### 45.3 Migraciones ajustadas

| Migración | Tipo |
| :--- | :--- |
| `0001_01_01_000000_create_enums` | `user_status`, `promotion_type`, `stock_movement_type`, `stock_movement_reason`, `petty_cash_reason`, `order_status` |
| `2026_06_27_000002_add_discount_columns_to_orders` | `discount_type` ← **el que rompía** |
| `2026_07_31_000001_add_open_to_order_status_enum` | `order_status` (vía `addValue`) |
| `2026_07_31_000002_create_tables_table` | `table_status` |
| `2026_07_31_000004_create_table_sessions_table` | `table_session_status` |
| `2026_08_03_000001_create_job_execution_logs_table` | `job_execution_status` |

Se auditó el resto del esquema en busca de otros objetos que sobrevivan a
`migrate:fresh` —vistas, funciones, triggers, extensiones, dominios,
secuencias sueltas—: **no existe ninguno**. Los ENUM eran la única familia
afectada.

### 45.4 Prueba de regresión

`tests/Feature/Database/MigrationsAreRerunnableTest.php` — 6 pruebas:

- `migrate:fresh` **dos y tres veces seguidas SIN `--drop-types`** (la
  reproducción exacta del fallo).
- El comando literal del entrypoint, repetido.
- **Guardia estática:** recorre `database/migrations/*.php`, aísla el cuerpo de
  cada `up()` por conteo de llaves y falla si encuentra un `CREATE TYPE` crudo.
  Es lo que impide que una migración nueva escrita al viejo estilo reintroduzca
  el problema. (Los `down()` sí pueden: recrear un tipo para convertir una
  columna es una maniobra legítima.)
- Los 10 ENUM del esquema quedan creados, y `order_status` conserva sus tres
  valores incluido el `open` que añade `ALTER TYPE`.
- `create()` sobre un tipo **en uso** no lo altera; sobre un tipo **huérfano**
  sí lo recrea.

**Verificación de que la guardia funciona:** al reintroducir a propósito el
`CREATE TYPE` crudo en la migración original, **4 pruebas fallan**, y la
estática nombra el archivo culpable.

### 45.5 Estado de la suite

Tres arranques consecutivos con el comando exacto del contenedor (39
migraciones + seed): **OK, OK, OK**.

De paso se corrigió `TicketBuilderTest`, que llevaba varias fases en rojo: el
parámetro `descuentoTotal` del `TicketDTO` es nullable pero **no tiene valor por
defecto**, y la prueba —anterior al módulo de descuentos— nunca se actualizó.
Es un arreglo de una línea en la prueba, sin cambio de comportamiento en
producción.

**La suite pasa completa por primera vez: 74/74, 319 aserciones.**

### Archivos Creados
- `app/Support/Database/PostgresEnum.php`
- `tests/Feature/Database/MigrationsAreRerunnableTest.php`

### Archivos Modificados
- `docker-entrypoint.sh` — `migrate:fresh --seed --force --drop-types`
- Las 6 migraciones de la tabla en 45.3
- `tests/Unit/TicketBuilderTest.php` — `descuentoTotal: null` explícito

## 46. CORRECCIÓN: SISTEMA INOPERANTE TRAS SEMBRAR + SELECTOR DE ZONAS VACÍO [🟢 CORREGIDO]

Dos defectos visibles en el Plano de Mesas, reportados desde la interfaz.

### 46.1 El 422 al abrir una mesa — el sistema no podía vender

```
POST /api/tables/019fd842-.../open  422 (Unprocessable Content)
```

**Causa raíz: el seeder no creaba ninguna `ticket_config` activa.**

Tanto `OrderController@store` (mostrador) como `TableSessionController@open`
(comedor) empiezan por buscar la configuración de ticket vigente y abortan con
`ERR_TICKET_NO_ACTIVE_CONFIG` si no la encuentran. Como el entrypoint de Docker
ejecuta `migrate:fresh --seed` en cada arranque, **toda instalación nacía
inoperante**: ni el cajero podía cobrar ni el mesero abrir una mesa.

No era un problema del comedor: era **el sistema entero sin poder facturar**.
El síntoma apareció primero en Mesas por casualidad.

Corrección — el seeder crea la versión 1 activa, con los datos replicados de
`fiscal_data` para que ambas fuentes nazcan coherentes:

```php
TicketConfig::create([
    'version' => 1,
    'is_active' => true,
    'business_name' => $fiscalData['business_name'],
    'rfc' => $fiscalData['rfc'],
    'address' => $fiscalData['address'].', '.$fiscalData['city'],
    'phone' => $fiscalData['phone'],
    'header_message' => 'Gracias por su preferencia',
    'footer_message' => 'Conserve su ticket para cualquier aclaracion',
    'updated_by' => $user->id,
]);
```

El módulo `/ticket-config` sigue siendo append-only: cuando el administrador
cargue los datos reales del negocio se crea una versión 2 y esta se desactiva,
nunca se edita.

**Verificación HTTP contra la API real, tras sembrar de cero:**

| Endpoint | Antes | Después |
| :--- | :--- | :--- |
| `POST /api/tables/{id}/open` | **422** `ERR_TICKET_NO_ACTIVE_CONFIG` | **201** — mesa `occupied`, sesión y orden viva creadas |
| `POST /api/orders` | **422** (mismo código) | **201** — venta con total `$50.00` |

### 46.2 El selector de zonas se pintaba vacío

El filtro nace sin zona seleccionada (`zoneFilter = null`) y PrimeReact
interpreta `null` como "sin valor". Al no haber `placeholder`, el control se
renderizaba **completamente en blanco**, sin ninguna pista de para qué sirve.

Existía una opción `{ label: 'Todas las zonas', value: null }` en la lista, pero
elegirla devolvía al mismo estado vacío: su valor coincidía con el de "sin
selección".

Corrección: se añade `placeholder="Todas las zonas"` —que describe el estado
real, no hay filtro— y la opción de limpieza usa un valor sentinela propio
(`ALL_ZONES`) para que también se muestre al elegirla. Más `aria-label` para
lectores de pantalla.

Verificado en navegador: el control muestra "Todas las zonas", lista las tres
zonas sembradas (Barra, Salón, Terraza) y filtrar por Terraza deja 3 mesas
visibles.

### 46.3 Prueba de regresión

`tests/Feature/Database/SeederLeavesSystemOperationalTest.php` — 4 pruebas que
fijan el contrato mínimo del seeder:

- Existe **exactamente una** `ticket_config` activa, con versión 1 y todos sus
  campos obligatorios poblados.
- **Se puede abrir una mesa sin configurar nada a mano** (la reproducción
  exacta del 422, ahora exigiendo 201).
- El catálogo base queda operativo: métodos de pago activos, los tres roles,
  y mesas dadas de alta.
- `migrate:fresh --seed` repetido deja siempre una única configuración activa.

Suite completa: **78/78, 334 aserciones.**

### 46.4 Pendiente detectado, NO corregido: desfase horario de 6 horas

Al validar el flujo se vio que una mesa recién abierta muestra
**"Abierta hace 6h 0m"**. Es el mismo defecto sistémico documentado en 43.3:

```
Laravel now()      : 2026-08-06T12:34:04-06:00
Guardado en la BD  : 2026-08-06 12:33:28+00     <- la hora local etiquetada como UTC
Sesión de Postgres : Etc/UTC
```

Laravel serializa el Carbon en zona `America/Mexico_City` **sin el offset**, y
PostgreSQL —cuya sesión está en UTC— lo interpreta como si ya fuera UTC. Toda
columna `timestamptz` de la aplicación queda **6 horas corrida**.

La corrección es una línea en `config/database.php` (`'timezone'` en la conexión
`pgsql`, que emite `SET TIME ZONE`), **pero cambia la interpretación de los
datos ya almacenados**: órdenes, cierres de caja, auditoría. Requiere decidir si
se migran las filas históricas o se acepta la discontinuidad, así que queda
fuera de esta corrección y a criterio del propietario del sistema.

### Archivos Creados
- `tests/Feature/Database/SeederLeavesSystemOperationalTest.php`

### Archivos Modificados
- `database/seeders/DatabaseSeeder.php` — `TicketConfig` activa versión 1
- `frontend/src/pages/dining/TablesFloorPlanPage.jsx` — placeholder, sentinela `ALL_ZONES` y `aria-label` en el selector de zonas
- `frontend/dist/` — build regenerado

## 47. CORRECCIÓN: DESFASE DE 6 HORAS EN TODA COLUMNA `timestamptz` [🟢 CORREGIDO — solo registros nuevos]

### 47.1 El mecanismo

Eloquent serializa los `Carbon` con el formato `Y-m-d H:i:s`, es decir **sin
offset**. El Carbon vive en la zona de la aplicación (`America/Mexico_City`),
pero PostgreSQL interpretaba esa cadena desnuda en la zona de **su sesión**
—`Etc/UTC` por defecto— y guardaba la hora de pared local etiquetada como UTC:

```
Laravel now()      2026-08-06T12:34:04-06:00
Guardado en la BD  2026-08-06 12:34:04+00     ← 6 h adelantado
Sesión de Postgres Etc/UTC
```

Afectaba a **toda** columna `timestamptz` del sistema: órdenes, cierres de caja,
auditoría, sesiones de mesa, telemetría de jobs, `personal_access_tokens`. El
síntoma visible era una mesa recién abierta anunciando *"Abierta hace 6h 0m"*.

### 47.2 La corrección

Una línea en la conexión `pgsql` de `config/database.php`:

```php
'timezone' => env('DB_TIMEZONE') ?: config('app.timezone'),
```

Laravel emite `SET TIME ZONE` al abrir la conexión, de modo que PostgreSQL
interpreta la cadena **en la misma zona en que Laravel la escribió** y al leer
la devuelve con su offset explícito.

**Verificación del viaje redondo:**

| | Antes | Después |
| :--- | :--- | :--- |
| Escrito por Laravel | `2026-08-06T12:34:04-06:00` | `2026-08-06T12:44:09-06:00` |
| Crudo en PostgreSQL | `2026-08-06 12:34:04+00` | `2026-08-06 12:44:09-06` |
| Releído por Eloquent | 6 h de desfase | `2026-08-06T12:44:09-06:00` |
| Mesa recién abierta | *"Abierta hace 6h 0m"* | *"Abierta hace 0m"* |

### 47.3 El histórico de producción NO se migra

Decisión del propietario del sistema: los registros existentes se dejan como
están para no arriesgar la integridad de los datos financieros.

**Por qué es seguro.** `timestamptz` almacena un **instante absoluto**, no una
cadena con zona. Cambiar la zona de la sesión altera cómo se *renderiza* al
leer, jamás el instante guardado. Verificado con una fila preexistente: el
instante releído es idéntico al almacenado, con **0 segundos** de diferencia. Y
como el frontend convierte a hora local del navegador, **el histórico se sigue
mostrando exactamente igual que antes**.

**La única consecuencia real.** Los filtros por rango de fecha usan cadenas sin
offset, así que sus límites también pasan a interpretarse en hora local. Sobre
filas **antiguas** —escritas bajo la convención vieja— eso mueve la frontera del
día 6 horas:

| Fila legacy | Reporte del 05-ago |
| :--- | :--- |
| Instante `20:00Z` (tarde) | dentro ✔ |
| Instante `01:00Z` (madrugada) | **fuera** — cae en el bucket del 04-ago |

Es decir: **los registros históricos cuya hora almacenada esté entre 00:00 y
06:00 aparecerán un día antes en los reportes filtrados por fecha.** En este
negocio —que cierra a las 21:00 y auto-cierra cajas a esa hora— las ventas en
esa franja son improbables; sí caen ahí la auditoría, la telemetría y el
respaldo de las 03:30, que son operativos y no financieros.

Si algún día se decide normalizar el histórico, el desplazamiento es de
`-6 hours` sobre las filas anteriores a esta corrección — pero **no se ejecutó
nada**: la base de producción queda intacta.

### 47.4 Código que compensaba el bug

- `JobTelemetrySubscriber` conserva su cronómetro monotónico. Nació para
  esquivar este desfase, pero se mantiene porque sigue siendo la medición más
  precisa: ambos extremos salen del mismo reloj de proceso, sin viaje a la base
  de datos. Se actualizaron los comentarios, cuya premisa ya no aplica.
- Los `serializeDate()` de `Order`, `OrderItem`, `Table`, `TableSession` y
  `SystemNotification` (que convierten a `America/Mexico_City` al serializar)
  **no eran parches**, sino normalización de presentación: siguen siendo
  correctos y quedan intactos.

### 47.5 Prueba de regresión

`tests/Feature/Database/TimestampRoundTripTest.php` — 5 pruebas:

- La sesión de PostgreSQL comparte zona con la aplicación.
- Un timestamp escrito ahora se relee como ahora (tolerancia 5 s).
- PostgreSQL almacena el offset correcto, no la hora local como UTC.
- **Una fila histórica conserva su instante** — la garantía para producción.
- Un registro de hoy cae en el filtro de hoy.

**Verificado que la guardia sirve:** al retirar la línea de `config/database.php`
fallan 3 de las 5 con mensajes que nombran la causa.

Suite completa: **83/83, 339 aserciones.**

### Archivos Creados
- `tests/Feature/Database/TimestampRoundTripTest.php`

### Archivos Modificados
- `config/database.php` — `'timezone'` en la conexión `pgsql`
- `app/Listeners/JobTelemetrySubscriber.php` — comentarios actualizados
- `.env.example`, `.env.production.example` — `DB_TIMEZONE` documentada

---

## 48. MÓDULO DE MAILING DINÁMICO (SendGrid) INTEGRADO AL CIERRE AUTOMÁTICO DE CAJA [🟢 COMPLETADO Y OPERATIVO]

El correo saliente deja de depender del bloque `MAIL_*` del `.env`. Cada tipo de
proceso del negocio ("jobs", "users", …) tiene su propia fila con credenciales,
remitente, asunto y lista de destinatarios; el transporte se arma **al vuelo**
justo antes de enviar. Rotar una API Key o redirigir un reporte pasa a ser una
escritura en base de datos, no un redeploy.

### 48.1 Tabla `email_configurations`

| Columna | Tipo | Nota |
| :--- | :--- | :--- |
| `id` | uuid PK | |
| `process_type` | varchar(60) **unique** | `jobs`, `users`, `sales`, `inventory` |
| `provider` | varchar(40) default `sendgrid` | resuelto contra `config/mailing.php` |
| `api_key` | text | cast `encrypted` — cifrada con `APP_KEY` |
| `from_email` / `from_name` | varchar | identidad del remitente |
| `target_emails` | jsonb | arreglo de destinatarios (máx. 20) |
| `subject` | varchar | asunto aplicado al Mailable en el envío |
| `is_active` | boolean | kill-switch: inactiva ⇒ el proceso no notifica |
| `updated_by` | uuid FK users | auditoría |

`UNIQUE(process_type)` garantiza que una búsqueda por proceso jamás devuelva dos
transportes en competencia. Índice `(process_type, is_active)` para la única
consulta caliente.

### 48.2 Transporte dinámico — `App\Services\Mail\DynamicMailerFactory`

Toma la fila, la fusiona sobre la plantilla del proveedor declarada en
`config/mailing.php` e inyecta el resultado en `config('mail.mailers.dynamic-{proceso}')`
con `Config::set()`. Nada se persiste en disco y nada sobrevive al proceso.

- **SendGrid** viaja por su relay SMTP (`smtp.sendgrid.net:2525`, usuario literal
  `apikey`, la API Key como contraseña). Sin dependencias nuevas en Composer.
  El puerto **no es 587**: los proveedores de nube lo bloquean de salida por
  política anti-spam y el envío expiraba con `Operation timed out` — ver
  sección 56.
- Tras registrar, llama a `MailManager::forgetMailers()`: el worker es un
  proceso largo que cachea cada mailer resuelto, y sin ese flush el segundo job
  enviaría por el transporte del primero — con la API Key equivocada.
- Se inyecta el contrato `Illuminate\Contracts\Mail\Factory` (no el manager
  concreto) para que el servicio siga funcionando bajo `Mail::fake()`.

### 48.3 Desacoplamiento — `App\Jobs\SendConfiguredProcessMail`

El productor (el comando de cierre) solo despacha `(process_type, Mailable)`:
nunca toca credenciales ni bloquea en SMTP.

**Por qué la configuración se resuelve dentro del worker y no al despachar:** el
transporte se inyecta en `config('mail.mailers')` en tiempo de ejecución y muere
con el proceso. Si el productor lo registrara y solo encolara el Mailable, el
worker buscaría después un mailer que no existe en su propia configuración.
Resolver ahí mantiene transporte y envío en el mismo proceso, y de paso lee la
fila más fresca: una llave rotada mientras el mensaje esperaba en Redis se
respeta igual.

- Sin configuración activa ⇒ el job termina en silencio (estado válido, no falla).
- Sin destinatarios válidos ⇒ `Log::warning` y salida limpia.
- `tries = 3` con `backoff [30, 120, 300]` para fallas transitorias de SMTP.
- Envía con `sendNow()`: el Mailable es `ShouldQueue` y volver a encolarlo desde
  ahí lo devolvería a Redis, donde el transporte en tiempo de ejecución ya no
  existe. La pata asíncrona ya ocurrió al despachar el job.

### 48.4 Plantilla ajustada — `CashRegisterClosingReportMail`

- **Sin PDF**: desaparecen `attachments()` y el parámetro `pdfPath`. El cuerpo
  del correo *es* el documento.
- **Sin aritmética de diferencias**: fuera `declaredAmount` y `differenceAmount`
  (y con ellos los tags FALTANTE/SOBRANTE). Un cierre automático declara
  exactamente lo esperado; la conciliación del efectivo físico corresponde al
  arqueo del siguiente turno. Queda una sola cifra: **Total Registrado**.
- **Membrete fiscal**: el Mailable lee `fiscal_data` de `global_settings` **al
  renderizar** (no al construir: el objeto viaja por la cola) e imprime Razón
  Social, RFC, dirección y teléfono como encabezado formal.
- El asunto de `email_configurations` gana sobre el predeterminado: `envelope()`
  devuelve `$this->subject ?: self::DEFAULT_SUBJECT`, porque la hidratación del
  Envelope corre *después* de `->subject()` y si no lo sobrescribiría.
- Seeder: `fiscal_data.business_name` pasa de `Cronos Fast Food` a `Cronos POS`
  (única ocurrencia quemada en el código). Las instalaciones ya sembradas
  conservan su valor y se editan desde Configuración → Datos Fiscales.

### 48.5 Enganche en `cronos:auto-close-registers`

Tras cerrar las cajas y asentar la notificación inmutable, el comando sondea
**una sola vez** si existe configuración activa para `jobs`; si no hay, ni toca
la cola. Si hay, despacha un `SendConfiguredProcessMail` por cierre con el
desglose aplanado a `nombre => monto registrado`. El correo nunca puede
estirar la ventana de las 21:00 ni convertir un cierre exitoso en un comando
fallido.

### 48.6 Endpoints (admin-only)

| Método | Ruta | Descripción |
| :--- | :--- | :--- |
| GET | /api/admin/email-configurations | Listado (API Key enmascarada) |
| GET | /api/admin/email-configurations/catalogs | Tipos de proceso y proveedores |
| POST | /api/admin/email-configurations | Alta |
| PUT | /api/admin/email-configurations/{id} | Edición |
| PATCH | /api/admin/email-configurations/{id}/toggle-status | Kill-switch inline |
| DELETE | /api/admin/email-configurations/{id} | Baja |
| POST | /api/admin/email-configurations/test-connection | Prueba de conexión síncrona (agregada en la sección 57) |

`role:admin` estricto — no `admin,manager`: estas filas guardan la credencial
del proveedor y deciden a dónde llegan los reportes financieros.

La API Key **nunca** sale del servidor: `$hidden` la oculta y el modelo expone
`has_api_key` y `api_key_preview` (`****1234`). En la edición, un campo vacío
significa "conserva la credencial guardada", no "bórrala".

### 48.7 Frontend — pestaña "Notificaciones / Emails"

`SystemSettingsPage` suma la pestaña (visible solo para `admin`) con
`EmailNotificationsPanel`: tabla de configuraciones con InputSwitch optimista
(rollback ante error), y modal con Tipo de Proceso, Proveedor, API Key
(`Password` con `toggleMask`), remitente, asunto y destinatarios en `Chips`.

### 48.8 Jobs de mantenimiento suspendidos

En `routes/console.php` quedan **comentados** —con su cadencia documentada— los
schedules de `cronos:backup-run --trigger=scheduled` (03:30) y
`cronos:telemetry-prune --backups` (04:15). Ambos comandos siguen operativos y
se pueden ejecutar a mano. Nota operativa: mientras la poda esté apagada,
`job_execution_logs` crece sin techo (cada correo encolado deja una fila).

### 48.9 Pruebas

`tests/Feature/Mailing/DynamicMailingTest.php` — 9 pruebas (la novena, del puerto
2525, se agregó en la sección 56): el cierre encola sin
enviar de forma síncrona; sin configuración activa no se encola nada pero el
cierre sí ocurre; el job usa destinatarios y asunto de la BD; una configuración
desactivada después de encolar no envía; el transporte usa las credenciales de
la fila; el reporte es autocontenido (sin PDF, sin diferencias, con RFC); la API
no expone la API Key y la conserva al editar; y solo existe una configuración
por tipo de proceso.

### Archivos Creados
- `backend/database/migrations/2026_08_08_000001_create_email_configurations_table.php`
- `backend/app/Models/EmailConfiguration.php`
- `backend/app/Services/Mail/DynamicMailerFactory.php`
- `backend/app/Jobs/SendConfiguredProcessMail.php`
- `backend/app/Http/Controllers/Admin/EmailConfigurationController.php`
- `backend/app/Http/Requests/EmailConfiguration/StoreEmailConfigurationRequest.php`
- `backend/app/Http/Requests/EmailConfiguration/UpdateEmailConfigurationRequest.php`
- `backend/config/mailing.php`
- `backend/tests/Feature/Mailing/DynamicMailingTest.php`
- `frontend/src/components/settings/EmailNotificationsPanel.jsx`

### Archivos Modificados
- `backend/app/Mail/CashRegisterClosingReportMail.php` — sin PDF ni diferencias, membrete fiscal
- `backend/resources/views/mail/cash-register-closing-report.blade.php` — plantilla reescrita
- `backend/app/Console/Commands/AutoCloseCashRegisters.php` — despacho a la cola
- `backend/routes/api.php` — rutas del módulo
- `backend/routes/console.php` — schedules de respaldo y poda comentados
- `backend/routes/web.php`, `backend/app/Http/Controllers/Admin/MailPreviewController.php` — previews alineadas a la nueva firma
- `backend/database/seeders/DatabaseSeeder.php` — `Cronos Fast Food` → `Cronos POS`
- `frontend/src/pages/admin/SystemSettingsPage.jsx` — pestaña "Notificaciones / Emails"

---

## 49. CONTROL DE PARTIDAS Y CANCELACIÓN SEGURA DE MESAS [🟢 COMPLETADO Y OPERATIVO]

Fricción operativa reportada desde el piso: una vez comandada, una partida no se
podía corregir y una mesa abierta por error no se podía liberar sin cobrarla. Se
resuelve sin aflojar la trazabilidad — al contrario: **todo retiro de consumo ya
registrado deja huella**.

### 49.1 Corrección de partidas en la cuenta viva

| Método | Ruta | Descripción |
| :--- | :--- | :--- |
| PUT | /api/tables/{table}/items/{item} | Ajusta la cantidad de una partida ya comandada |
| DELETE | /api/tables/{table}/items/{item} | Retira la partida de la cuenta |

Ambas corren bajo el mismo `lockForUpdate` sobre la mesa que el resto del módulo
—punto único de serialización del comedor—, más bloqueo de la orden, de la
partida y del producto.

**Aritmética.** La línea se reescala desde el **precio unitario almacenado**, no
desde el catálogo: un cambio de precio posterior no puede reescribir lo que se
le cotizó al comensal. El descuento de promoción se recalcula sobre el bruto
nuevo con `OrderCalculator::promotionDiscount()`, porque `fixed_amount` y
`freebie_100` no escalan linealmente con la cantidad. Si la promoción fue purgada
del catálogo, el descuento ya concedido se escala proporcionalmente en lugar de
reencarecer la línea en silencio.

Recomponer TODA la cuenta es lossless mientras la orden no arrastre descuento
global (`discount_type = 'none'`, la invariante de una cuenta abierta): las
líneas intactas resuelven exactamente a los valores ya guardados.

**Stock.** Subir la cantidad consume unidades igual que una comanda nueva
(valida `ERR_POS_INSUFFICIENT_STOCK`). Bajarla o eliminar la partida las
reintegra **y escribe un `StockMovement` de tipo `adjustment`**, para que un
conteo de almacén se concilie contra un ajuste explícito y no contra un salto
inexplicado de `current_stock`.

> `StockMovement` suma `created_at` a su `$fillable` (el modelo tiene
> `$timestamps = false`): sin sellarlo, el movimiento quedaba invisible para los
> filtros por fecha y el ordenamiento del kardex, que se apoyan en esa columna.

**Auditoría.** Toda reducción y toda eliminación insertan
`action: 'item_removed_from_table'` con `operation`
(`quantity_decreased` / `item_deleted`), usuario, mesa, orden, producto,
`quantity_before` / `quantity_after` / `quantity_removed`, **`amount_removed`**
(lo que salió de la cuenta, no un precio unitario), `stock_returned` y el nuevo
total. Los aumentos quedan como `table_item_quantity_increased`.

**Alcance del endpoint.** La partida se busca **acotada a la orden de esa mesa**;
un id de otra cuenta responde `ERR_TABLE_ITEM_NOT_FOUND` (404) en lugar de
editarse.

**Autorizacion.** Ambas rutas van bajo `role:admin,manager`. Un mesero AGREGA
consumo con normalidad, pero retirarlo saca dinero ya registrado de la cuenta y
eso exige mando: un vendedor recibe `ERR_AUTH_FORBIDDEN_ROLE` (403). La huella en
`audit_logs` opera como segunda capa, no como el unico control. En el frontend
los controles `+/-/papelera` solo se pintan para admin y manager; la cantidad
sigue siendo legible para el mesero.

### 49.2 Cancelación segura de mesa

`POST /api/tables/{table}/cancel` — motivo obligatorio (mínimo 5 caracteres) y
autorización por una de dos vías, **registrando cuál**:

| Autoridad | `authorized_by` | Contraseña |
| :--- | :--- | :--- |
| Rol `admin` | `admin_role` | No se solicita |
| Cualquier otro rol | `authorization_password` | Obligatoria, validada con `Hash::check` |

Errores: `ERR_TABLE_CANCEL_UNAUTHORIZED` (403) si la contraseña no coincide y
`ERR_CANCEL_PASSWORD_NOT_CONFIGURED` (422) si nunca se definió — un hueco de
administración no es una contraseña equivocada, y el operador merece una
instrucción accionable en vez de reintentar a ciegas.

**Transacción única** (una cancelación a medias es peor que ninguna: dejaría una
mesa ocupada para siempre o una cuenta fantasma sin mesa):

1. Reintegra el stock de cada partida con su `StockMovement`.
2. Sella `order_items.canceled_at` en todas las líneas — **se marcan, no se
   borran**: el consumo anulado es la evidencia de lo que había en la mesa.
3. `orders` → `canceled` con `canceled_by`, `canceled_at` y `cancellation_reason`.
4. `table_sessions` → `canceled` con `closed_at` / `closed_by`. El índice único
   parcial solo vigila las sesiones abiertas, así que este cambio es lo que
   realmente libera la mesa.
5. `tables` → `available`.
6. `AuditLog: table_canceled` con motivo exacto, ejecutor (nombre y correo),
   **monto cancelado**, snapshot de partidas, mesero, minutos de ocupación y bajo
   qué autoridad se liberó.

Migración `2026_08_09_000001_add_canceled_at_to_order_items_table` — columna
`timestamptz` nullable; NULL significa "nunca anulada".

### 49.3 Contraseña de Autorización

Vive en `global_settings` bajo la llave `cancellation_authorization`, **hasheada
con bcrypt**, y es deliberadamente **independiente de la contraseña de acceso de
cualquier usuario**: un supervisor puede confiarla a un encargado de turno sin
entregar su cuenta, y rotarla no obliga a nadie a cambiar su forma de entrar.

`App\Services\CancellationAuthorizationService` concentra las tres operaciones
(`isConfigured`, `store`, `authorize`).

| Método | Ruta | Middleware |
| :--- | :--- | :--- |
| GET | /api/admin/settings/cancellation-password | role:admin,manager — solo estado y fecha de rotación |
| PUT | /api/admin/settings/cancellation-password | **role:admin** — define o rota (min 6, confirmada) |

**Blindaje del endpoint genérico de settings.** `GlobalSetting::PROTECTED_KEYS`
se descuenta de toda lectura de `GET /api/admin/settings` y se **rechaza en la
escritura** con error de validación: el endpoint genérico guarda valores tal
cual, así que dejar pasar la llave escribiría el secreto en texto plano y
saltaría el hasheo de su propio endpoint.

### 49.4 Interfaz (React)

- **`TableDetailModal`** — cada partida de "Cuenta de la mesa" suma los controles
  del POS: `-` / cantidad / `+` y papelera. Nada se edita en memoria: cada acción
  viaja al servidor y la respuesta **reemplaza la sesión completa**, porque el
  servidor es dueño de la aritmética y del stock. Llegar a 0 con el `-` se
  encamina al DELETE, para que la auditoría lo registre como retiro y no como
  edición de cantidad. Mientras una mutación está en vuelo se bloquean los
  controles de todas las partidas (una sola cuenta, un solo cambio a la vez).
  Los controles se pintan **solo para admin y manager**, en espejo del
  middleware del backend.
- **`TableCancellationModal`** — botón "Cancelar Mesa" en rojo, separado del de
  cobro para que un toque errado no anule una cuenta que iba a pagarse. El modal
  exige motivo y, **solo si el usuario no es admin**, la contraseña de
  autorización; al admin se le muestra un aviso de que su rol basta. El frontend
  decide qué *mostrar*; el backend revalida rol y hash por su cuenta.
- **`CancellationPasswordPanel`** — pestaña "Autorizaciones" (admin-only) en
  Configuración del Sistema: estado (Configurada / Sin configurar), fecha de la
  última rotación y formulario con confirmación. Como solo se guarda el hash, la
  única operación posible es reemplazarla.

### 49.5 Pruebas

`tests/Feature/Dining/TableAccountControlsTest.php` — 9 pruebas: reducir devuelve
stock y audita monto retirado; eliminar retira la partida, reintegra stock y deja
`StockMovement`; no se puede aumentar más allá del stock; una partida ajena
responde 404; **un mesero recibe 403 y no mueve ni la cuenta ni el inventario**; el admin cancela sin contraseña (sesión, mesa, orden e items
sellados); el no-admin necesita la contraseña correcta y el log registra
`authorization_password`; el motivo es obligatorio; y la contraseña se guarda
hasheada, no se expone en `GET /settings` ni se puede sobrescribir por el
endpoint genérico.

### Archivos Creados
- `backend/database/migrations/2026_08_09_000001_add_canceled_at_to_order_items_table.php`
- `backend/app/Services/CancellationAuthorizationService.php`
- `backend/app/Http/Requests/TableSession/UpdateTableItemRequest.php`
- `backend/app/Http/Requests/TableSession/CancelTableRequest.php`
- `backend/tests/Feature/Dining/TableAccountControlsTest.php`
- `frontend/src/components/dining/TableCancellationModal.jsx`
- `frontend/src/components/settings/CancellationPasswordPanel.jsx`

### Archivos Modificados
- `backend/app/Http/Controllers/Dining/TableSessionController.php` — `updateItem`, `destroyItem`, `cancel` y helpers de recomposición/stock
- `backend/app/Http/Controllers/Admin/SystemSettingsController.php` — llaves protegidas y endpoints de la contraseña de autorización
- `backend/app/Models/GlobalSetting.php` — `PROTECTED_KEYS`
- `backend/app/Models/OrderItem.php` — `canceled_at` (fillable + cast)
- `backend/app/Models/StockMovement.php` — `created_at` (fillable + cast)
- `backend/routes/api.php` — 3 rutas de comedor + 2 de la contraseña de autorización
- `frontend/src/components/dining/TableDetailModal.jsx` — controles +/-/papelera y botón de cancelación
- `frontend/src/pages/admin/SystemSettingsPage.jsx` — pestaña "Autorizaciones"

## 50. CORRECCIÓN: EL ARRANQUE DE DOCKER SE PISABA A SÍ MISMO + DATOS DE DEMOSTRACIÓN [🟢 CORREGIDO]

`docker compose up --build` moría con dos errores distintos, ninguno de ellos
reproducible fuera de Docker. Los dos tienen la misma causa: **tres contenedores
ejecutando el mismo arranque a la vez sobre los mismos recursos.**

### 50.1 Los dos síntomas

```
cronos-queue-worker-dev  | In Filesystem.php line 123:
cronos-queue-worker-dev  |   require(/var/www/html/bootstrap/cache/packages.php): Failed to open stream:
cronos-queue-worker-dev  |    No such file or directory
cronos-queue-worker-dev exited with code 1
```

```
cronos-postgres | ERROR:  relation "users" does not exist
cronos-backend  |   2026_08_03_000001_create_job_execution_logs_table ....... FAIL
cronos-backend  |   SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "users" does not exist
cronos-backend exited with code 1
```

La migración señalada no tenía ningún defecto: `users` se crea 30 migraciones
antes y su llave foránea es correcta. El log lo delata en la línea siguiente —
mientras `cronos-backend` iba por la migración 36, `cronos-scheduler-dev`
ejecutaba **su propio** `migrate:fresh` y estaba dropeando todas las tablas.

### 50.2 Causa raíz: tres contenedores, un solo entrypoint

`backend`, `scheduler` y `queue-worker` comparten imagen, bind-mount de
`./backend`, volumen `backend-vendor` y base de datos. `Dockerfile.dev` declara
`ENTRYPOINT ["docker-entrypoint.sh"]` y el script terminaba en
`exec php artisan serve`, **sin usar `"$@"`**. Consecuencias:

1. **Tres `composer install` simultáneos** regenerando el autoload sobre el
   mismo `bootstrap/cache/`: el que llegaba tarde leía `packages.php` mientras
   otro lo estaba reescribiendo → el crash del queue worker.
2. **Tres `migrate:fresh --seed` simultáneos** sobre la misma base: uno borraba
   las tablas que otro estaba migrando → el 42P01.
3. **El `command:` del compose se ignoraba.** El scheduler nunca ejecutó
   `schedule:work`: levantaba un `artisan serve` idéntico al del backend (visible
   en su propio log: `Starting Laravel server on port 8000`). Los cierres
   automáticos de caja y el prune de telemetría **nunca habían corrido en
   local**.

### 50.3 La corrección: un solo dueño del arranque

`CONTAINER_ROLE` divide responsabilidades en `docker-entrypoint.sh`:

| Rol | Servicios | Hace |
| :--- | :--- | :--- |
| `app` | `backend` | composer install, `.env`, `APP_KEY`, Reverb, `migrate:fresh --seed`, caches, servidor HTTP |
| `worker` | `scheduler`, `queue-worker` | Nada del arranque compartido: espera y ejecuta `exec "$@"` |

El orden lo garantiza Docker, no un `sleep`: `backend` publica un healthcheck
—que solo responde 200 *después* de migrar, porque el servidor HTTP arranca al
final del script— y los workers declaran `depends_on: condition:
service_healthy`. El entrypoint conserva además una espera acotada por
`vendor/autoload.php` y `.env` como red de seguridad.

Dos efectos secundarios buscados: el `command:` vuelve a respetarse (el
scheduler por fin corre `schedule:work`) y el queue worker en segundo plano
desaparece del entrypoint — el servicio `queue-worker` es su único dueño, ya no
hay dos procesos compitiendo por la misma cola.

### 50.4 Datos de demostración

Un arranque limpio dejaba el sistema operable pero **vacío**: sin productos no
se puede cobrar, y sin cobrar no se puede probar ni el ticket, ni el arqueo, ni
el inventario. Dos seeders nuevos, encadenados desde `DatabaseSeeder`:

- **`DemoCatalogSeeder`** — Bebidas, Paquetes y Refrescos con 16 productos
  (precios con IVA incluido, la convención de la casa), y dos promociones
  vigentes: una por porcentaje y una por monto fijo, para ejercitar las dos
  ramas de `OrderCalculator`. `track_stock` queda en `false` en lo que se
  prepara al momento y en los paquetes: descontarles stock dejaría el inventario
  en negativo desde la primera venta.
- **`DemoSalesSeeder`** — entradas de compra que respaldan el stock inicial, una
  merma, un turno de caja ABIERTO (sin él el POS responde
  `ERR_POS_CASH_REGISTER_REQUIRED`), tres ventas cobradas —una con descuento
  global del 10% prorrateado—, una venta cancelada con devolución de stock y
  `Mesa 3` ocupada con una cuenta viva lista para cobrar.

Los importes **no se escriben a mano**: pasan por `App\Services\OrderCalculator`,
el mismo motor que usan `OrderController` y `TableSessionController`. Un dato
sembrado es indistinguible de uno producido por la aplicación, de modo que un
descuadre en el arqueo sobre estos datos sería un defecto real y no ruido del
seeder.

`SEED_DEMO_DATA` manda en ambas direcciones; vacío, se activan solo fuera de
producción. Un ticket falso en la base real contamina el histórico de ventas.

### 50.5 Verificación

Ejecutado contra PostgreSQL 16 real, con el esquema completo:

| Prueba | Resultado |
| :--- | :--- |
| `migrate:fresh --seed --drop-types` (3 corridas seguidas) | Sin errores; el arranque es repetible |
| Cuadre de cada orden sembrada (líneas vs total, subtotal + IVA vs total) | Exacto al centavo |
| Arqueo (`CashClosingService::snapshot`) | Ventas $712.60 sobre fondo $1,500 → esperado $2,212.60; la cancelada no cuenta |
| Ticket de una venta sembrada (`TicketBuilder` + `PrinterService`) | Renderiza folio, líneas, IVA, descuento, recibido/cambio y ESC/POS de 1364 bytes |
| `SEED_DEMO_DATA=false` | 0 productos, 0 órdenes: solo el catálogo base |
| Rol `worker` del entrypoint | Ejecuta su `command:`, falla claro si no lo tiene y espera si `backend` no ha terminado |
| Healthcheck de `backend` | `exit 0` con el servidor arriba, `exit 1` con el puerto muerto |
| `tests/Feature/Database/` | 21/21, 98 aserciones |
| Suite completa | 96/101 — los 5 fallos restantes son previos a este cambio y ajenos a él (verificado con el árbol limpio) |

### Archivos Creados
- `backend/database/seeders/DemoCatalogSeeder.php`
- `backend/database/seeders/DemoSalesSeeder.php`
- `backend/tests/Feature/Database/DemoDataSeederTest.php`

### Archivos Modificados
- `backend/docker-entrypoint.sh` — roles `app`/`worker`, `exec "$@"` y sin queue worker embebido
- `docker-compose.yml` — `CONTAINER_ROLE`, healthcheck de `backend`, `depends_on: service_healthy` en scheduler y queue-worker, `SEED_DEMO_DATA`
- `backend/database/seeders/DatabaseSeeder.php` — encadena los seeders de demo bajo `shouldSeedDemoData()`
- `backend/.env.example` — `SEED_DEMO_DATA` documentado
- `SETUP_LOCAL.md` — arranque por roles y tabla de datos de demostración

## 51. CORRECCIÓN CRÍTICA: FLYSYSTEM V3 EN LA BÓVEDA Y BLINDAJE DEL CIERRE AUTOMÁTICO [🟢 CORREGIDO]

Dos defectos de producción sin relación aparente, unidos por el mismo patrón: el
sistema fallaba **en el sitio equivocado** y contaba una historia falsa. El panel
de respaldos culpaba a la bóveda de un error de código propio, y el cierre de las
21:00 no dejaba rastro alguno de por qué no había corrido.

### 51.1 El 503 de `/api/admin/backups` no venía de GCP

**Síntoma:** el panel de puntos de restauración respondía `503
ERR_BACKUP_VAULT_UNREACHABLE` con el mensaje
`Call to undefined method League\Flysystem\Filesystem::assertDependenciesInstalled()`.

**Diagnóstico.** `assertDependenciesInstalled()` **nunca fue un método de
Flysystem**: era un método privado de `App\Providers\CloudStorageServiceProvider`,
invocado con `$this->` desde dentro de la clausura registrada en
`Storage::extend('gcs', …)`. Ese callback no lo ejecuta el proveedor que lo
registró: lo resuelve `FilesystemManager` desde su maquinaria interna de drivers,
y el `$this` que llega ahí no es de fiar. La llamada terminaba resolviéndose
contra el objeto equivocado —una `League\Flysystem\Filesystem`, la clase que en
Flysystem **V3** ya no expone ese helper (en V1/V2 el método sí existía en la
jerarquía, que es de donde viene el nombre)— y PHP lanzaba un **error fatal**.

Lo grave no era el fatal, sino su disfraz: el `catch (Throwable)` de
`BackupController::index()` lo capturaba y lo presentaba como *"la bóveda no
responde"*. **GCP estaba perfectamente sano.** El operador veía un incidente de
infraestructura donde había un defecto de código de dos líneas.

**Corrección en tres capas:**

1. **`CloudStorageServiceProvider`** — la clausura de `Storage::extend` se declara
   ahora `static`: *no puede* recibir un `$this`, así que ningún refactor futuro
   puede volver a colgarle una llamada de instancia. El método
   `assertDependenciesInstalled()` **se eliminó**; la guarda de dependencias es un
   `class_exists()` en línea dentro de la propia clausura, y `clientConfig()` pasó
   a `private static` (se invoca con `self::`).
2. **`BackupService::vaultConfigured()`** — comprueba `adapterAvailable()` antes de
   dar por buena la bóveda de GCS. Sin el adaptador instalado, el motor se degrada
   al disco local **y lo reporta**, en vez de resolver un driver que no puede
   construirse. Esto es lo que la sección 43.5 siempre prometió ("sin el paquete el
   sistema no se rompe") y que el código no cumplía: sólo miraba bucket y
   credenciales, nunca si la clase existía.
3. **`BackupService::probe()`** — sondeo de alcanzabilidad nuevo: un
   `Storage::disk(…)->exists('/')` envuelto en `try/catch (Throwable)`. Todo lo que
   pueda salir mal aguas abajo —credenciales caducadas, bucket inexistente, DNS
   caído, adaptador ausente— se convierte **ahí** en un booleano con motivo. Se
   captura `Throwable` y no `Exception` a propósito: un `Error` de PHP también debe
   degradar el diagnóstico, nunca tumbar la petición.

`status()` gana la llave **`reachable`** y `BackupController::index()` la consulta
**antes** de listar. El `503` se conserva —una lista vacía se leería como "todo en
orden, sin respaldos aún", y eso es peor que un error— pero ahora es un fallo
*diagnosticado*: llega con el motivo real y con el comando exacto a ejecutar si lo
que falta es el adaptador.

> **Regla que deja este defecto:** una clausura registrada en el contenedor de
> servicios (`Storage::extend`, `Cache::extend`, macros…) **no debe depender de
> `$this`**. Si necesita un helper de la clase, que sea `static`.

### 51.2 Zona horaria del scheduler

El contenedor opera en **UTC** (`docker-compose.prod.yml` no fija `TZ`), de modo
que `timezone()` es lo único que ata el arqueo al reloj de pared del negocio: sin
él, las 21:00 se evalúan en UTC y el cierre cae a las **15:00** hora local.

La declaración de `routes/console.php` se reescribió en la forma canónica, con la
zona fijada **antes** de la hora y de manera **explícita** —no heredada de
`config('app.timezone')`— porque un cambio de configuración global no debe poder
mover en silencio la hora del arqueo:

```php
Schedule::command('cronos:auto-close-registers --source=scheduler')
    ->weekdays()
    ->timezone('America/Mexico_City')
    ->at('21:00')
    ->withoutOverlapping()
    ->onOneServer();
```

> **Nota honesta de auditoría.** El `->timezone('America/Mexico_City')` **ya
> estaba presente** en la línea anterior (iba después de `at()`, orden que en
> Laravel es indiferente: `timezone()` sólo fija una propiedad que `isDue()` lee
> al evaluar), y `config/app.php` ya declaraba la misma zona. Es decir: **la
> declaración del schedule no explica por sí sola una ejecución fuera de hora.**
> Si el cierre volviera a desfasarse, la causa está en otra capa y hay que
> buscarla ahí — el contenedor `scheduler` caído o reiniciado (corre
> `schedule:work`, que **no** es un cron del sistema y no recupera ejecuciones
> perdidas), `->weekdays()` saltándose un sábado o domingo, un `withoutOverlapping()`
> con el mutex trabado por una corrida previa que murió sin liberarlo, o el
> desfase de `timestamptz` de la sección 47. La instrumentación de 51.3 existe
> justamente para poder distinguir esos casos en vez de conjeturarlos.

### 51.3 Escudo de telemetría del Job de cierre

**El hueco:** `AutoCloseCashRegisters` es un **comando de consola**, no un job
encolado. La telemetría automática de la Fase 10 (`JobTelemetrySubscriber`)
escucha los eventos de la **cola** —`JobQueued`, `JobProcessing`, `JobProcessed`,
`JobFailed`— y un comando de consola no emite ninguno. Resultado: la operación
financiera más crítica del día era **la única que no dejaba rastro** en
`job_execution_logs`. Si moría, moría en silencio, y nadie se enteraba hasta que a
la mañana siguiente las cajas seguían abiertas.

Por eso este comando —y sólo este— se instrumenta **a mano**:

| Momento | Efecto en `job_execution_logs` |
| :--- | :--- |
| Inicio | INSERT `running` con `job_uuid` propio, `attempt = 1`, `started_at` |
| Fin correcto | UPDATE `success` + `duration_ms` + `context` (cajas cerradas / fallidas) |
| Excepción | UPDATE `failed` + `exception_class`, `exception_message` (2 000 car.) y `exception_trace` (`telemetry.jobs.max_trace_length`) + `Log::error()` |

Detalles no obvios:

- **`job_uuid` propio y `attempt = 1`.** El índice único es `(job_uuid, attempt)`;
  un comando no pasa por la cola ni se reintenta solo, así que cada corrida es un
  UUID nuevo con un único intento. La fila aparece en `/admin/jobs-monitor` como
  cualquier otra, con `shortName()` → `AutoCloseCashRegisters`.
- **Cronómetro monotónico** (`microtime(true)`), no resta de timestamps releídos:
  eso arrastraría el sesgo de 6 h documentado en 43.3.
- **La telemetría observa, nunca interfiere.** Tanto la apertura como el cierre de
  la bitácora van en su propio `try/catch`. Si la BD rechaza la escritura, el
  cierre de cajas **continúa** y el fallo se registra en el log de aplicación: una
  bitácora rota jamás debe impedir un arqueo ni marcar como fallido un cierre que
  funcionó.
- **Se captura `Throwable`, no `Exception`.** Un `TypeError` o un
  `Error` de PHP también deben quedar en la bitácora; el requisito era "nunca morir
  en silencio", y `Exception` sola deja fuera media jerarquía de PHP 8.
- **Doble destino del fallo.** Va a la BD *y* a `Log::error()` con traza. El log
  vive en el sistema de archivos y sobrevive a un rollback de base de datos, que es
  precisamente cuando más falta hace (misma lección que 43.3, trampa 3).
- **`--dry-run` no abre bitácora.** Un simulacro no es una ejecución y no debe
  contaminar el histórico forense.
- **`--source=`** (default `console`) alimenta `trigger_source`, y el schedule pasa
  `--source=scheduler`. Así el panel distingue una corrida programada de una
  lanzada a mano, que es la primera pregunta ante un arqueo inesperado.

`handle()` quedó como envoltura delgada: el cuerpo se extrajo a
`closeOpenRegisters()`, que devuelve el conteo de cajas cerradas y fallidas y
alimenta tanto el código de salida como el `context` de la bitácora. El
`try/catch` por caja individual **se conserva**: un fallo en una caja no debe
bloquear el cierre de las demás.

### 51.4 Verificación

| Comprobación | Resultado |
| :--- | :--- |
| `php -l` sobre los 5 archivos modificados | Sin errores de sintaxis |
| Revisión de la clausura `Storage::extend` | `static`, sin un solo `$this` en su cuerpo |
| Rastreo de `assertDependenciesInstalled` en el árbol | **0 apariciones** (era la única del repositorio) |

> ⚠️ **La suite automatizada NO pudo ejecutarse en esta sesión.** El proxy de
> egress bloquea `repo.packagist.org` (`CONNECT tunnel failed, 403`), así que no
> hay `vendor/` y por tanto no hay PHPUnit — la misma restricción documentada en
> 43.5. La verificación anterior es **estática**. Antes de desplegar hay que correr
> en un entorno con dependencias:
> ```
> composer install
> php artisan test --filter=BackupVaultTest
> php artisan schedule:list          # confirma 21:00 America/Mexico_City
> php artisan cronos:auto-close-registers --dry-run
> ```
> `BackupVaultTest` merece atención particular: `status()` cambió de forma (llave
> `reachable` nueva) y `vaultConfigured()` ganó una condición, de modo que las
> pruebas de "diagnóstico degradado" son las candidatas naturales a requerir
> ajuste.

### Archivos Modificados
- `backend/app/Providers/CloudStorageServiceProvider.php` — clausura `static`, guarda `class_exists` en línea, `assertDependenciesInstalled()` **eliminado**, `clientConfig()` a `private static`, `adapterAvailable()` público
- `backend/app/Services/Backup/BackupService.php` — `probe()` con `exists('/')` en try/catch, `status()` con llave `reachable`, `vaultConfigured()` verifica el adaptador
- `backend/app/Http/Controllers/Admin/BackupController.php` — `index()` sondea antes de listar; el 503 llega diagnosticado
- `backend/app/Console/Commands/AutoCloseCashRegisters.php` — blindaje try/catch, bitácora en `job_execution_logs`, opción `--source`, cuerpo extraído a `closeOpenRegisters()`
- `backend/routes/console.php` — schedule en forma canónica con `timezone()` explícita y `--source=scheduler`

## 52. DESACOPLAMIENTO DEL ENTRYPOINT DE PRODUCCIÓN: UN CONTENEDOR, UN PROCESO [🟢 CORREGIDO]

La sección 50 corrigió este mismo anti-patrón en **desarrollo**
(`docker-entrypoint.sh` + `CONTAINER_ROLE`). Producción quedó fuera de aquella
corrección y seguía arrastrando el defecto original: `docker-entrypoint.prod.sh`
terminaba en una cadena rígida de procesos y **nunca cedía el control** al
`command:` del compose.

### 52.1 El síntoma: el `command:` no servía para nada

`docker-compose.prod.yml` declaraba desde siempre lo correcto:

```yaml
scheduler:
  command: php artisan schedule:work
queue-worker:
  command: php artisan queue:work --tries=3 --timeout=90
```

…y los tres contenedores levantaban exactamente lo mismo:

```
cronos-scheduler     | → Starting queue worker...
cronos-scheduler     | → Starting Laravel Reverb on port 8080...
cronos-scheduler     | → Starting Laravel server on port 8000...
cronos-queue-worker  | → Starting queue worker...
cronos-queue-worker  | → Starting Laravel Reverb on port 8080...
cronos-queue-worker  | → Starting Laravel server on port 8000...
```

### 52.2 Causa raíz: `ENTRYPOINT` gana, y este no usaba `"$@"`

`backend/Dockerfile.prod` declara `ENTRYPOINT ["docker-entrypoint.sh"]`, y la
regla de Docker es inflexible: **el `command:` del compose no reemplaza al
entrypoint, se le pasa como argumentos**. Un entrypoint que no lee `"$@"` los
descarta en silencio. El script terminaba así:

```sh
php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600 --quiet &
php artisan reverb:start --host=0.0.0.0 --port=8080 &
exec php artisan serve --host=0.0.0.0 --port=8000
```

Tres procesos cableados en la imagen. Consecuencias medibles en el Droplet:

| # | Duplicación | Efecto real |
| :--- | :--- | :--- |
| 1 | 3 × `artisan serve` en :8000 | Dos servidores HTTP inalcanzables (solo `backend` publica el puerto) consumiendo RAM y una conexión al Managed PostgreSQL cada uno |
| 2 | 3 × `reverb:start` en :8080 | Dos instancias reintentando el bind en bucle contra un puerto ya tomado |
| 3 | 3 × `queue:work` sobre la misma cola Redis | Un job podía tocarle a cualquiera de los tres; los logs de la cola quedaban repartidos entre tres contenedores y `job_execution_logs` registraba el intento bajo un contenedor arbitrario |
| 4 | 0 × `schedule:work` | **El scheduler nunca corrió.** El cierre automático de caja de las 21:00, el respaldo de las 03:30 y la poda de las 04:15 dependían por completo del cron del host documentado en `DEPLOY_PRODUCTION.md` |

El punto 4 es el más grave: el servicio llamado `scheduler` llevaba toda su vida
sirviendo una API que nadie consultaba.

### 52.3 La corrección: el entrypoint prepara, el compose decide

`backend/docker-entrypoint.prod.sh` quedó reducido a dos responsabilidades:

1. **Preparar** — `storage:link` (con limpieza previa del enlace obsoleto) y las
   cuatro cachés de producción: `config:cache`, `route:cache`, `event:cache`,
   `view:cache`.
2. **Ceder el control** — `exec "$@"` como última instrucción, sin excepción.

Toda ejecución rígida de `serve`, `queue:work` y `reverb:start` desapareció del
script. `Dockerfile.prod` gana un `CMD` por defecto
(`php artisan serve --host=0.0.0.0 --port=8000`) que existe solo para que
`exec "$@"` nunca se quede sin argumentos en un `docker run` suelto; el compose
lo sobreescribe en los cuatro servicios.

### 52.4 Reverb sale a su propio contenedor (4.º servicio PHP)

Se descartaron el supervisor ligero y el `&` dentro de `backend`. Reverb ahora es
un servicio propio en `docker-compose.prod.yml`:

| Servicio | `command:` | Puerto publicado |
| :--- | :--- | :--- |
| `backend` | `php artisan serve --host=0.0.0.0 --port=8000` | `127.0.0.1:8000` |
| `reverb` | `php artisan reverb:start --host=0.0.0.0 --port=8080` | `127.0.0.1:8080` |
| `scheduler` | `php artisan schedule:work` | — |
| `queue-worker` | `php artisan queue:work --tries=3 --timeout=90` | — |

Por qué separarlo y no usar un supervisor:

- **`restart: always` vuelve a significar algo.** Un proceso lanzado con `&`
  moría sin que Docker se enterara: el contenedor seguía "sano" porque su PID 1
  (`artisan serve`) continuaba vivo. Los WebSockets se caían en silencio hasta
  que alguien reportaba que las notificaciones no llegaban.
- **`SIGTERM` llega al proceso correcto.** `exec` deja al comando destino como
  PID 1: el worker termina el job en curso y Reverb cierra sus sockets con
  orden, en lugar de comerse el `SIGKILL` de los 10 s.
- **Reinicio y logs aislados.** `docker compose restart reverb` ya no tumba la
  API, y `logs reverb` solo trae WebSockets.

El contrato hacia afuera **no cambia**: `infrastructure/cronos-pos.conf` sigue
haciendo proxy de `/app` y `/apps` a `127.0.0.1:8080`, solo que ahora ese puerto
lo publica `reverb` y no `backend`. No hubo que tocar Nginx ni el frontend.

### 52.5 La trampa que destapó la separación: `REVERB_HOST` es del *cliente*

`config/broadcasting.php` usa `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME`
para el **cliente HTTP** con el que Laravel *publica* eventos, no para el bind
del servidor (ese lo fija el `--host` de la línea de comandos). `.env.production`
traía `REVERB_HOST=0.0.0.0`, que "funcionaba" solo porque emisor y servidor
vivían en el mismo contenedor. Con Reverb fuera, publicar contra `0.0.0.0` desde
`backend` habría fallado en cada evento.

Peor todavía: `REVERB_SCHEME` no estaba definido y su default en
`config/broadcasting.php` es `https` con `useTLS => true` — el broadcaster
intentaba un handshake TLS contra un Reverb que habla HTTP plano. El compose fija
los tres valores vía `environment:` (que tiene precedencia sobre `env_file:`), de
modo que un `.env.production` antiguo en el Droplet **no rompe el despliegue**:

```yaml
environment:
  REVERB_HOST: reverb     # nombre de servicio en la red interna de Docker
  REVERB_PORT: 8080
  REVERB_SCHEME: http     # el TLS lo termina el Nginx del host
```

`.env.production.example` documenta además `REVERB_SERVER_HOST` /
`REVERB_SERVER_PORT` (bind del servidor) para que el par no se vuelva a
confundir.

### 52.6 Efecto colateral obligatorio: eliminar el cron del host

`DEPLOY_PRODUCTION.md` pedía una línea de crontab en el Droplet:

```
* * * * * cd /opt/cronos-pos && docker compose ... exec -T backend php artisan schedule:run
```

Era un parche a la era en que el contenedor `scheduler` no ejecutaba nada. Con el
entrypoint corregido, **mantener ambos ejecuta cada tarea programada dos veces**:
el cierre automático de caja de las 21:00 correría dos arqueos. El paso 7 de la
guía ahora prohíbe explícitamente el cron y ordena borrarlo si existe.

### 52.7 Decisiones de diseño

- **Las cuatro cachés se compilan en TODOS los roles.** En producción no hay
  bind-mount: cada contenedor tiene su propia capa de escritura, así que no se
  pisan (a diferencia del `composer install` del entorno de desarrollo, que sí
  competía por un volumen compartido y motivó `CONTAINER_ROLE` en la sección 50).
  Y cada rol las necesita de verdad: el queue worker renderiza Blade al enviar
  los Mailables (`view:cache`) y genera URLs firmadas (`route:cache`).
- **Cero migraciones en el entrypoint de producción.** Siguen siendo exclusivas
  de `deploy.sh`, que las corre una sola vez contra `backend`. Cuatro
  contenedores arrancando en paralelo no pueden ser cuatro migradores — es
  exactamente el 42P01 de la sección 50.
- **`image: cronos-backend:latest` compartida** por los cuatro servicios: Compose
  construye el Dockerfile una vez en lugar de resolver cuatro builds idénticos.
- **Guardia de arranque.** Si el entrypoint recibe cero argumentos, falla con
  mensaje explícito en vez de salir con código 0 y dejar un contenedor que
  "arranca bien" y muere de inmediato.
- **Healthcheck propio para Reverb** sin dependencias externas
  (`php -r "exit(@fsockopen('127.0.0.1', 8080) ? 0 : 1);"`): un `nc -z` habría
  dependido de qué applets trae el busybox de la imagen Alpine.

### 52.8 Verificación

| Comprobación | Resultado |
| :--- | :--- |
| `sh -n backend/docker-entrypoint.prod.sh` | Sin errores de sintaxis |
| `docker compose -f docker-compose.prod.yml config` | Válido; los 4 `command:` se resuelven distintos |
| `REVERB_HOST` renderizado | `reverb` — el `environment:` gana sobre el `env_file:` con `0.0.0.0` |
| `image:` de los 4 servicios PHP | `cronos-backend:latest` en los cuatro (build único) |
| Rastreo de `artisan serve`, `queue:work` y `reverb:start` en el entrypoint | **0 apariciones** |
| `exec "$@"` como última instrucción del script | Confirmado |

> ⚠️ **Verificación estática.** No se construyeron las imágenes en esta sesión:
> el proxy de egress bloquea `repo.packagist.org` (misma restricción de las
> secciones 43.5 y 51.4), así que no hay `vendor/` y el build de
> `Dockerfile.prod` no puede completarse. En el Droplet, tras el primer
> `bash deploy.sh`, confirmar:
> ```
> docker compose -f docker-compose.prod.yml ps          # 6 servicios arriba
> docker compose -f docker-compose.prod.yml exec scheduler ps -o args
> #   -> php artisan schedule:work   (NO 'artisan serve')
> docker compose -f docker-compose.prod.yml exec queue-worker ps -o args
> #   -> php artisan queue:work --tries=3 --timeout=90
> docker compose -f docker-compose.prod.yml logs reverb | tail
> crontab -l                                            # NO debe haber schedule:run
> ```
> La prueba de humo de WebSockets es la que más importa: emitir una
> notificación y confirmar que llega al navegador — valida de una sola vez el
> `REVERB_HOST=reverb`, el `REVERB_SCHEME=http` y el proxy `/app` de Nginx.

### Archivos Modificados
- `backend/docker-entrypoint.prod.sh` — **reescrito**: solo preparación (`storage:link` + 4 cachés) y `exec "$@"`; eliminados `serve`, `queue:work` y `reverb:start` cableados
- `backend/Dockerfile.prod` — `CMD` por defecto para que `exec "$@"` nunca quede sin comando
- `docker-compose.prod.yml` — `command:` explícito en `backend`, nuevo servicio `reverb` (4.º contenedor PHP) con healthcheck propio, ancla YAML compartida, override de `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME`, `8080` movido de `backend` a `reverb`
- `.env.production.example` — `REVERB_HOST=reverb`, `REVERB_SCHEME=http`, `REVERB_SERVER_HOST/PORT` documentados con la distinción cliente/servidor
- `deploy.sh` — el paso 7 reinicia también `reverb`; nota sobre por qué las cachés del paso 6 no se propagan entre contenedores
- `DEPLOY_PRODUCTION.md` — paso 7 prohíbe el cron del host (duplicaba el scheduler), diagrama con los 4 contenedores PHP, tabla de puertos y sección de reinicios individuales actualizados

---

## 53. HOMOLOGACIÓN HORARIA DE TRES CAPAS: `America/Mexico_City` COMO ESTÁNDAR GLOBAL [🟢 CORREGIDO]

La sección 47 alineó la **base de datos** con la aplicación (`SET TIME ZONE` por
sesión). Esta sección cierra el círculo hacia afuera: alinea el **reloj del
sistema operativo** de los contenedores, vuelve la zona de Laravel una variable
de entorno documentada y elimina las ~40 apariciones de la cadena
`'America/Mexico_City'` escritas a mano por el código.

### 53.1 El síntoma: la venta de las 18:00 se registraba "mañana"

Toda venta posterior a las 18:00 CST aparecía en el día siguiente al filtrar el
Historial de Ventas. La aritmética es directa —CST es UTC−6, así que a partir de
las 18:00 locales ya es el día siguiente en UTC— y se reprodujo así:

```
Venta                        2026-08-15T18:30:00-06:00
La misma venta en UTC        2026-08-16T00:30:00+00:00   ← día 16
Filtro "Hoy" en hora local   15/ago 00:00:00-06 .. 15/ago 23:59:59-06  → SÍ entra
Filtro "Hoy" evaluado en UTC 15/ago 00:00:00+00 .. 15/ago 23:59:59+00  → NO entra
```

El instante guardado nunca estuvo mal: lo que estaba mal era **la zona en que se
dibujaba la frontera del día**.

### 53.2 El estándar: una sola zona, tres capas que no pueden divergir

| Capa | Mecanismo | Dónde vive |
| :--- | :--- | :--- |
| 1. Sistema operativo | `TZ=America/Mexico_City` | `docker-compose.yml`, `docker-compose.prod.yml` |
| 2. Laravel / PHP | `date_default_timezone_set()` desde `app.timezone` | `config/app.php` ← `APP_TIMEZONE` |
| 3. PostgreSQL | `SET TIME ZONE` por sesión (sección 47) + `-c timezone` en el servidor de desarrollo | `config/database.php`, `docker-compose.yml` |

La **capa 2 es la única fuente de verdad**. Las otras dos la reciben: el `.env`
alimenta `APP_TIMEZONE`, y `config/database.php` reenvía `app.timezone` a la
sesión de Postgres. Cambiar de plaza es **una línea del `.env`**, no una cacería
de literales.

### 53.3 Capa 1 — Docker

`TZ` en los cuatro servicios pedidos. En **desarrollo** se declara servicio por
servicio (`backend`, `scheduler`, `queue-worker`, `postgres`); en **producción**
va una sola vez en el ancla YAML `x-backend-service`, de modo que `backend`,
`reverb`, `scheduler` y `queue-worker` no puedan quedarse con relojes distintos
—el arqueo de las 21:00 lo dispara el `scheduler` y lo ejecuta el `queue-worker`,
así que una divergencia entre ambos sería invisible y devastadora—.

Dos detalles que no son obvios:

- **En el contenedor de PostgreSQL, `TZ` no basta.** El parámetro `timezone` del
  servidor lo escribe `initdb` la **primera** vez y queda grabado en
  `postgresql.conf`: un volumen `postgres-data` creado antes de esta corrección
  seguiría sirviendo en UTC por más `TZ` que se le pase. Por eso el servicio
  arranca además con `-c timezone=… -c log_timezone=…`, que sí manda sobre un
  volumen preexistente. Se añade también `PGTZ` para los clientes libpq (`psql`,
  `pg_dump`) que corren dentro del contenedor.
- **En producción no hay servicio `postgres` que alinear.** La base es la Managed
  PostgreSQL de DigitalOcean: corre en UTC y **no se reconfigura**. No hace falta:
  la sesión se alinea sola en cada conexión gracias a la sección 47. Alinear el
  servidor gestionado habría sido un cambio de infraestructura sin beneficio
  sobre uno de configuración de aplicación que ya funciona.

### 53.4 Capa 2 — Laravel

```php
'timezone' => env('APP_TIMEZONE', 'America/Mexico_City'),
```

El default deja la zona del negocio garantizada aunque la variable falte, así que
un `.env` viejo en el Droplet **no puede revivir el desfase**. `APP_TIMEZONE`
queda documentada en `backend/.env.example` y en `.env.production.example`, y
declarada explícitamente en los `environment:` de ambos compose junto al `TZ` del
SO —las dos capas viajan pegadas para que nadie mueva una y olvide la otra—.

### 53.5 Capa 3 — Consultas: el literal escrito a mano ERA el pasivo

Los filtros ya pasaban la zona explícita (`Carbon::parse($fecha, 'America/Mexico_City')`),
así que **el Historial de Ventas ya calculaba bien sus fronteras**. El problema
era otro: con `app.timezone` convertida en variable de entorno, esas ~40 cadenas
literales se vuelven una **segunda fuente de verdad que el `.env` no puede
mover**. Alguien que cambiara `APP_TIMEZONE` obtendría una mitad del sistema en
una zona y la otra mitad en la anterior — un bug peor que el original, porque
sería intermitente.

La regla quedó invertida: **el código no vuelve a escribir la zona**.

- `Carbon::now(…)` / `now(…)` / `Carbon::parse($x, …)` → **sin argumento de
  zona**. Laravel ya llamó `date_default_timezone_set()` al arrancar, así que
  `Carbon::now()`, `Carbon::today()` y `Carbon::now()->endOfDay()` nacen en hora
  local por construcción.
- Conversión de un instante ya hidratado (`serializeDate()` de `Order`,
  `OrderItem`, `Table`, `TableSession`, `SystemNotification`) → `Timezone::app()`.
- Agrupaciones en SQL crudo (`AT TIME ZONE`) → `Timezone::sqlLiteral()`.

**`App\Support\Timezone`** (nuevo) es el único lugar que menciona la zona:

| Método | Uso |
| :--- | :--- |
| `Timezone::app()` | Lee `config('app.timezone')`, con la zona del negocio como respaldo |
| `Timezone::sqlLiteral()` | El mismo valor **validado contra el catálogo IANA de PHP** y entrecomillado para SQL |

La validación de `sqlLiteral()` no es decorativa: `AT TIME ZONE` se usa dentro de
expresiones de `GROUP BY` que deben coincidir carácter por carácter con las del
`SELECT`, así que el valor viaja **interpolado** y no como binding. Verificar que
sea un identificador IANA real antes de entrecomillarlo es lo que impide que un
`APP_TIMEZONE` manipulado se convierta en un vector de inyección; si no lo es, la
consulta ni siquiera se construye.

### 53.6 Bugs reales de frontera encontrados de paso

Tres listados **sí** usaban la cadena cruda del request como límite, sin anclarla
al día. `'2026-08-15'` se interpreta como las **00:00:00** en ambos extremos, de
modo que el filtro "hasta" excluía el día entero que el usuario acababa de pedir:

| Archivo | Defecto | Corrección |
| :--- | :--- | :--- |
| `StockMovementController@index` | `where('created_at', '<=', $request->date_to)` | `Carbon::parse(…)->startOfDay()` / `->endOfDay()` |
| `StockMovementController@summary` | Límites por defecto mezclando `toDateString()` y `toDateTimeString()` | Ambos extremos como `Carbon` anclado |
| `PettyCashController@index` | Igual que el anterior, y con `has()` en vez de `filled()` (un `?date_from=` vacío llegaba a Carbon) | `filled()` + anclaje al día |
| `JobMonitorController@index` | `date_to` ya anclaba, `date_from` no — asimétrico | `->startOfDay()` en el "desde" |

### 53.7 La excepción deliberada: el arqueo de las 21:00

`routes/console.php` **conserva el literal** en el `->timezone('America/Mexico_City')`
del schedule, y es la única excepción a 53.5. Desde que el contenedor `scheduler`
corre con `TZ`, la línea es redundante en la práctica — y se queda justamente por
eso: el cierre automático de caja es la operación financiera más crítica del día y
no debe poder moverse de hora por un `APP_TIMEZONE` mal escrito ni por un `TZ` que
alguien quite del compose. **Aquí la redundancia es el candado, no un descuido.**
El comentario del bloque, que afirmaba "el contenedor corre en UTC", quedó
actualizado: ya no es cierto.

### 53.8 Guardia de regresión

`tests/Unit/TimezoneAlignmentTest.php` (nuevo, sin base de datos: solo lee
archivos del repositorio). `MexicoTimezoneTest` prueba la **aritmética** de los
límites; esta clase prueba que las tres capas sigan **apuntando a la misma zona**:

- `app.timezone` resuelve a `America/Mexico_City` y se lee de `APP_TIMEZONE`.
- La conexión `pgsql` sigue heredando `app.timezone` (candado de la sección 47).
- Los cuatro servicios exigidos declaran `TZ` en **cada** compose — reconociendo
  tanto la declaración directa como la herencia vía `<<: *backend-service`.
- El `TZ` de producción vive en el ancla compartida, no repetido por servicio.
- El PostgreSQL de desarrollo arranca con `-c timezone=…`.
- **Cero zonas escritas a mano** en `app/`: barre el árbol y falla nombrando el
  archivo infractor.

### 53.9 Verificación

| Comprobación | Resultado |
| :--- | :--- |
| `php -l` sobre los 22 archivos modificados | Sin errores de sintaxis |
| `docker compose -f docker-compose.yml config` | Válido |
| `TZ` renderizado por servicio (ambos compose) | `America/Mexico_City` en los 4 exigidos de cada archivo; el ancla de prod se expande a los 4 contenedores PHP |
| Venta 18:30 CST contra el filtro "Hoy" local | **Entra** en el día correcto |
| La misma venta contra un filtro evaluado en UTC | Queda fuera — el bug reportado, reproducido |
| Literales `'America/Mexico_City'` en `app/` | 0 en cálculos de fecha (quedan 2, ambos en texto: un docblock y una etiqueta de bitácora) |
| Expresiones de `GROUP BY` vs. `SELECT` tras usar `sqlLiteral()` | Idénticas — ambas salen de la misma llamada |

**Prueba de que la guardia sirve** (5 mutaciones inyectadas y revertidas): quitar
el `TZ` de un servicio de desarrollo, revertir `app.timezone` a `'UTC'`, quitar el
`TZ` del ancla de producción, reintroducir la zona a mano en `OrderController` y
borrar el `SET TIME ZONE` de `config/database.php` → **las 5 fueron detectadas**,
cada una nombrando su causa.

> ⚠️ **Verificación estática.** La suite PHPUnit no se ejecutó en esta sesión: el
> proxy de egress bloquea `repo.packagist.org` (misma restricción de las secciones
> 43.5, 51.4 y 52.8), así que no hay `vendor/`. Las aserciones de
> `TimezoneAlignmentTest` se ejecutaron con un script PHP equivalente que replica
> su lógica (16/16 en verde). En el Droplet, tras el deploy, confirmar:
> ```
> docker compose -f docker-compose.prod.yml exec backend date       # CST, no UTC
> docker compose -f docker-compose.prod.yml exec scheduler date     # el MISMO reloj
> docker compose -f docker-compose.prod.yml exec backend php artisan tinker
> #   >>> config('app.timezone')  -> "America/Mexico_City"
> #   >>> now()->format('c')      -> ...-06:00
> ```
> La prueba de humo que más importa: registrar una venta después de las 18:00 y
> confirmar que el Historial la muestra **en el día de hoy**.

### 53.10 Nota operativa para cierres y reportes

El histórico anterior a la sección 47 **sigue sin migrarse** y esa decisión no
cambia aquí. Lo que esta sección garantiza es que, de ahora en adelante, la
frontera del día sea la misma en los tres lugares donde se calcula —el filtro de
Eloquent, la agregación de PostgreSQL y el `date` del contenedor que dispara el
scheduler—, que es la condición para que un corte de caja, un reporte mensual y
la pantalla del cajero puedan cuadrar entre sí.

### Archivos Creados
- `backend/app/Support/Timezone.php` — fuente única de la zona (`app()` y `sqlLiteral()` validado)
- `backend/tests/Unit/TimezoneAlignmentTest.php` — guardia estática de las tres capas

### Archivos Modificados
- `docker-compose.yml` — `TZ` + `APP_TIMEZONE` en `backend`, `scheduler` y `queue-worker`; `TZ`/`PGTZ` y `-c timezone`/`-c log_timezone` en `postgres`
- `docker-compose.prod.yml` — `TZ` + `APP_TIMEZONE` en el ancla `x-backend-service` (cubre los 4 contenedores PHP); nota sobre por qué no hay `postgres` que alinear
- `backend/config/app.php` — `'timezone' => env('APP_TIMEZONE', 'America/Mexico_City')` y comentario que documenta el ancla de tres capas
- `backend/.env.example`, `.env.production.example` — `APP_TIMEZONE` documentada
- `backend/app/Http/Controllers/Sales/OrderController.php` — filtros del Historial sin zona a mano, con el porqué del bug en el comentario
- `backend/app/Http/Controllers/Sales/SalesExportController.php`, `Sales/DailySummaryController.php` — íd.
- `backend/app/Http/Controllers/Finance/CashRegisterClosingController.php`, `Finance/PettyCashController.php`, `Finance/AnalyticsController.php` — íd. (+ `sqlLiteral()` en `AT TIME ZONE`)
- `backend/app/Http/Controllers/Dashboard/DashboardController.php`, `Dashboard/MonthlyAnalyticsController.php` — íd.; eliminada la constante local `TZ`
- `backend/app/Http/Controllers/Logistics/StockMovementController.php` — **bug de frontera corregido** en `index` y `summary`
- `backend/app/Http/Controllers/Admin/JobMonitorController.php` — `date_from` anclado con `startOfDay()` (era asimétrico)
- `backend/app/Models/{Order,OrderItem,Table,TableSession,SystemNotification}.php` — `serializeDate()` vía `Timezone::app()`
- `backend/app/Console/Commands/AutoCloseCashRegisters.php` — `now()` y `Timezone::app()`
- `backend/routes/console.php` — comentario actualizado; el literal del schedule de las 21:00 **se conserva a propósito** (53.7)

---

## 54. RENDIMIENTO Y BLINDAJE DEL PAYLOAD: PROYECCIÓN DE COLUMNAS, CACHÉ DINÁMICO EN REDIS Y PERCEPCIÓN DE CARGA [🟢 COMPLETADO Y OPERATIVO]

Cuatro trabajos que atacan el mismo problema desde capas distintas: **cuánto
viaja**, **cada cuánto se calcula**, **qué ve el usuario mientras espera** y
**dónde queda parado al volver**. Las tres primeras son de rendimiento medible;
la primera es, además, de seguridad de datos.

### 54.1 Data Masking / Payload Reduction: se prohíbe el `SELECT *` implícito

Un `Model::with('relacion')->get()` sin `select()` es un `SELECT *` en las dos
tablas. Eso no es solo peso: es **superficie de exposición**. Cada columna que
nadie pinta viaja igual al navegador y queda en el *network tab* de cualquier
terminal del piso.

El caso más caro estaba en el Historial de Ventas. `Order::with(['items.product',
...])` traía, por cada renglón de la tabla, el producto completo de cada partida
del ticket —**incluido `cost_price`**—: el margen del negocio publicado a
cualquier cajero que abriera el historial. Y `cashRegister.user` sin acotar
adjuntaba la cuenta entera del cajero (teléfono, estado de 2FA,
`password_restored_at`, rastro de borrado lógico) a cada venta listada.

La regla aplicada en toda vista principal es doble:

| Mecanismo | Qué resuelve | Ejemplo |
| :--- | :--- | :--- |
| `select([...])` explícito | El `SELECT *` de la tabla base | `Order::settled()->select(self::LIST_COLUMNS)` |
| Eager loading acotado (`with('user:id,name')`) | El `SELECT *` de la relación | `'cashRegister.user:id,name'` |

Las columnas se eligen **por lo que el componente de React realmente pinta**, no
por lo que "podría servir":

| Endpoint | Antes | Ahora |
| :--- | :--- | :--- |
| `GET /orders` (Historial) | Orden completa + `items.product` + `ticketConfig` + `promotion` + `table` + usuario completo | 11 columnas + `cashRegister.user:id,name` + `paymentMethod:id,name` |
| `GET /orders/{id}` (detalle/reimpresión) | `items.product` completo, usuarios completos | `items.product:id,name,sku`, usuarios a `id,name` |
| `GET /products/grouped` (POS) | Producto completo + categoría | 8 columnas, **sin `cost_price`**, sin categoría (nadie la pinta) |
| `GET /products` (catálogo) | Producto completo | 11 columnas; fuera `maximum_stock`, `image_url`, rastro de borrado y timestamps |
| `GET /admin/users` | Cuenta completa + roles completos | `id,name,email,status,created_at` + `roles:id,name` |
| `GET /admin/cierres` | `cashRegister.user` y `closedByUser` completos | ambos a `id,name,email` |
| `GET /tables` (plano) | Mesa completa + orden viva completa | 6 columnas + orden a `id,subtotal,iva_total,total` + `items_count` |
| `GET /sales/export` (Excel, sin paginar) | Orden completa + 4 relaciones | 11 columnas + 2 relaciones acotadas |

> **Por qué el `select()` y no solo `$hidden`.** El modelo `User` ya oculta
> `password` y los secretos de 2FA, pero `$hidden` es una **lista de negación**:
> la próxima columna que se agregue a la tabla queda expuesta por omisión. El
> `select()` explícito invierte la lógica a lista de permitidos.

Dos detalles que hay que respetar al tocar estas consultas:

- **La llave foránea no es decorativa.** `category_id`, `cash_register_id` y
  `payment_method_id` viajan porque son la columna con la que Eloquent empareja
  el *eager load*. Quitarlas deja todas las relaciones en `null`.
- **`deleted_at` viaja solo cuando se pide `include_deleted`.** `SoftDeletes`
  resuelve `trashed()` leyendo esa columna, así que se agrega al `select()` de
  forma condicional en `ProductController` y `CategoryController`.

### 54.2 Caché dinámico en base de datos: la ventana la decide un administrador

El TTL dejó de vivir en PHP. La tabla **`cache_configurations`**
(`module_name` único, `duration_minutes`, `is_active`) guarda una fila por cada
lectura pesada, y `App\Support\ModuleCache` la resuelve justo antes de responder.
Ampliar la ventana del Dashboard es una escritura en base de datos desde
`Configuración → Caché de Módulos`, no un *redeploy*.

**Módulos cacheables** (`CacheConfiguration::MODULES`): `dashboard_stats`,
`dashboard_hourly_trend`, `dashboard_top_products`, `monthly_analytics`,
`product_catalog`.

**Ventanas ofrecidas** (lista cerrada, `DURATION_OPTIONS`): 15 min, 30 min, 1 h,
1 día (1440) y 2 días (2880). Es deliberadamente cerrada: un TTL arbitrario
escrito a mano es como un Dashboard termina congelado una semana.

El flujo de una lectura:

```
Petición  →  ModuleCache::remember($módulo, $clave, $consulta)
                 │
                 ├─ CacheConfiguration::durationFor($módulo)
                 │     ├─ null  (política inactiva o duración inválida)
                 │     │        → se ejecuta la consulta y NO se guarda nada
                 │     └─ N minutos
                 │
                 ├─ registra la clave en el índice del módulo
                 └─ Cache::remember("module_cache:{módulo}:{clave}", N*60, …)   → Redis
```

Tres decisiones de diseño que conviene no deshacer:

1. **El mapa de políticas también se cachea.** Leer la tabla en cada petición
   para decidir si cachear anularía el beneficio. `CacheConfiguration::policies()`
   guarda las cinco filas como un solo arreglo bajo
   `cache_configurations:policies`, y cualquier escritura lo invalida en el
   `saved`/`deleted` del modelo.

2. **No se usan *cache tags* de Redis.** Los tags no existen en el driver
   `database`, y atarse a un driver por comodidad es una deuda. En su lugar cada
   clave guardada se apunta en un índice por módulo
   (`module_cache_index:{módulo}`, podado a 200 claves) y `flush()` recorre ese
   índice llamando `Cache::forget()`. Funciona igual en Redis, en `database` y
   con el driver `array` de las pruebas.

3. **Las claves llevan la fecha dentro.** `dashboard_stats` se guarda bajo el día
   (`2026-08-15`) y `monthly_analytics` bajo el mes (`2026-08`). El cambio de día
   cae naturalmente en una entrada nueva en lugar de reproducir las cifras de
   ayer, y navegar meses históricos se sirve siempre de Redis porque esos meses
   ya no cambian.

Driver: **Redis** (`CACHE_STORE=redis` en `docker-compose.yml` y en
`.env.production.example`). La suite de pruebas corre sobre el driver `array`
sin ningún cambio de código.

### 54.3 Invalidación crítica: una venta nueva tira el Dashboard de "Hoy"

Una ventana de dos días sería inaceptable si "Órdenes Hoy" tardara dos días en
reflejar la venta que el cajero acaba de cobrar. Por eso la invalidación **no
vive en los controladores sino en los modelos**:

| Modelo | Evento | Módulos purgados | Por qué |
| :--- | :--- | :--- | :--- |
| `Order` | `saved`, `deleted` | `dashboard_stats`, `dashboard_hourly_trend`, `dashboard_top_products`, `monthly_analytics` | Toda cifra del Dashboard se deriva de esta tabla |
| `Product` | `saved`, `deleted` | `product_catalog`, `dashboard_stats` | Precio, baja lógica y **cada decremento de stock** de una venta |

Colgar el *hook* del modelo y no del controlador es intencional: una orden se
escribe desde cuatro caminos distintos —venta de mostrador, cobro de mesa,
cancelación y cierre automático de las 21:00— y enganchar cada uno es como
eventualmente se olvida uno. En `saved` la purga es incondicional, y sigue siendo
barata: un puñado de `DEL` en Redis.

El mismo criterio aplica al cambio de política: acortar la ventana de 2 días a 15
minutos purga de inmediato lo guardado bajo la ventana anterior, en lugar de
esperar a que expire el TTL viejo que ya nadie configuró.

Además, cada módulo tiene un botón **Purgar ahora** en la UI
(`POST /admin/cache-configurations/{id}/flush`) para el caso en que los datos se
muevan por un camino que el sistema no observa: una corrección directa en base de
datos o un rollback desde la bóveda de respaldos.

### 54.4 Administración: nueva pestaña `Caché de Módulos`

Vive en `Configuración del Sistema`, junto a `Notificaciones / Emails`, y como
ella está detrás de `role:admin` en las dos capas (la pestaña no se renderiza
para *manager*, y las rutas llevan el middleware). Decidir cuán vieja puede ser
la cifra que ve todo el negocio es una facultad de administrador.

La tabla lista **siempre el catálogo completo**: los módulos sin política
aparecen como *Sin configurar* y corren con la ventana por defecto (15 min), de
modo que las consultas más pesadas nunca quedan sin cachear esperando a que
alguien visite la pantalla. El `module_name` no se puede reasignar en una fila
existente —cambiarlo dejaría huérfanos los *payloads* guardados bajo el módulo
anterior—: se elimina la política y se crea otra.

### 54.5 Skeleton Loaders: el Dashboard se dibuja a sí mismo mientras carga

El *spinner* centrado desapareció. `DashboardSkeleton` reproduce la geometría
final —cuatro tarjetas de KPI en la misma grilla `1 / 2 / 4`, el panel ancho de
la gráfica con barras escalonadas, y el panel de Top 5 con su dona (núcleo blanco
que imita el `innerRadius` real) y sus cinco renglones de leyenda— usando las
mismas clases de Tailwind que el componente real.

Ese calco es el punto: las cajas caen en los píxeles que ocupará el contenido, así
que cuando llegan los datos **nada se mueve**. Un *spinner* no le da al ojo ningún
ancla y todo aparece de golpe; un esqueleto que coincide con la estructura final
se lee como "ya estoy cargando *esta* pantalla", y por eso la latencia percibida
baja aunque las peticiones tarden exactamente lo mismo.

Dos matices de implementación:

- **`animate-pulse` solo en los bloques placeholder**, nunca en la tarjeta ni en
  su `ring`. Animar el contenedor completo hace latir la página entera y se ve
  notoriamente peor.
- **El esqueleto solo aparece cuando no hay nada que dibujar** (`loading && !stats`).
  En una recarga posterior `stats` conserva el payload anterior y la pantalla
  sigue mostrando cifras reales en lugar de degradarse a cajas grises, que se
  leería como una regresión y no como carga.

El envoltorio lleva `role="status"` y `aria-busy`, y los bloques decorativos van
con `aria-hidden`.

### 54.6 UX: el Sidebar recuerda dónde estaba parado el usuario

El scroll de un `<nav>` es estado del navegador, no de React: al refrescar la
página en `/admin/papelera`, el menú se reconstruía arriba del todo y la opción
en la que se estaba trabajando quedaba debajo del pliegue.

`Sidebar` ahora localiza el enlace activo **en el DOM** —`nav.querySelector('a[aria-current="page"]')`—
en lugar de compararlo contra `pathname`. La razón es que React Router ya resolvió
ese problema: `NavLink` estampa `aria-current="page"` en la entrada que considera
activa, incluyendo coincidencias parciales y anidadas que una comparación de
cadenas fallaría. El `useRef` sobre el `<nav>` acota la búsqueda para que ningún
otro `aria-current` de la página se cuele.

```js
element.scrollIntoView({ behavior: 'auto', block: 'center' });
```

- **`behavior: 'auto'` y nunca `'smooth'`**: la corrección tiene que estar
  terminada en el primer fotograma que el usuario ve, para que se lea como la
  posición en la que el sidebar siempre estuvo y no como una animación que
  reacciona a su llegada.
- **`block: 'center'`** deja entradas visibles arriba y abajo, que es el contexto
  que vuelve legible la posición.
- **Dos guardas** lo vuelven un *no-op* en el caso ordinario: no hace nada si la
  lista no desborda, y no hace nada si el enlace ya está completamente dentro de
  la banda visible. Solo desplazar cuando de verdad está fuera de pantalla es lo
  que evita que un clic normal sacuda el menú bajo el cursor.
- **El scroll de la ventana se restaura.** `scrollIntoView` sube por todos los
  ancestros desplazables y puede arrastrar el documento; el sidebar es `fixed` y
  la página detrás no debe moverse, así que se captura y se repone el offset.

### 54.7 Pruebas

`tests/Feature/Performance/DynamicCacheTest.php` fija las propiedades por las que
existe el módulo (11 casos):

- La duración se lee de la base de datos; un módulo sin fila cae en el default.
- Una política **inactiva** hace que la consulta corra en vivo y no guarde nada.
- Una política activa memoriza, y `flush()` obliga a reconstruir.
- **Una venta nueva invalida el Dashboard de hoy** aun con ventana de 2 días.
- Acortar la ventana purga lo guardado bajo la anterior.
- Solo `admin` gobierna las políticas; un `manager` recibe 403.
- El catálogo lista los módulos sin configurar.
- Solo se aceptan las cinco ventanas del catálogo, y hay una política por módulo.
- **`GET /orders` no contiene `cost_price` en ninguna parte del cuerpo**, ni el
  correo del cajero, ni `items`.
- **`GET /products/grouped` no publica el margen del negocio.**
- `GET /admin/users` no devuelve `two_factor_confirmed_at`, `password_restored_at`
  ni `deleted_at`.

### Archivos Creados
- `backend/database/migrations/2026_08_15_000001_create_cache_configurations_table.php` — tabla de políticas
- `backend/app/Models/CacheConfiguration.php` — catálogo de módulos, ventanas, `durationFor()` y purga en `saved`/`deleted`
- `backend/app/Support/ModuleCache.php` — `remember()` / `flush()` con TTL dinámico e índice de claves por módulo
- `backend/app/Http/Controllers/Admin/CacheConfigurationController.php` — CRUD, catálogos y purga manual (admin-only)
- `backend/app/Http/Requests/CacheConfiguration/{Store,Update}CacheConfigurationRequest.php` — validación contra el catálogo cerrado
- `backend/tests/Feature/Performance/DynamicCacheTest.php` — 11 casos de caché dinámico y enmascarado de payload
- `frontend/src/components/settings/CacheSettingsPanel.jsx` — pestaña de administración de caché
- `frontend/src/components/dashboard/DashboardSkeleton.jsx` — esqueleto que calca la geometría del Dashboard

### Archivos Modificados
- `backend/routes/api.php` — grupo `admin/cache-configurations` bajo `role:admin`
- `backend/app/Models/Order.php` — invalidación de los cuatro módulos de venta en `saved`/`deleted`
- `backend/app/Models/Product.php` — invalidación de catálogo POS y alertas de stock
- `backend/app/Http/Controllers/Dashboard/DashboardController.php` — las tres consultas envueltas en `ModuleCache`; mes y promedio en una sola agregación
- `backend/app/Http/Controllers/Dashboard/MonthlyAnalyticsController.php` — payload completo memoizado por mes (`buildPayload()`)
- `backend/app/Http/Controllers/Catalog/ProductController.php` — `LIST_COLUMNS`, `grouped()` cacheado y sin `cost_price`
- `backend/app/Http/Controllers/Catalog/CategoryController.php` — `select()` explícito
- `backend/app/Http/Controllers/Sales/OrderController.php` — `LIST_COLUMNS` y relaciones acotadas en `index()` y `show()`
- `backend/app/Http/Controllers/Sales/SalesExportController.php` — proyección de las 11 columnas del Excel; fuera `promotion` y `table`
- `backend/app/Http/Controllers/Admin/UserController.php` — `select()` explícito y `roles:id,name`
- `backend/app/Http/Controllers/Finance/CashRegisterClosingController.php` — `cashRegister.user` y `closedByUser` acotados a `id,name,email` en las seis consultas
- `backend/app/Http/Controllers/Dining/TableController.php` — `select()` del plano y orden viva reducida a sus columnas de dinero
- `frontend/src/pages/DashboardPage.jsx` — esqueleto en lugar del spinner, solo en la primera carga
- `frontend/src/pages/admin/SystemSettingsPage.jsx` — pestaña `Caché de Módulos` (admin-only)
- `frontend/src/components/layout/Sidebar.jsx` — auto-scroll silencioso al enlace activo (`useRef` + `useEffect` + `scrollIntoView`)

---

## 55. ESTANDARIZACIÓN DE FECHAS EN EL CLIENTE: `YYYY-MM-DD` SIEMPRE EN HORA LOCAL [🟢 CORREGIDO]

La sección 53 alineó las tres capas del **servidor** (SO → Laravel → PostgreSQL)
para que la frontera del día se dibujara en `America/Mexico_City`. Esta sección
cierra el último eslabón, que quedó fuera de aquel trabajo: **el navegador**. El
backend contestaba correctamente; la pregunta que le llegaba estaba mal.

### 55.1 El síntoma: "Hoy" devolvía la lista vacía después de las 18:00

A partir de las 6:00 PM CST, el filtro rápido **Hoy** del Historial de Ventas
dejaba de mostrar las ventas del día —incluidos los tickets que el cajero acababa
de cobrar minutos antes—. La causa es de una línea:

```js
new Date().toISOString().split('T')[0]
```

`toISOString()` **convierte el instante a UTC antes de formatearlo**, así que el
día calendario que imprime es el día UTC, no el del reloj de pared del usuario.
En México (UTC−6) ambos dejan de coincidir cada tarde a las 18:00:

```
Reloj local                 2026-08-14 18:30 (CST, UTC−6)
new Date().toISOString()    "2026-08-15T00:30:00.000Z"
      .split('T')[0]        "2026-08-14"  →  NO: "2026-08-15"   ← mañana
```

El frontend pedía `date_from=2026-08-15&date_to=2026-08-15` mientras el backend
—correctamente anclado en hora local desde la sección 53— respondía que ese día
todavía no tenía ventas. **Ninguna de las dos capas estaba mal por separado: no
estaban hablando del mismo día.**

### 55.2 La regla: `toISOString()` queda prohibido para fechas calendario

Se creó **`frontend/src/lib/dates.js`** como fuente única, con la justificación
del bug escrita en el propio archivo para prevenir la regresión. La regla es:

| Tipo de dato | Qué es | Cómo se serializa |
| :--- | :--- | :--- |
| **Fecha calendario** (`date_from`, `date_to`, nombre de un archivo exportado) | Un día en el calendario del usuario | `toLocalYmd()` / `todayYmd()` — componentes locales, sin pasar por UTC |
| **Fecha + hora elegida** (vigencia de una promoción) | Un reloj de pared que el admin escribió | `toLocalDateTime()` → `'YYYY-MM-DD HH:mm:ss'` |
| **Instante** (`created_at`, `_queued_at`, sello de un ticket) | Un punto en la línea de tiempo | `toISOString()` **sigue siendo lo correcto**: es inequívoco y el receptor lo renderiza en local |

La distinción importa: `toISOString()` no es el enemigo, lo es usarlo para algo
que no es un instante. Las cuatro llamadas que quedan en el código
(`CheckoutModal`, `useOnlineStatus`, dos en `TicketConfigPage`) son instantes y
se conservan a propósito.

Se eligió **no agregar `date-fns`**: el cálculo es aritmética de componentes que
el navegador ya resolvió en hora local, y el bundle ya pesa 1.8 MB. La utilidad
completa son ~40 líneas de código.

### 55.3 API de `lib/dates.js`

| Función | Devuelve | Para qué |
| :--- | :--- | :--- |
| `toLocalYmd(date)` | `'2026-08-14'` \| `null` | **La única** autorizada a producir `date_from` / `date_to` |
| `todayYmd()` | `'2026-08-14'` | El día de hoy en el reloj del usuario |
| `toLocalDateTime(date)` | `'2026-08-14 18:30:00'` \| `null` | Pickers con `showTime` |
| `parseLocalYmd(value)` | `Date` a medianoche local \| `null` | El problema inverso (55.5) |
| `addDays(date, n)` | `Date` nueva | Rangos rápidos, sin mutar el original |
| `addMonths(date, n)` | `Date` nueva | Íd., recortando al último día del mes destino |
| `startOfMonth(date)` | `Date` nueva | Filtro "Mes" |

### 55.4 Alcance de la corrección

| Archivo | Qué se corrigió |
| :--- | :--- |
| `components/layout/AppHeader.jsx` | Contador de "ventas de hoy": pedía el día UTC |
| `pages/sales/SalesHistoryPage.jsx` | Filtros rápidos (Hoy / Semana / Mes), rango manual del `Calendar` y nombre del Excel |
| `pages/admin/CashRegisterClosingsPage.jsx` | Íd. (Hoy / Semana / Mes), rango manual y nombre del Excel |
| `pages/finance/FinanceDashboardPage.jsx` | Copia local del formateador → utilidad compartida |
| `pages/admin/CashClosingsAuditPage.jsx` | Copia local de `toYmd` → utilidad compartida |
| `pages/admin/JobsMonitorPage.jsx` | Íd. |
| `pages/promotions/PromotionsPage.jsx` | Vigencia de la promoción (55.6) |

> **Sobre las tres copias locales.** `FinanceDashboardPage`, `CashClosingsAuditPage`
> y `JobsMonitorPage` **ya calculaban bien la fecha**, cada una con su propia
> versión del mismo formateador. No estaban rotas, pero tres implementaciones
> paralelas de una regla es exactamente cómo una de ellas se desvía en el
> siguiente refactor. Ahora las siete pantallas comparten una sola función.

### 55.5 El problema inverso: parsear `'YYYY-MM-DD'` de vuelta

`new Date('2026-08-14')` está especificado para interpretar una fecha desnuda
como **medianoche UTC**, que en México se renderiza como las 18:00 del **día 13**.
Es el mismo error en espejo. `parseLocalYmd()` construye la fecha desde sus
partes y la mantiene en el día que la cadena nombra. Se aplicó en el eje del
gráfico de `FinanceDashboardPage`, que hasta ahora lo esquivaba con el truco de
concatenar `'T12:00:00'` —funcionaba, pero por accidente y sin explicar por qué—.

### 55.6 Hallazgo adicional: la vigencia de las promociones nacía 6 horas tarde

Al revisar los *datepickers* (requisito 2) apareció un bug del mismo origen pero
con otro efecto. Ambos `Calendar` del formulario de promociones corren con
`showTime`: el administrador elige un **instante exacto**. El formulario enviaba
`formData.start_date.toISOString()`, es decir el mismo instante expresado en UTC:

```
El admin elige          14/08/2026 00:00  (inicio de la promoción)
Viajaba como            "2026-08-14T06:00:00.000Z"
PHP (ya en CST) escribía 2026-08-14 06:00:00   ← 6 horas tarde
```

Una promoción configurada para arrancar a medianoche arrancaba a las 6 AM, y una
configurada para terminar a medianoche seguía viva seis horas de más. La
comparación que decide si aplica (`start_date <= now()`) usa un `now()` local, así
que el valor comparado tiene que ser local también. Ahora viaja como
`'2026-08-14 00:00:00'` vía `toLocalDateTime()`.

> **Nota operativa.** Las promociones **ya guardadas** conservan su desfase de 6
> horas: esto corrige lo que se escribe de aquí en adelante, no migra el
> histórico. Si alguna promoción vigente tiene una hora de inicio o fin que no
> cuadra, basta reabrirla y volver a guardarla para que quede con el reloj
> correcto.

### 55.7 Verificación

Se ejecutó la utilidad bajo `TZ=America/Mexico_City` reproduciendo el escenario
del reporte (14/08/2026 18:30) — 17 comprobaciones, todas en verde:

```
legacy toISOString().split("T")[0] => 2026-08-15      ← el bug, reproducido
toLocalYmd(18:30)                  => 2026-08-14      ← corregido
toLocalYmd(23:59:59)               => 2026-08-14
week  -> date_from                 => 2026-08-07
month -> date_from                 => 2026-07-14
31/Mar addMonths(-1)               => 2026-02-28      ← sin desbordar a marzo
addDays(-7) cruzando el año        => 2025-12-27
toLocalDateTime(00:00)             => 2026-08-14 00:00:00
legacy new Date("2026-08-14")      => día 13          ← el problema inverso
parseLocalYmd("2026-08-14")        => 2026-08-14
```

> La prueba de humo que más importa: entrar al Historial de Ventas **después de
> las 18:00** y confirmar que el filtro **Hoy** sigue mostrando las ventas del
> día.

### Archivos Creados
- `frontend/src/lib/dates.js` — fuente única de fechas locales, con la explicación del bug documentada en el archivo

### Archivos Modificados
- `frontend/src/components/layout/AppHeader.jsx` — contador de ventas del día
- `frontend/src/pages/sales/SalesHistoryPage.jsx` — filtros rápidos, rango manual y nombre del Excel
- `frontend/src/pages/admin/CashRegisterClosingsPage.jsx` — íd.
- `frontend/src/pages/admin/CashClosingsAuditPage.jsx` — `toYmd` local eliminado
- `frontend/src/pages/admin/JobsMonitorPage.jsx` — íd.
- `frontend/src/pages/finance/FinanceDashboardPage.jsx` — `formatDate` local eliminado; eje del gráfico con `parseLocalYmd`
- `frontend/src/pages/promotions/PromotionsPage.jsx` — vigencia enviada como reloj de pared local

---

## 56. CORRECCIÓN CRÍTICA: EL RELAY DE SENDGRID SALE POR EL PUERTO 2525 [🟢 CORREGIDO]

`SendConfiguredProcessMail` fallaba **de forma sistemática** en producción con
`TransportException: Operation timed out` contra `smtp.sendgrid.net:587`. El
reporte del cierre automático de caja se encolaba correctamente cada noche,
agotaba sus 3 reintentos (`backoff [30, 120, 300]`) y moría: ningún correo salió
del Droplet. La API Key era válida y SendGrid nunca estuvo caído.

### 56.1 El diagnóstico: no es SendGrid, es el firewall de salida del proveedor

Los proveedores de nube —DigitalOcean, AWS, GCP, Azure— **bloquean el puerto 587
de salida por política anti-spam** en las cuentas nuevas o sin excepción
solicitada. El detalle que hace el error tan confuso es *cómo* lo bloquean:
**descartan los paquetes en silencio** en lugar de rechazar la conexión.

```
Conexión rechazada (puerto cerrado)   → falla en milisegundos, "Connection refused"
Paquetes descartados (política DO)    → el socket espera el saludo SMTP hasta expirar
```

Por eso el síntoma es un **timeout** y no un error de conexión: el
`SocketStream` de Symfony Mailer abre el socket, se queda esperando el banner
`220` del servidor que nunca llega y expira a los 15 s (`timeout` de la
plantilla). El mensaje resultante apunta al host de SendGrid, así que se lee
como una caída del proveedor cuando en realidad el tráfico jamás salió del
Droplet.

### 56.2 La corrección: puerto alterno de submission (2525)

SendGrid publica **2525** como puerto alterno de su relay: mismas credenciales
(usuario literal `apikey`, la API Key como contraseña), mismo `STARTTLS`, misma
ruta de entrega. Lo único que cambia es el número de puerto — y ese número no
entra en las reglas anti-spam de los proveedores, así que atraviesa el bloqueo.

```php
// backend/config/mailing.php
'sendgrid' => [
    'transport' => 'smtp',
    'host' => env('SENDGRID_SMTP_HOST', 'smtp.sendgrid.net'),
    'port' => (int) env('SENDGRID_SMTP_PORT', 2525), // <- 587 lo bloquea la nube
    'encryption' => env('SENDGRID_SMTP_ENCRYPTION', 'tls'),
    'username' => 'apikey',
    'credentials' => 'api_key',
    'timeout' => (int) env('SENDGRID_SMTP_TIMEOUT', 15),
],
```

**Dónde se aplicó y por qué ahí.** El transporte dinámico se arma en
`App\Services\Mail\DynamicMailerFactory` fusionando la fila de
`email_configurations` sobre la plantilla del proveedor (sección 48.2). El puerto
es infraestructura, no dato del inquilino: no vive en la base de datos, vive en
la plantilla. Corregirlo en `config/mailing.php` arregla de un golpe todas las
filas existentes —presentes y futuras— sin tocar un solo registro ni pedirle
nada al administrador. La fábrica también cambió su `?? 587` de respaldo por
`?? 2525`, para que una plantilla a la que le falte el puerto no reintroduzca el
bloqueo por la puerta de atrás.

**Sin dependencias nuevas.** Se evaluó `symfony/sendgrid-mailer` (que saldría
por HTTPS/443 vía API, inmune por definición a cualquier bloqueo de puertos
SMTP), pero exige aprobar una dependencia de Composer y reconstruir la imagen.
El ajuste de puerto resuelve el incidente con un cambio de configuración y cero
dependencias; la vía API queda documentada como evolución si el proveedor
llegara a bloquear también el 2525.

### 56.3 Requerimiento obligatorio de despliegue (VPS / Droplets)

> **El puerto 587 de salida está bloqueado en Droplets, EC2, Compute Engine y
> VMs de Azure. Todo correo saliente de un despliegue en VPS debe usar el
> puerto alterno de submission (2525).** No es una preferencia ni una
> optimización: con 587 el envío no falla rápido, se cuelga hasta expirar y
> quema los reintentos de la cola en silencio.

Aplica a las dos rutas de correo del sistema:

| Ruta | Dónde se configura | Valor obligatorio |
| :--- | :--- | :--- |
| Mailing dinámico por proceso (SendGrid, sección 48) | `backend/config/mailing.php` → `providers.sendgrid.port` (override: `SENDGRID_SMTP_PORT`) | `2525` |
| Mailer estático del `.env` (`MAIL_MAILER=smtp`) | `.env` de producción → `MAIL_PORT` | `2525` |

Diagnóstico en 5 segundos desde el Droplet — si el primero se cuelga y el
segundo conecta, el bloqueo está confirmado:

```bash
nc -zv -w 5 smtp.sendgrid.net 587    # se queda colgado y expira  ← bloqueado
nc -zv -w 5 smtp.sendgrid.net 2525   # "succeeded!"               ← ruta buena
```

Tras cambiar el puerto hay que **reciclar la configuración cacheada y los
workers**, porque `config:cache` congela la plantilla y `queue:work` es un
proceso largo que no la vuelve a leer:

```bash
docker compose -f docker-compose.prod.yml exec backend php artisan config:cache
docker compose -f docker-compose.prod.yml restart queue-worker
```

### 56.4 Pruebas

`tests/Feature/Mailing/DynamicMailingTest.php` suma una novena prueba,
`test_el_transporte_de_sendgrid_relaya_por_el_puerto_alterno_2525`, que fija el
puerto en las dos capas donde puede regresar el defecto: el transporte que la
fábrica inyecta en `config('mail.mailers.dynamic-jobs')` y la plantilla
`config('mailing.providers.sendgrid')` de la que sale. Verifica además que el
cifrado sigue siendo `tls`, para dejar asentado que el puerto alterno cambia la
ruta y **nunca** el `STARTTLS`.

### Archivos Modificados
- `backend/config/mailing.php` — puerto de SendGrid 587 → 2525, con el bloqueo del proveedor documentado en el propio archivo
- `backend/app/Services/Mail/DynamicMailerFactory.php` — puerto de respaldo 587 → 2525 y nota de por qué no debe volver
- `backend/app/Jobs/SendConfiguredProcessMail.php` — nota de diagnóstico: ante un `Operation timed out`, revisar el puerto antes que al proveedor
- `backend/tests/Feature/Mailing/DynamicMailingTest.php` — guardia de regresión del puerto
- `.env.production.example` — `MAIL_PORT` 587 → 2525 con la advertencia del bloqueo
- `DEPLOY_DIGITALOCEAN.md` — íd. en el bloque de `.env` de producción, con nota operativa

---

## 57. DIAGNÓSTICO SÍNCRONO DE CORREO: VALIDAR CREDENCIALES ANTES DE GUARDARLAS [🟢 COMPLETADO Y OPERATIVO]

La sección 56 corrigió el puerto, pero dejó abierta la pregunta operativa: **¿cómo
sabe un administrador que su configuración funciona?** Hasta ahora, la única
forma de comprobarlo era esperar al cierre automático de las 21:00 y revisar
después `job_execution_logs` — un ciclo de retroalimentación de horas para un
error que se corrige en segundos. Peor: si la credencial estaba mal, el fallo
ocurría dentro del worker, donde el administrador no tiene visibilidad.

Esta sección agrega un botón **Probar Conexión** que responde esa pregunta en el
momento, contra el proveedor real, con las credenciales que el usuario acaba de
escribir y **sin necesidad de guardarlas**.

### 57.1 La decisión de diseño: síncrono aquí, asíncrono en producción

Es la única ruta de correo del sistema que llama a `send()` en lugar de
`queue()`, y la excepción es deliberada:

| | Correo de negocio (sección 48) | Prueba de conexión (esta sección) |
| :--- | :--- | :--- |
| Ruta | `SendConfiguredProcessMail` → cola Redis | Petición HTTP, en el mismo proceso |
| Método | `sendNow()` dentro del worker | `send()` dentro del controlador |
| Mailable | `ShouldQueue` | **NO** `ShouldQueue` |
| Qué significa un 200 | El mensaje quedó **encolado** | El mensaje quedó **entregado** |
| Ante un timeout | 3 reintentos con backoff, traza en `job_execution_logs` | La excepción viaja al navegador |

Encolar la prueba la volvería inútil: devolvería `200 OK` en cuanto Redis
aceptara el payload —informando que el correo se **encoló**, jamás que se
**envió**— y el fallo real aterrizaría minutos después en una bitácora de worker
que el administrador no puede abrir. La pregunta que hace el botón ("¿sirven
estas credenciales?") solo se puede responder si la excepción regresa dentro de
la misma petición.

Lo inverso también sigue siendo cierto: el cierre de caja **nunca** debe ser
síncrono, porque una latencia de SMTP no puede estirar la ventana de las 21:00 ni
convertir un cierre exitoso en un comando fallido.

### 57.2 Validación "on-the-fly": probar lo que aún no existe en la base

El endpoint recibe el payload del formulario, no lee la fila guardada. Para
armar el transporte con datos que todavía no se persisten se instancia un modelo
**en memoria** y se le entrega a la fábrica de siempre:

```php
$configuration = new EmailConfiguration([...]);   // sin ->save()
$mailerName = $factory->register($configuration); // Config::set(...) al vuelo
```

`DynamicMailerFactory` nunca pregunta si la fila existe: fusiona lo que recibe
sobre la plantilla del proveedor —**incluido el puerto 2525 de la sección 56**—
y lo inyecta en `config('mail.mailers.dynamic-{proceso}')`. Reimplementar esos
`Config::set()` dentro del controlador habría creado un **segundo** constructor
de transportes capaz de desviarse del real, que es justo el defecto que esta
herramienta existe para detectar: la prueba tiene que recorrer el mismo camino
que la producción o no prueba nada.

**Caso de la credencial guardada.** El formulario deja el campo de API Key vacío
al editar (la API nunca la devuelve al navegador). Si el endpoint la exigiera, el
botón sería inservible precisamente en las configuraciones que ya están en
producción. Por eso `api_key` es opcional y, cuando llega vacía, el controlador
recupera la credencial cifrada de `configuration_id`.

### 57.3 El error del proveedor se devuelve **textual**

```php
} catch (Throwable $e) {
    return response()->json([
        'status' => 'error',
        'message' => $e->getMessage(),   // verbatim, sin parafrasear
        'error_code' => 'ERR_MAIL_TEST_FAILED',
        'error_class' => class_basename($e),
    ], 400);
}
```

El texto de `TransportException` **es** el entregable de la herramienta: un
`Operation timed out` contra el 2525 señala al firewall del proveedor, mientras
que un `401 Unauthorized` en la misma ruta señala a la API Key. Un amable "no se
pudo conectar" borraría esa distinción y dejaría al administrador exactamente
donde estaba. La credencial nunca se registra en la bitácora ni viaja en la
respuesta; sí se registran proceso, proveedor, ruta y clase de la excepción.

### 57.4 La plantilla — `TestConnectionMail` + `mail.test-connection`

Mailable **sin** `ShouldQueue` (el único del sistema), sobre el layout
corporativo de la sección 41. Encabezado "Prueba de Conexión Exitosa" con
distintivo verde, y un bloque de diagnóstico con fecha y hora del envío
(resueltas al renderizar, así el administrador puede compararlas con el sello de
su bandeja y medir la latencia real), zona horaria, tipo de proceso probado y la
ruta SMTP efectivamente marcada (`host:puerto` + cifrado). Cuando el puerto es
2525 imprime además la nota del bloqueo anti-spam documentado en la sección 56.

Su llegada a la bandeja **es** la aserción: prueba credencial, identidad del
remitente y puerto de salida de una sola vez.

### 57.5 Endpoint

| Método | Ruta | Descripción |
| :--- | :--- | :--- |
| POST | /api/admin/email-configurations/test-connection | Envío de diagnóstico síncrono (`role:admin` + `throttle:10,1`) |

Cuota propia porque cada clic cuesta una conexión SMTP real contra el proveedor y
un correo en la bandeja de alguien. Respuesta exitosa: ruta usada, destinatarios
y `elapsed_ms`.

### 57.6 Frontend — botón "Probar Conexión"

En el pie del formulario de `EmailNotificationsPanel`, a la izquierda de
Cancelar / Guardar. Toma el estado vivo del formulario, no la fila guardada.

- `type="button"` explícito: dentro de un `<form>` el tipo por omisión es
  `submit`, y el botón habría **guardado** la configuración en lugar de probarla.
- Estado `loading` con spinner; Guardar, Cancelar y el cierre del diálogo quedan
  bloqueados mientras la petición síncrona está en vuelo.
- Guardas locales (remitente, destinatarios, credencial en alta) para no gastar
  un viaje al servidor en lo que el formulario ya sabe.
- Toast **verde** con la ruta y los milisegundos; toast **rojo** de 15 segundos
  con el string exacto de la API, tiempo suficiente para leerlo o copiarlo.

### 57.7 Pruebas

`tests/Feature/Mailing/MailConnectionDiagnosticTest.php` — 8 pruebas: el envío
ocurre dentro de la petición y **no** por la cola (`assertNothingQueued`); el
transporte sale por el 2525 sin escribir una sola fila (`assertDatabaseCount 0`)
y sin exponer la credencial; el mensaje del transporte se devuelve textual con
`400`; la credencial guardada se reutiliza cuando el formulario la deja vacía;
sin credencial y sin fila de respaldo responde `ERR_MAIL_TEST_NO_CREDENTIAL`; los
destinatarios inválidos se rechazan con `422`; un no-administrador recibe `403`;
y la plantilla imprime fecha, proceso y ruta de salida.

> **Nota de mantenimiento.** Al ejecutar la suite se encontró que
> `test_el_job_envia_a_los_destinatarios_y_con_el_asunto_de_la_base_de_datos`
> (sección 48.9) estaba **en rojo desde su creación** por dos supuestos
> incorrectos sobre el framework: `MailFake::sendNow()` archiva el Mailable como
> *enviado* aunque sea `ShouldQueue` (fija `shouldQueue: false`), así que
> `assertQueued` nunca podía pasar; y `hasFrom()` consulta primero `envelope()`,
> cuyo `isFrom()` desreferencia su propio `$from` sin comprobar `null` — fatal en
> cualquier Mailable cuyo Envelope declare solo el asunto. Corregida a
> `assertSent` + lectura directa de la propiedad `from`. La suite de correo queda
> **17/17 en verde**.

### Archivos Creados
- `backend/app/Mail/TestConnectionMail.php`
- `backend/resources/views/mail/test-connection.blade.php`
- `backend/app/Http/Requests/EmailConfiguration/TestEmailConnectionRequest.php`
- `backend/tests/Feature/Mailing/MailConnectionDiagnosticTest.php`

### Archivos Modificados
- `backend/app/Http/Controllers/Admin/EmailConfigurationController.php` — método `testConnection()` y respaldo de credencial guardada
- `backend/routes/api.php` — ruta con cuota propia dentro del grupo `role:admin`
- `backend/tests/Feature/Mailing/DynamicMailingTest.php` — aserción corregida (nota de mantenimiento)
- `frontend/src/components/settings/EmailNotificationsPanel.jsx` — botón, estado `testing` y toasts de diagnóstico

---

## 58. CONFIGURACIÓN DE CORREO SECUESTRADA: EL `.env` VUELVE A MANDAR CUANDO LA BASE NO TIENE DATOS [🟢 CORREGIDO]

`.env.production` declaraba `MAIL_HOST=smtp.resend.com` y el sistema seguía
marcando `127.0.0.1`. El archivo estaba bien: lo que estaba mal era que
**alguien lo sobrescribía en memoria** después de leerlo. Esta sección elimina
ese secuestro y fija la regla de precedencia que faltaba.

### 58.1 El diagnóstico: un `Config::set()` incondicional sobre valores que no existían

`DynamicMailerFactory::register()` ejecutaba **siempre** esta línea, sin
preguntarse si tenía algo real que inyectar:

```php
Config::set("mail.mailers.{$mailerName}", $this->transportFor($provider, $configuration));
```

Y `transportFor()` armaba el transporte a partir de la plantilla del proveedor
con literales de respaldo:

```php
'host' => $provider['host'] ?? '127.0.0.1',
'port' => $provider['port'] ?? 2525,
```

La plantilla del proveedor genérico, a su vez, traía el literal incorporado en
el propio `env()`:

```php
'smtp' => [
    'host' => env('DYNAMIC_SMTP_HOST', '127.0.0.1'),   // ← el secuestro
    'port' => (int) env('DYNAMIC_SMTP_PORT', 587),
],
```

**Ningún despliegue define `DYNAMIC_SMTP_HOST`.** Nunca se pensó como variable
obligatoria: era el gancho para un relay alterno. Pero al llevar el literal
dentro del segundo argumento, la ausencia de la variable no producía "sin
configurar", producía `127.0.0.1` — un valor tan válido para el código como
cualquier otro, que bajaba por `transportFor()` hasta `Config::set()` y
**reemplazaba** el `MAIL_HOST` correcto en `config('mail.mailers.*')`.

De ahí el síntoma exacto que se reportó:

| Capa | Qué contenía |
| :--- | :--- |
| `.env.production` | `MAIL_HOST=smtp.resend.com` ✅ |
| `config/mail.php` | `smtp.resend.com` ✅ (leído correctamente) |
| `config/mailing.php` | `127.0.0.1` (literal, sin variable que lo desplazara) |
| `config('mail.mailers.dynamic-jobs')` tras `register()` | **`127.0.0.1`** ❌ |

El `.env` se leía siempre y siempre se descartaba, un microsegundo después. Por
eso revisar el archivo, el `docker-compose` o el contenedor no llevaba a ningún
lado: la evidencia no estaba en disco, estaba en memoria y solo existía dentro
del worker.

**La lección de diseño.** `Config::set()` no es "configurar", es **sobrescribir
lo que el servidor ya decidió**. Ejecutarlo sin condiciones convierte cada valor
que la fábrica no pudo resolver en un pisotón silencioso sobre uno que sí era
correcto. Un valor ausente en la base de datos no es un valor: es la instrucción
de no tocar nada.

### 58.2 La regla: prioridad campo por campo

```
    base de datos   >   config/mailing.php   >   config/mail.php (.env.production)
```

Se aplica **por campo**, no por bloque: que la fila aporte la credencial no le
da derecho a decidir el host. Cada capa solo sobrescribe lo que realmente
declara, y lo que no declara se hereda intacto de la capa de abajo.

| Campo | Lo aporta | Notas |
| :--- | :--- | :--- |
| `password` | La fila (`api_key`, cifrada) | Manda sobre `MAIL_PASSWORD` siempre que exista |
| `host` / `port` / `encryption` / `timeout` | La plantilla del proveedor | Solo si la plantilla los declara |
| `username` | Plantilla → `MAIL_USERNAME` → remitente | El remitente es el **último** recurso, no el primero |
| Remitente, asunto, destinatarios | La fila | Se aplican al Mailable, nunca al transporte |
| Todo lo demás | `config/mail.php` | La base que produce el `.env` |

Con proveedor **SendGrid**, la plantilla sí fija host y puerto: elegir SendGrid
en el panel es pedir `smtp.sendgrid.net:2525` explícitamente (sección 56), no
"lo que diga `MAIL_HOST`".

Con proveedor **SMTP Genérico**, la plantilla ya no declara nada y el envío sale
por el servidor de correo del `.env`. Las `DYNAMIC_SMTP_*` siguen disponibles
para quien necesite desviar el correo del panel a otro relay, y se leen **solo
si están definidas**.

### 58.3 La corrección: el `Config::set()` es ahora condicional

`register()` calcula primero qué está sobrescribiendo de verdad. Si la
respuesta es "nada", no inyecta ningún transporte y devuelve el nombre del
mailer que declara el `.env`:

```php
$native = $this->nativeTransport($provider);
$overrides = $this->overridesFor($provider, $configuration, $native);

if ($overrides === []) {
    return $this->nativeMailerName($provider);   // sin Config::set()
}

Config::set("mail.mailers.{$mailerName}", $this->transportFor($provider, $native, $overrides));
```

Tres detalles que sostienen la regla:

- **`transport` no cuenta como sobrescritura.** Todas las plantillas lo
  declaran (`'transport' => 'smtp'`), así que incluirlo en la cuenta habría
  hecho `$overrides` siempre distinto de vacío y la rama de herencia
  inalcanzable — el secuestro de vuelta, con otra ropa.
- **El `username` se resuelve al final y solo si ya hay algo más que
  sobrescribir.** Por sí solo no autentica nada, y añadirlo bastaría para
  forzar un `Config::set()` cuyo único efecto sería cambiar el `MAIL_USERNAME`
  del servidor por la dirección del remitente.
- **Con host propio se descartan `url` y `scheme` heredados.** Symfony arma el
  DSN a partir de ellos **antes** que de host y puerto: heredarlos dejaría que
  un `MAIL_URL` del `.env` desviara el tráfico de SendGrid mientras todos los
  valores impresos seguirían diciendo `smtp.sendgrid.net`.

`config/mailing.php` quedó sin literales de endpoint en el proveedor genérico y
con una clave nueva, `inherits`, que nombra el mailer de `config/mail.php` que
cada proveedor completa. El propio archivo lleva escrito por qué un `null` ahí
no significa "sin servidor" sino "lo que diga el `.env`", porque rellenarlo
parece una mejora en cualquier diff.

### 58.4 `php artisan app:debug-mail-config`

El incidente duró lo que duró porque no había forma de preguntarle al framework
qué estaba usando. El comando responde eso, y se ejecuta dentro del contenedor
de la API o del worker:

```bash
docker compose -f docker-compose.prod.yml exec api php artisan app:debug-mail-config
```

Imprime cuatro secciones, en el orden en que las capas se aplican:

1. **Entorno de ejecución** — si la configuración está cacheada y, por lo
   tanto, si el `.env` se leyó siquiera en este arranque.
2. **Mailer nativo** — `config('mail.mailers.smtp.host')` y
   `config('mail.mailers.smtp.port')` impresos textualmente, más el bloque
   completo y `config('mail.default')`.
3. **Variables de entorno** — las `MAIL_*` y `DYNAMIC_SMTP_*` crudas,
   contrastadas contra la configuración cargada; si `MAIL_HOST` y
   `config(...smtp.host)` no coinciden, lo dice con todas sus letras.
4. **Filas almacenadas** — qué mailer resolvería cada proceso y si el
   transporte sale de la base de datos o del `.env`.

Es de solo lectura: no envía nada y no escribe nada. Las contraseñas salen
enmascaradas a los últimos cuatro caracteres, porque esta salida termina pegada
en tickets y conversaciones.

**El hallazgo que suele aparecer aquí.** Con `config:cache` activo (lo ejecuta
`docker-entrypoint.prod.sh` en cada arranque), Laravel **no lee el `.env`**:
trabaja contra `bootstrap/cache/config.php`. Editar el archivo no cambia nada
hasta reconstruir el caché, y las lecturas de `env()` vuelven vacías — que es
distinto de que la variable no exista. El comando avisa de esa condición antes
de que se interprete como una segunda falla:

```bash
php artisan config:cache && php artisan queue:restart
```

`queue:restart` no es opcional: los workers son procesos largos y conservan en
memoria la configuración con la que arrancaron.

### 58.5 Pruebas

`tests/Feature/Mailing/MailConfigurationPrecedenceTest.php` — 7 pruebas, sin
tocar la base de datos (el modelo viaja en memoria, como en la sección 57):

- Sin datos en la fila y sin plantilla, `register()` devuelve el mailer nativo,
  **no** crea `mail.mailers.dynamic-jobs` y deja el bloque `smtp` intacto.
- Una credencial guardada inyecta transporte pero hereda host y puerto del
  `.env`: la aserción explícita es que el host **no** es `127.0.0.1`.
- Una plantilla con `DYNAMIC_SMTP_HOST` gana sobre el `.env`, con el puerto
  convertido a entero.
- SendGrid conserva `smtp.sendgrid.net:2525` y no hereda el host del `.env`.
- Un `MAIL_URL` en el `.env` no desvía al proveedor elegido.
- Guardia sobre el archivo: `config/mailing.php` no vuelve a declarar host ni
  puerto literales en el proveedor genérico.
- El comando de diagnóstico imprime host y puerto vigentes y **no** filtra la
  contraseña.

Las nueve pruebas de `DynamicMailingTest.php` siguen aplicando sin cambios: el
transporte de SendGrid conserva credenciales, puerto 2525 y cifrado `tls`.

### Archivos Creados
- `backend/app/Console/Commands/DebugMailConfig.php` — comando `app:debug-mail-config`
- `backend/tests/Feature/Mailing/MailConfigurationPrecedenceTest.php`

### Archivos Modificados
- `backend/app/Services/Mail/DynamicMailerFactory.php` — `Config::set()` condicional, herencia del mailer del `.env` y resolución de `username` por prioridad
- `backend/config/mailing.php` — se elimina el literal `127.0.0.1` del proveedor genérico, clave `inherits` y orden de precedencia documentado en el archivo
- `backend/app/Jobs/SendConfiguredProcessMail.php` — se registra el mailer resuelto en la bitácora de envío y nota de diagnóstico
- `.env.production.example` — orden de prioridad, comprobación con `app:debug-mail-config` y bloque opcional `DYNAMIC_SMTP_*`

---

## 59. PATRÓN STRATEGY EN EL SUBSISTEMA DE CORREO: RESEND POR HTTPS/443, TIMEOUTS ACOTADOS Y TRAZA REAL DE ERRORES [🟢 COMPLETADO Y OPERATIVO]

El botón **Probar Conexión** se congelaba 60 segundos y volvía sin decir nada.
No era lentitud del proveedor: era un puerto de salida bloqueado por el VPS más
un cliente sin límite de tiempo. Esta sección reemplaza el camino único de envío
por un **Patrón Strategy**, añade **Resend por API HTTP (puerto 443)** como
proveedor de primera clase y convierte cualquier fallo en un **HTTP 422 con el
error técnico real** en el cuerpo.

### 59.1 El diagnóstico: por qué se congelaba exactamente 60 segundos

Un puerto de submission bloqueado en un VPS (DigitalOcean, AWS, GCP, Azure)
**no rechaza: descarta**. Los paquetes se tiran en silencio, así que el
handshake TCP nunca se completa y el socket se queda esperando hasta agotar su
propio tiempo. Ese tiempo, cuando nadie lo declara, sale del `php.ini`:

```
default_socket_timeout = 60
```

Symfony lo confirma en su propio código: `SocketStream::getTimeout()` devuelve
`$this->timeout ?? (float) ini_get('default_socket_timeout')`, y ese valor es el
cuarto argumento de `stream_socket_client()` — el límite del intento de
conexión. Nadie declaraba `timeout` en el transporte, luego el número efectivo
era 60, la petición HTTP se quedaba colgada, el navegador se rendía primero y el
administrador se quedaba sin mensaje de error.

A eso se sumaba el problema de fondo: **los tres proveedores compartían un solo
camino de envío**. Añadir uno significaba editar el método del que dependían los
demás, y todos heredaban la misma dependencia de un puerto SMTP abierto.

### 59.2 La arquitectura: una clase por proveedor, elegida por configuración

```
  EmailConfiguration.provider  (columna en base de datos)
              │
              ▼
      MailStrategyFactory ──lee──► config('mailing.providers.*.strategy')
              │
              ├── SmtpMailStrategy      → Symfony ESMTP (587 / 465 / 2525)
              ├── SendGridMailStrategy  → extiende la anterior: smtp.sendgrid.net:2525, usuario "apikey"
              └── ResendMailStrategy    → POST https://api.resend.com/emails (HTTPS/443)
```

El contrato es `App\Services\Mail\Contracts\MailStrategyInterface`:

| Método | Devuelve | Para qué existe |
| :--- | :--- | :--- |
| `provider()` | `string` | Clave del proveedor que atiende |
| `send(Mailable $mail, array $config)` | `bool` | Entrega real desde el worker; **sí lanza** excepción para que la cola reintente |
| `testConnection(array $config)` | `array` | Diagnóstico síncrono; **nunca lanza**: el fallo es un valor de retorno |
| `describeTransport(array $config)` | `array` | Ruta resuelta **sin marcar**, de solo lectura (la usa `app:debug-mail-config`) |

Tres decisiones que sostienen el diseño:

1. **Las estrategias reciben un `array`, no el modelo.** La prueba de conexión
   valida credenciales que todavía no existen en la base, así que ninguna
   estrategia puede asumir que hay fila. `EmailConfiguration::toStrategyConfig()`
   produce ese arreglo (proceso, proveedor, credencial, remitente, asunto y
   destinatarios ya depurados).
2. **`testConnection()` no lanza, retorna.** Es lo que permite al controlador
   responder 422 con la causa en el cuerpo en lugar de un 500 sin ella.
3. **El mapa vive en `config/mailing.php`.** Agregar un proveedor es un bloque de
   configuración más una clase: ni el job, ni el controlador, ni la fábrica se
   tocan. El catálogo del panel se cruza contra las estrategias realmente
   registradas, así que el desplegable **no puede** ofrecer un proveedor que el
   sistema no sepa enviar.

`SendGridMailStrategy` **extiende** a `SmtpMailStrategy` en lugar de duplicarla:
el protocolo es el mismo, lo específico es la política (puerto alterno 2525,
usuario literal `apikey`, avisos cuando la credencial no empieza con `SG.`). El
día que SendGrid deba migrar a su API HTTP, el cambio queda confinado a esa
clase.

`DynamicMailerFactory` sigue siendo el constructor de transportes **de la
familia SMTP** y ahora expone `preview()`, la mitad de solo lectura que resuelve
el mismo transporte sin ejecutar `Config::set()`. `register()` se implementó
encima de `preview()`, de modo que la ruta que se reporta y la que se marca no
pueden divergir. Además rechaza explícitamente a un proveedor `driver => http`:
Resend no declara host ni puerto, y un merge silencioso lo habría interpretado
como "nada que sobrescribir", enviando su correo por el relay SMTP del `.env`
con la credencial equivocada.

### 59.3 Resend: integración nativa por API HTTP, sin abrir un solo puerto SMTP

`ResendMailStrategy` renderiza el Mailable a HTML y lo publica como JSON:

```php
Http::withToken($apiKey)
    ->acceptJson()->asJson()
    ->connectTimeout(config('mailing.timeouts.connect'))  // handshake TCP/TLS
    ->timeout($timeout)                                   // intercambio completo
    ->post('https://api.resend.com/emails', [
        'from' => 'Cronos POS <no-reply@…>',
        'to' => [...],
        'subject' => …,
        'html' => $mail->render(),
    ]);
```

- **Puerto 443, el mismo que ya usa toda la aplicación.** Ningún proveedor de
  nube lo filtra, así que elegir Resend **elimina** la clase entera de fallos por
  firewall de salida en lugar de esquivarla.
- **El cuerpo es idéntico al que habría entregado SMTP**: se renderiza el mismo
  Mailable, así que cambiar de proveedor no cambia lo que ve el destinatario.
- **El remitente y el asunto se leen del Mailable** (`->from()` / `->subject()`
  que aplica el job) antes de caer al asunto guardado, de modo que un correo por
  Resend conserva la redacción configurada en la base de datos.
- **Un 4xx no es una excepción de PHP**, es un objeto `Response`. Se normaliza a
  `App\Exceptions\Mail\MailTransportException`, que extrae el mensaje del cuerpo
  (`message`, `error.message`, o el cuerpo crudo), el `statusCode` y el nombre
  del error del proveedor. Así "API key inválida" y "socket agotado" viajan por
  la misma maquinaria de diagnóstico.

### 59.4 Mitigación de timeouts: dos cotas en SMTP, dos en HTTP

`config('mailing.timeouts')` es el presupuesto de red de todo el módulo:

| Fase | Segundos (defecto) | Variable | Aplica a |
| :--- | :--- | :--- | :--- |
| `test` | 8 | `MAIL_TEST_TIMEOUT` | Prueba síncrona: hay un humano mirando un spinner |
| `send` | 30 | `MAIL_SEND_TIMEOUT` | Envío desde el worker: nadie espera y hay reintentos con backoff |
| `connect` | 5 | `MAIL_CONNECT_TIMEOUT` | Handshake TCP/TLS de las estrategias HTTP |

**En SMTP se aplican dos cotas, porque cada una tapa lo que la otra no alcanza:**

1. `timeout` en la configuración del mailer. Laravel lo reenvía a
   `SocketStream::setTimeout()`, que es lo que usa el intento de conexión. Se
   **acota, no se impone**: si el despliegue ya configuró 3 segundos, sabe algo
   de su red que este valor por omisión no sabe, y se respeta.
2. `default_socket_timeout` alrededor del envío, restaurado en un bloque
   `finally`. Cubre lo que la primera no alcanza: un transporte armado desde un
   DSN `MAIL_URL` (donde `isset($config['timeout'])` nunca se cumple), una
   negociación TLS que se atasca después de abrir el socket, y cualquier stream
   que la librería abra por su cuenta. Se restaura siempre porque el valor es
   global al proceso y PHP-FPM reutiliza el worker en la siguiente petición.

**En HTTP** el par es `connect_timeout` + `timeout` de cURL. El primero es el
que dispara contra un puerto filtrado, antes de intercambiar un solo byte.

Resultado medible: el peor caso pasó de **60 segundos y silencio** a **≤ 8
segundos y un mensaje accionable**.

### 59.5 Traza de errores reales: el 422 que reemplazó al cuelgue

`POST /api/admin/email-configurations/test-connection` ya no puede terminar en
un 500 ni en un timeout del navegador. La estrategia captura, el controlador
traduce:

```jsonc
// HTTP 422
{
  "status": "error",
  "message": "Resend respondio HTTP 403: The cronos.pos domain is not verified",
  "error":   "Resend respondio HTTP 403: The cronos.pos domain is not verified",
  "error_code": "ERR_MAIL_TEST_FAILED",
  "error_class": "MailTransportException",
  "data": {
    "provider": "resend",
    "strategy": "ResendMailStrategy",
    "transport": { "channel": "https", "host": "api.resend.com", "port": 443, "timeout": 8 },
    "elapsed_ms": 412,
    "hints": ["Resend responde 403 cuando el dominio del remitente no esta verificado…"],
    "error": {
      "message": "…",            // verbatim, sin parafrasear
      "class": "MailTransportException",
      "status_code": 403,
      "provider_code": "validation_error",
      "trace": ["ResendMailStrategy.php:212 (origen)", "…"],
      "previous": { "message": "…", "class": "…" }
    }
  }
}
```

**422 y no 500** es deliberado: la petición estaba bien formada y la aplicación
hizo exactamente lo que se le pidió; lo que falló es la configuración bajo
prueba, que es un hecho sobre el payload. Además mantiene una API Key mal
tecleada fuera del alerting 5xx de la plataforma. *(Cambio de contrato respecto
a la sección 57, que respondía 400.)*

Qué **nunca** viaja: la credencial. `MailDiagnostic` construye la traza a partir
de archivo, línea, clase y método — jamás de los argumentos, que es donde vive
la API Key — y el resumen del transporte se arma con una lista blanca de claves
(`transport`, `host`, `port`, `encryption`, `timeout`), así que `password` no
puede colarse ni en la respuesta ni en la bitácora. Hay una prueba que serializa
el diagnóstico completo y afirma que la clave no aparece.

Los `hints` son la novedad práctica: no son errores, son las tres confusiones
caras del módulo — el puerto 587 bloqueado en VPS, una contraseña donde SendGrid
espera una `SG.`, un dominio sin verificar en Resend.

### 59.6 Catálogo, panel y comando de diagnóstico

- **Base de datos**: `email_configurations.provider` es un `string(40)` sin
  restricción de valores, así que `'resend'` entra sin migración. El catálogo
  formal vive en `EmailConfiguration::PROVIDERS`, ahora con constantes
  (`PROVIDER_SMTP`, `PROVIDER_SENDGRID`, `PROVIDER_RESEND`) y etiquetas que
  nombran el puerto de salida: *SendGrid (SMTP 2525)*, *Resend (API HTTPS 443)*,
  *SMTP Generico*.
- **`GET /catalogs`** cruza ese catálogo contra las estrategias registradas y
  añade `channel` (`smtp` | `https`) a cada opción: elegir proveedor es, en la
  práctica, elegir qué puerto tiene que dejar pasar el firewall.
- **Panel**: el desplegable se alimenta del catálogo (Resend aparece solo), el
  placeholder y la nota de ayuda cambian por proveedor (`SG.…` / `re_…` /
  contraseña del buzón), y el toast de error muestra clase de excepción, ruta
  (`HTTPS api.resend.com:443`), milisegundos, el mensaje verbatim y los avisos.
- **`php artisan app:debug-mail-config`** imprime ahora la estrategia, la ruta
  (`HTTPS → api.resend.com:443`) y el presupuesto de timeouts, y lo hace sin
  mutar nada gracias a `describeTransport()` — un comando de diagnóstico que
  modifica lo que describe es un comando que miente.

### 59.7 Pruebas

`tests/Feature/Mailing/MailStrategyTest.php` — 15 pruebas **sin PostgreSQL**
(las filas viajan en memoria y la única tabla que toca el Mailable de
diagnóstico se crea en SQLite):

- La fábrica devuelve una clase distinta por proveedor y rechaza uno sin
  estrategia (`ERR_MAIL_STRATEGY_UNSUPPORTED`).
- El catálogo visible nunca excede las estrategias registradas.
- Resend sale por `https://api.resend.com/emails` con `Bearer`, remitente
  `Nombre <correo>` y **sin** crear `mail.mailers.dynamic-jobs`.
- Un 401 de la API vuelve como valor de retorno con mensaje, `status_code`,
  `provider_code` y traza — sin lanzar.
- Un `ConnectionException` de cURL ("Operation timed out after 5001 ms") viaja
  verbatim y el diagnóstico tarda mucho menos de 60 segundos.
- Sin credencial o sin destinatarios, la petición **no** sale a la red.
- El diagnóstico serializado no contiene la API Key.
- La estrategia SMTP baja el `timeout` del transporte al presupuesto de prueba y
  **no** sube uno ya configurado más bajo.
- `describeTransport()` no muta `config('mail.mailers')`.
- `DynamicMailerFactory` rechaza un proveedor HTTP (`ERR_MAIL_PROVIDER_NOT_SMTP`).

`MailConnectionDiagnosticTest.php` — el fallo de transporte ahora se afirma
contra **422** con `error`, `data.error.trace` y la ruta dialogada; se añaden la
prueba de Resend por HTTPS/443, la del 403 con su hint y la del catálogo con los
tres proveedores. `DynamicMailingTest.php` incorpora la entrega del job por
Resend: `Mail::assertNothingOutgoing()` más la aserción de que el HTML, el
asunto y el remitente de la base de datos viajaron en el JSON.

**43 pruebas de mailing en verde** (`--filter Mailing`).

### Archivos Creados
- `backend/app/Services/Mail/Contracts/MailStrategyInterface.php` — contrato del patrón
- `backend/app/Services/Mail/Strategies/SmtpMailStrategy.php` — SMTP genérico y base de la familia
- `backend/app/Services/Mail/Strategies/SendGridMailStrategy.php` — relay 2525, usuario `apikey`
- `backend/app/Services/Mail/Strategies/ResendMailStrategy.php` — API HTTP nativa, HTTPS/443
- `backend/app/Services/Mail/MailStrategyFactory.php` — resolución dinámica por configuración
- `backend/app/Services/Mail/Support/MailDiagnostic.php` — resultado normalizado, traza sin credenciales
- `backend/app/Exceptions/Mail/MailTransportException.php` — normaliza un 4xx HTTP a excepción
- `backend/tests/Feature/Mailing/MailStrategyTest.php`

### Archivos Modificados
- `backend/config/mailing.php` — presupuesto `timeouts`, claves `strategy` / `driver` y proveedor `resend`
- `backend/app/Models/EmailConfiguration.php` — constantes de proveedor, `resend` en el catálogo y `toStrategyConfig()`
- `backend/app/Services/Mail/DynamicMailerFactory.php` — `preview()` de solo lectura, `register()` construido encima y rechazo de proveedores HTTP
- `backend/app/Http/Controllers/Admin/EmailConfigurationController.php` — despacho por estrategia, respuesta 422 con traza y catálogo con `channel`
- `backend/app/Jobs/SendConfiguredProcessMail.php` — entrega por estrategia y bitácora con la ruta resuelta
- `backend/app/Console/Commands/DebugMailConfig.php` — sección de timeouts y tabla con estrategia y ruta, sin efectos secundarios
- `frontend/src/components/settings/EmailNotificationsPanel.jsx` — ayuda por proveedor y toast de error con clase, ruta, tiempo y avisos
- `backend/tests/Feature/Mailing/MailConnectionDiagnosticTest.php`, `DynamicMailingTest.php`

---

## 60. UNIFICACIÓN DEL ENVÍO MANUAL DE CIERRES DE CAJA CON EL MÓDULO CENTRALIZADO DE CORREO [🟢 COMPLETADO Y OPERATIVO]

El botón **Enviar por Correo** del histórico de Cierres de Caja era la última
isla del subsistema de correo: armaba su propio mensaje con la fachada `Mail`,
salía por el bloque `MAIL_*` del `.env` y no sabía nada de
`email_configurations`. En la práctica el mismo reporte podía llegar con dos
remitentes distintos según quién lo enviara — el cierre automático con la
identidad oficial, el envío manual con lo que el `.env` tuviera puesto — y
fallaba por un camino que ni el diagnóstico síncrono ni la telemetría del
worker podían ver. Esta sección lo integra al proceso **`sales` / Ventas y
Cierres** y le añade dos comportamientos que la operación pedía: precarga de
destinatarios y sobrescritura puntual.

### 60.1 Validación previa: la modal no abre sobre un buzón que no puede enviar

El clic ya no abre la modal directamente. Primero consulta
`GET /api/cash-registers/closings/email-configuration`, que resuelve la
configuración activa del proceso `sales`:

- **Sin fila activa (o con la fila desactivada)** ⇒ **HTTP 422** con
  `code: ERR_MAIL_CONFIG_MISSING` y el mensaje *"No existe una configuración de
  correo activa para este proceso. Por favor, da de alta la configuración en el
  panel de Ajustes."* El frontend lo intercepta y pinta un **toast de
  advertencia** (no de error) que nombra dónde se resuelve; la modal no llega a
  abrirse.
- **Con fila activa** ⇒ 200 con el proceso, el remitente y los destinatarios ya
  depurados.

**422 y no 500**, igual que el diagnóstico de conexión de la sección 59.5: la
petición estaba bien formada y la aplicación hizo lo que se le pidió; lo que
falta es una fila que un administrador tiene que crear. Mantenerlo fuera de la
banda 5xx significa que una instalación que nunca configuró correo no despierta
al alerting.

La credencial **no** viaja en esa respuesta: el endpoint expone únicamente el
enrutamiento que un operador puede ver y editar para un envío. Hay una prueba
que afirma que la API Key no aparece en el cuerpo.

El mismo requisito se vuelve a exigir en el `POST /closings/send-email`. No es
redundancia defensiva por gusto: la precarga y el envío son dos peticiones
distintas y la configuración puede desactivarse entre ambas. Encolar un reporte
financiero hacia la nada y responder 200 es peor que negarse.

`App\Exceptions\Mail\MailConfigurationMissingException` centraliza esa negativa
—código, mensaje y `render()` a 422— para que el texto sea idéntico en cualquier
punto donde se surta, y `EmailConfiguration::requireActiveFor()` es la variante
de `activeFor()` para los llamadores que no pueden continuar sin fila.

**Por qué el job sigue callado y el controlador no.** `SendConfiguredProcessMail`
termina en silencio cuando no hay configuración: nadie mira un cierre automático
de las 21:00 y "las notificaciones están apagadas" es un estado válido ahí. Un
humano que acaba de pulsar un botón sí está mirando, así que la misma condición
tiene que volver dentro de la petición en lugar de morir en una bitácora del
worker que el operador no puede abrir.

### 60.2 Precarga inteligente de destinatarios

Con configuración activa, la modal abre con el `Chips` de destinatarios ya
poblado con `target_emails` de la fila (depurados por `deliverableEmails()`:
recortados, sin duplicados y sin direcciones inválidas). El encabezado de la
modal muestra qué configuración se está usando y con qué remitente sale
(`no-reply@pos-app.tech` en producción), de modo que el operador ve el buzón
oficial antes de enviar, no después.

### 60.3 Modo ad-hoc: sobrescribir sin tocar lo guardado

El operador puede borrar los correos precargados y escribir otros — uno o
varios — para mandar el reporte a un involucrado puntual o a un auditor externo.
En cuanto la lista deja de coincidir con la configurada, la modal se marca con
la etiqueta **ENVÍO PUNTUAL** y ofrece *"Restaurar los configurados"*.

La sobrescritura es **por mensaje y nunca escribe en la base**:
`SendConfiguredProcessMail` acepta un `$recipientsOverride` que reemplaza
**solo el destino**. Proveedor, credencial, remitente y asunto siguen saliendo
de la fila, así que un reporte ad-hoc es indistinguible de uno programado para
quien lo recibe. La bitácora del envío marca `ad_hoc: true|false`, que es lo que
permite auditar después a dónde terminó yendo un reporte financiero.

Dos decisiones sobre esa lista:

1. **La omisión y la lista vacía no son lo mismo que una lista puntual.** El
   frontend manda `emails` **solo** cuando el operador los cambió; sin ese
   campo, el job resuelve los destinatarios guardados dentro del worker y honra
   un correo agregado en Ajustes mientras el mensaje esperaba en Redis. Una
   lista ad-hoc, en cambio, viaja congelada con el job: es una decisión que el
   operador tomó sobre *ese* reporte.
2. **Una lista puntual sin correos válidos no cae en la lista configurada.**
   Ensanchar en silencio un envío puntual a toda la distribución de finanzas
   sería la peor recuperación posible ante un dedazo, así que el job no envía
   nada y deja un `Log::warning`.

### 60.4 Enrutamiento por el patrón Strategy

El envío manual dejó de usar `Mail::to()->queue()`. Ahora despacha
`SendConfiguredProcessMail` — el mismo job del cierre automático — que pide su
estrategia a `MailStrategyFactory` según `email_configurations.provider`. Con
`resend` el reporte sale por `https://api.resend.com/emails` (HTTPS/443) con la
credencial y el remitente oficial de la fila, sin abrir un solo socket SMTP.
Cambiar el proveedor del envío manual vuelve a ser lo que ya era para el resto
del sistema: una columna en la base de datos.

`CashRegisterClosingMail` se alineó al contrato del job: su `envelope()` ahora
devuelve `$this->subject ?: self::DEFAULT_SUBJECT`. La hidratación del Envelope
corre *después* de que el job aplica el asunto configurado, y sin ese cambio el
asunto quemado en la clase habría pisado al de la base — el mismo detalle
documentado en la sección 48.4 para el reporte del cierre automático.

### 60.5 Contrato de la API

| Método | Ruta | Descripción |
| :--- | :--- | :--- |
| GET | /api/cash-registers/closings/email-configuration | Precarga: destinatarios y remitente del proceso `sales`, o 422 si no hay configuración activa |
| POST | /api/cash-registers/closings/send-email | Encola el reporte. `emails` es **opcional**: ausente ⇒ destinatarios configurados; presente ⇒ sobrescritura ad-hoc (máx. 20) |

`SendCashRegisterClosingEmailRequest` pasó `emails` de `required` a `nullable`:
omitirlo dejó de ser un error de validación y significa "manda a quien diga la
configuración", que es el comportamiento por defecto de la modal.

### 60.6 Pruebas

`tests/Feature/Mailing/ManualCashClosingMailTest.php` — 10 pruebas: la precarga
responde 422 sin configuración y también con la fila desactivada; devuelve los
destinatarios configurados sin filtrar la API Key; el envío se rechaza sin
configuración y no encola nada; un envío por defecto encola **sin** override
(`recipientsOverride === null`); una lista escrita a mano viaja como override y
`target_emails` sobrevive intacta en la base; el job entrega al destinatario
puntual con el remitente oficial y el asunto de la fila; una lista puntual sin
correos válidos no envía nada ni cae en la lista configurada; una configuración
activa sin destinatarios se rechaza antes de encolar; y un correo mal formado se
detiene en validación.

**53 pruebas de mailing en verde** (`--filter Mailing`).

### Archivos Creados
- `backend/app/Exceptions/Mail/MailConfigurationMissingException.php` — negativa 422 con `ERR_MAIL_CONFIG_MISSING`
- `backend/tests/Feature/Mailing/ManualCashClosingMailTest.php`

### Archivos Modificados
- `backend/app/Http/Controllers/Finance/CashRegisterClosingController.php` — endpoint de precarga y envío por el módulo centralizado
- `backend/app/Http/Requests/CashRegisterClosing/SendCashRegisterClosingEmailRequest.php` — `emails` opcional y `adHocRecipients()`
- `backend/app/Jobs/SendConfiguredProcessMail.php` — `$recipientsOverride` y bitácora con `ad_hoc`
- `backend/app/Mail/CashRegisterClosingMail.php` — respeta el asunto configurado
- `backend/app/Models/EmailConfiguration.php` — constantes `PROCESS_SALES` / `PROCESS_INVENTORY` y `requireActiveFor()`
- `backend/routes/api.php` — ruta de precarga del envío manual
- `frontend/src/pages/admin/CashRegisterClosingsPage.jsx` — validación previa, precarga, etiqueta de envío puntual y restauración de configurados

## 61. REFACTORIZACIÓN RESPONSIVA DEL CORREO DE CIERRES DE CAJA [🟢 COMPLETADO Y OPERATIVO]

El reporte que sale por el botón **Enviar por Correo** (sección 60) llegaba
correcto en escritorio pero se rompía en la app de Gmail de Android/iOS, que es
donde el encargado lo abre en la práctica. Dos defectos concretos:

1. Las tres tarjetas de métricas —**Total Cierres**, **Con Faltante**, **Con
   Sobrante**— seguían peleándose por una sola fila de 320 px y quedaban
   ilegibles.
2. La tabla **Detalle de Cierres** se desbordaba horizontalmente y recortaba
   justo las columnas que dan sentido al reporte: **Declarado** y
   **Diferencia**.

La corrección vive completa en `backend/resources/views/mail/cash-register-closing.blade.php`.

### 61.1 Por qué el maquetado anterior no podía funcionar

La rejilla de métricas usaba `div`s con `display: table` / `display: table-cell`
y un `.summary-cell + .summary-cell { margin-left: 8px }` que **nunca se
aplicó**: los márgenes no existen en una celda de tabla. El ancho fijo de
`33.3%` no tenía ningún punto de ruptura, así que en 320 px cada tarjeta
recibía ~100 px para un número de 22 px más una etiqueta en mayúsculas.

La tabla de detalle no tenía contenedor ni `word-break`, y con seis columnas de
contenido monetario el motor de renderizado la ensanchaba más allá del viewport.

### 61.2 Media query y estilos base

Se añadió un bloque `<style>` completo en el `<head>` con un único punto de
ruptura, `@media only screen and (max-width: 600px)`, más tres piezas de
higiene que faltaban:

- `<meta name="viewport" content="width=device-width, initial-scale=1">` — sin
  esto ningún media query se evalúa en móvil.
- `-webkit-text-size-adjust: 100%` en el `body`, para que iOS Mail y Gmail iOS
  no reescalen la tipografía por su cuenta.
- `background-color: #4f46e5` **antes** del `linear-gradient` del encabezado: en
  los clientes que descartan gradientes el texto blanco seguía cayendo sobre
  blanco.

### 61.3 Tarjetas de métricas: apiladas al 100 %

Los `div`s se sustituyeron por una `<table role="presentation">` real con tres
celdas al 32 % y dos celdas separadoras (`.summary-gutter`) al 2 % — el
espaciado que el `margin-left` prometía y no entregaba. En móvil, la tabla, el
`tbody`, el `tr` y las celdas pasan a `display: block !important; width: 100%
!important; box-sizing: border-box !important;`, las separadoras desaparecen y
cada tarjeta gana `margin-bottom: 10px`. El número sube a 26 px y la etiqueta a
11 px, porque una vez que hay ancho de sobra no hay razón para conservar la
tipografía comprimida.

### 61.4 Tabla de detalle: tarjetas apiladas con respaldo de scroll

Se implementó la **Opción B (tarjetas apiladas)** como comportamiento principal
y la **Opción A (scroll horizontal)** como red de seguridad. No es redundancia:
la app de Gmail elimina el `<style>` del `<head>` cuando la cuenta configurada
**no** es de Google, y en ese escenario el media query nunca llega. El envoltorio
`.table-scroll` (`overflow-x: auto; -webkit-overflow-scrolling: touch;
max-width: 100%`) garantiza que en ese cliente la tabla se pueda desplazar en
lugar de recortarse; cuando el media query sí se aplica, ese mismo contenedor
vuelve a `overflow-x: visible` porque ya no hay nada que desbordar.

El apilado funciona así: `<thead class="detail-head">` se oculta, cada
`<tr class="detail-row">` se vuelve una tarjeta con borde y radio, y cada
`<td class="detail-cell">` pasa a bloque con el valor alineado a la derecha. La
celda de Fecha/Hora lleva `.detail-cell-primary` y actúa como cabecera de la
tarjeta (fondo gris, alineada a la izquierda); la de Estado lleva
`.detail-cell-last` para quitarle el borde inferior.

**Sobre el etiquetado de columnas.** El patrón habitual en la web es
`td::before { content: attr(data-label) }`, y aquí **no se usó**: Gmail no
soporta pseudo-elementos, así que la etiqueta habría desaparecido justo en el
cliente que motivó el trabajo. En su lugar cada celda imprime un
`<span class="stack-label">` que está en `display: none` por defecto y solo se
muestra —flotado a la izquierda— dentro del media query. Es marcado real, lo
entiende cualquier cliente que soporte media queries, y en los que no lo hacen
permanece oculto sin ensuciar la tabla de escritorio.

Complementos: `word-break: break-word` y `overflow-wrap: break-word` en todas
las celdas, y el padding subió de `8px 10px` a `10px` en escritorio y
`10px 14px` en móvil. El zebra striping (`tr:nth-child(even)`) se neutraliza al
apilar, porque con tarjetas delimitadas por borde deja de aportar y solo
introduce fondos grises inconsistentes dentro de una misma tarjeta.

### 61.5 Compatibilidad de clientes

Ni Flexbox ni CSS Grid entran en la estructura: todo el layout se sostiene sobre
tablas HTML con anchos en porcentaje y atributos `width` en las celdas, que es
lo único que el motor Word de Outlook interpreta. Outlook de escritorio ignora
los media queries, así que recibe la versión de escritorio íntegra —tres
columnas y tabla completa—, que es exactamente el resultado correcto en ese
cliente. Se añadieron `mso-table-lspace` / `mso-table-rspace` a `0` para
eliminar el espaciado fantasma que Word inyecta alrededor de cada tabla.

### Archivos Modificados
- `backend/resources/views/mail/cash-register-closing.blade.php` — media query de 600 px, métricas en tabla apilable, detalle en tarjetas apiladas con respaldo de scroll horizontal y `word-break` en celdas

## 62. REFACTORIZACIÓN MOBILE-FIRST INTEGRAL DEL POS (LAYOUT, TABLAS, MODALES Y DASHBOARDS) [🟢 COMPLETADO Y OPERATIVO]

La sección 61 arregló el **correo** de cierres en el móvil. Esta arregla la
**aplicación entera**: hasta ahora el POS solo era usable en escritorio. En un
teléfono el sidebar fijo de 16 rem seguía reservando su ancho, así que cada
vista nacía con 256 px menos de los que tenía, y cualquier tabla de siete
columnas arrastraba el viewport de lado.

El trabajo se ejecutó en cuatro fases y **se verificó en un navegador real**
(Chromium headless a 320, 375, 767, 768 y 1280 px), no solo leyendo clases. Esa
verificación encontró dos defectos que la lectura del código no habría
detectado — están documentados en 62.7.

### 62.0 Los tres invariantes

1. **Mobile-first de verdad.** La declaración sin prefijo describe el teléfono;
   `sm:` / `md:` / `lg:` **añaden** capacidad conforme hay espacio. No hay una
   sola regla que empiece en escritorio y se "deshaga" hacia abajo.
2. **Cero desbordamiento horizontal.** Medido, no supuesto:
   `document.documentElement.scrollWidth === window.innerWidth` en los cuatro
   anchos de prueba. Cuando una tabla ancha necesita scroll, ese scroll vive
   **dentro de su propia caja**, nunca en el documento.
3. **Áreas táctiles reales.** 44 px de mínimo en controles primarios (el umbral
   por debajo del cual el dedo empieza a fallar), 38-40 px en acciones
   secundarias densas.

### 62.1 Dónde vive cada cosa

El sistema usa **PrimeReact**, que renderiza su propio DOM: diálogos, tablas,
paginadores, pestañas y paneles flotantes. Sus clases no son alcanzables con una
utilidad de Tailwind desde fuera, y sus anchos venían de un `style` en línea que
**ningún media query puede leer**. Por eso el trabajo se reparte en dos capas:

- **`frontend/src/index.css`** — una capa mobile-first que corrige el DOM de
  PrimeReact **una sola vez, a nivel de clase de vendor**. Todo diálogo y toda
  tabla del sistema la hereda, incluidas las pantallas que todavía no existen.
  Las reglas van **fuera de `@layer`** a propósito: el tema de PrimeReact se
  importa sin capa en `vendor.css`, lo que lo hace ganar contra cualquier
  utilidad de Tailwind (que sí está en capa). Una regla sin capa y de igual
  especificidad gana por orden de origen.
- **`frontend/src/lib/responsive.js`** — el vocabulario compartido que usan las
  páginas: escala de anchos de diálogo, `pt` común, helpers de visibilidad de
  columnas y props de tabla apilada.

---

### 62.2 FASE 1 — Navegación y estructura global

**Archivos:** `components/layout/AppLayout.jsx`, `Sidebar.jsx`, `AppHeader.jsx`,
`UserProfileDropdown.jsx`, `notifications/NotificationBell.jsx`.

#### Dos estados de navegación, uno por factor de forma

`AppLayout` mantiene **dos estados independientes** para que ninguno pueda
corromper al otro:

| Estado | Rango | Qué hace |
|---|---|---|
| `collapsed` | `>= lg` | El riel histórico de escritorio: alterna entre 16 rem con etiquetas y 72 px solo-iconos, y arrastra el margen del contenido con él. |
| `mobileNavOpen` | `< lg` | El *drawer* off-canvas: el sidebar vive en `-translate-x-full` y entra deslizándose sobre un fondo oscurecido. |

**Toda** clase gobernada por `collapsed` lleva prefijo `lg:`. Es la pieza que
hace que los dos estados no se pisen: el riel es una afordancia exclusiva de
escritorio, y un teléfono debe recibir siempre el drawer completo con etiquetas
**aunque el usuario haya dejado el riel colapsado en su monitor**.

#### El margen que causaba el desbordamiento

La columna de contenido **no lleva margen izquierdo por debajo de `lg`**
(`lg:ml-64` / `lg:ml-[72px]`). El drawer se superpone a la página en vez de
desplazarla. Esto es exactamente lo que elimina la barra de scroll horizontal
permanente que el `ml-64` fijo imponía en cualquier viewport estrecho.

#### Detalles que hacen que se sienta nativo

- **Cierre al navegar mediante ajuste de estado durante el render**, no con un
  `useEffect`. El drawer tiene que estar cerrado ya en el **primer frame** que
  pinta la nueva ruta; un efecto lo cerraría un commit tarde y se vería como un
  parpadeo del menú encima del destino.
- **Bloqueo de scroll del cuerpo** mientras el drawer está abierto, capturando y
  restaurando el valor previo para componer con los diálogos de PrimeReact, que
  fijan el suyo. Sin esto la página sigue desplazándose por debajo del dedo.
- **`Escape` cierra**, y el backdrop es clicable.
- El drawer mide `18rem` acotado a `85vw`: nunca llena la pantalla de lado a
  lado, así que siempre queda contexto visible detrás que dice "esto es una capa".

#### Un glifo, dos acciones

La hamburguesa son **dos botones**, no uno que ramifica sobre un media query en
JS: por debajo de `lg` abre el drawer, desde `lg` colapsa el riel. La
visibilidad es CSS pura, lo que significa **sin listener de `resize` y sin
parpadeo en el primer pintado**.

#### Resto del chrome

- El **título de página sobrevive en el teléfono** (truncado, nunca envuelto).
  Los badges de estado degradan: el aviso offline pierde su frase y conserva el
  icono; el contador de ventas aparece desde `md`.
- Padding del `main`: `p-3 sm:p-4 lg:p-6`.
- Campana, avatar y menús de perfil pasan a 40 px táctiles y sus paneles se
  acotan a `calc(100vw - 1.5rem)`.
- `z-index`: sidebar 50 → backdrop 40 → header 30. El drawer se superpone al
  header, que es lo que hace que se lea como una capa y no como un panel
  encajado debajo.

**Verificado:** a 375 px el drawer está fuera de pantalla (`right = 0`), abre a
`left = 0` con `body { overflow: hidden }` y filas de 44 px, y cierra con
`Escape`. A 1280 px queda acoplado a 256 px (contenido a 256) y colapsa a 72 px
(contenido a 72).

---

### 62.3 FASE 2 — Tablas y listados: tarjetas apiladas

**19 tablas** en 17 archivos: Cierres de Caja, Auditoría de Cierres, Historial
de Ventas, Almacén, Usuarios, Caja Chica, Productos, Categorías, Promociones,
Métodos de Pago, Mesas, Papelera, Roles, Tickets, Caché de Módulos, Correos por
Proceso y las tres del Monitor de Jobs.

#### Por qué NO se escribió una lista de tarjetas paralela

La tentación era un `<div className="md:hidden">` con tarjetas al lado de la
tabla. Se descartó: **cada tabla del sistema lleva comportamiento que vive
dentro del `DataTable`** — paginación (perezosa y servida por el backend en
Cierres, Historial, Papelera, Auditoría y Monitor de Jobs; en cliente en el
resto), búsqueda por `globalFilter`, ordenamiento y overlay de carga. Una lista
paralela habría tenido que reimplementar todo eso **por página**, y se habría
desincronizado de la tabla de al lado la primera vez que alguien cambiara una
columna.

La solución conserva **una sola fuente de verdad**: la misma tabla se **re-mapea
a tarjetas por CSS** por debajo de `md`.

#### Qué aporta PrimeReact y qué hubo que escribir

`responsiveLayout="stack"` (exportado como `STACK_TABLE`) hace dos cosas útiles
en esta versión: marca la raíz con `p-datatable-responsive-stack` y **renderiza
un `<span class="p-column-title">` dentro de cada celda del cuerpo** con el
encabezado de su columna. Ese span es la pieza clave: es lo que permite que una
celda se lea como «TOTAL — $1,284.00» cuando la fila de encabezados desaparece.

Lo que **no** hace de forma fiable es inyectar el media query que ejecuta el
apilado. Se comprobó en el navegador: a 375 px no llegaba esa hoja al documento.
Por eso el layout se define en `index.css`, apoyado en la clase `pos-stack` que
las páginas ya pasan. Poseerlo también significa que **las tarjetas no pueden
regresar en silencio con una actualización de PrimeReact**.

La clase `pos-stack` hace, por debajo de 767.98 px:

- Oculta `thead` y `tfoot` — los reemplazan las etiquetas dentro de cada celda.
- Saca a `table`, `tbody` y `tr` del *table layout* (`display: block`). Mientras
  son `display: table*`, **los anchos de columna siguen dictando el ancho
  intrínseco de la tabla** y el wrapper se desplaza de lado: justamente lo que
  las tarjetas existen para eliminar. Medido: 766 px de tabla dentro de un
  wrapper de 351 px.
- Convierte cada `<tr>` en una tarjeta (borde, radio, sombra, separación) y cada
  `<td>` en una línea flex de etiqueta/valor, con el pie de acciones en gris.
- Neutraliza el *zebra striping*: con tarjetas delimitadas por borde deja de
  aportar y solo mete fondos grises inconsistentes dentro de una misma tarjeta
  (el mismo razonamiento de 61.4).
- Rompe palabras largas en la celda: una celda cuyo `body` devuelve **texto
  pelado** se convierte en un *anonymous flex item* que ningún selector alcanza,
  así que el `word-break` va sobre el `<td>`.

#### El `!important` que sí es imprescindible

```css
.pos-stack .p-datatable-tbody > tr > td {
  display: flex !important;
  width: 100% !important;
  min-width: 0 !important;
  max-width: none !important;
}
```

No es ruido defensivo. Las columnas del sistema llevan `style={{ width: '150px' }}`
o `minWidth` en línea, y **PrimeReact copia ese estilo al `<td>`**. Un estilo en
línea gana a cualquier declaración normal, así que sin estos overrides la
tarjeta conservaría sus anchos de columna de escritorio: líneas de
etiqueta/valor de 110 px una al lado de otra, es decir, exactamente el layout
roto que las tarjetas vienen a sustituir.

#### Visibilidad de columnas: `hidden md:table-cell` para PrimeReact

`className` en un `<Column>` se reenvía **al `<th>` y al `<td>`**, lo que hace
que una sola clase sea el equivalente exacto. `HIDE_BELOW.{sm,md,lg}` se escribe
como "oculta por debajo del breakpoint" —nunca "oculta y luego muestra"— para
que por encima del corte la celda conserve el `display` que le dé su contexto:
`table-cell` en una tabla, `flex` dentro de una tarjeta.

**En modo tarjeta estas clases eliminan la línea completa.** Eso es lo que
mantiene la tarjeta corta: los campos secundarios sencillamente no existen en el
teléfono. Ejemplo, Historial de Ventas (la tabla más ancha, 11 columnas):

| Columna | 320-767 px | 768-1023 px | >= 1024 px |
|---|---|---|---|
| ID Ticket, Fecha, Vendedor, Método, Total, Estatus, Acciones | ✅ | ✅ | ✅ |
| Origen | — | ✅ | ✅ |
| Descuento, Recibido, Cambio | — | — | ✅ |

El trío de flujo de efectivo vuelve en `lg`, que es donde de verdad se hace una
conciliación.

Otros recortes: Productos oculta Grupo (`lg`), Costo y Categoría (`md`);
Usuarios oculta Sesión (`md`) y Creado (`lg`); Caja Chica oculta el Sello
forense (`lg`); Almacén oculta Costo Unitario (`md`) y Usuario (`lg`); Correos
por Proceso oculta Remitente (`lg`), Asunto y Destinos (`md`). **Cierres de Caja
no oculta nada**: cada columna de un arqueo es material para el arqueo, así que
su fila se convierte en una tarjeta de siete líneas.

Dos columnas con `header=""` (Caja Chica, Tickets) se nombraron «Acciones»:
en modo tarjeta un encabezado vacío deja una línea sin etiqueta.

#### El alternativo: scroll táctil

`TABLE_CLASS` / `TABLE_CLASS_WIDE` (`pos-table`) es para las pocas tablas que
**no pueden apilarse** — PrimeReact ignora `responsiveLayout` cuando hay
`scrollable`, como el desglose por artículo dentro del Resumen del Día. Ahí la
tabla conserva su forma dentro de un scroll horizontal suave
(`-webkit-overflow-scrolling: touch`) con un ancho mínimo de columna razonable.
`overscroll-behavior-x: contain` impide que el deslizamiento se entregue al
gesto de "atrás" del navegador. El `min-width` se excluye explícitamente de
`.pos-stack`: ahí reintroduciría el scroll que las tarjetas eliminan.

#### Barras de herramientas

- Encabezados de listado: título sobre acción primaria por debajo de `sm`.
- Buscadores: `w-full`, recuperan `w-64` / `w-72` desde `sm`.
- Filtros de Historial, Usuarios, Auditoría, Cierres, Papelera y Monitor de Jobs
  a una columna (o rejilla de 2 en el Monitor, donde son seis controles).
- Paginador con `flex-wrap` y su leyenda «x de y» a línea completa.
- **Pestañas (`TabView`) y grupos segmentados (`SelectButton`) se desplazan
  horizontalmente en vez de envolverse**: un control segmentado que envuelve
  deja de leerse como un solo control.

---

### 62.4 FASE 3 — Modales, formularios y flotantes

#### La escala de diálogos

Los diálogos se dimensionaban con `style={{ width: '50vw' }}` o `'480px'` en
línea. Un `style` en línea es **invisible para un media query**, así que "medio
ancho" seguía siendo medio ancho en un teléfono de 360 px: 180 px útiles. Todos
migran a `dialogClass()` de `lib/responsive.js`:

| Tamaño | Teléfono | Desde `sm` | Uso |
|---|---|---|---|
| `sm` | `100vw - 1.5rem` | `26rem` | Confirmaciones |
| `md` | `100vw - 1.5rem` | `32rem` | Formularios de una columna (el caso normal) |
| `lg` | `100vw - 1.5rem` | `48rem` en `lg` | Formularios de dos columnas |
| `xl` | `100vw - 1.5rem` | `64rem` en `lg` | Paneles de detalle con tabla |

La escala es deliberadamente **gruesa**: cuatro tamaños cubren todo el sistema, y
tenerlos fijos es lo que hace que los modales se sientan una familia y no veinte
decisiones independientes. En el teléfono todos resuelven a "viewport menos 12 px
por lado", que es lo más ancho que puede ser un diálogo **sin dejar de leerse
como una superficie flotante** en lugar de una página rota a pantalla completa.

`DIALOG_PT` añade máscara con padding, tope de altura `92dvh` y contenido
desplazable, para que encabezado y pie sigan alcanzables en un teléfono en
horizontal. En `index.css` hay además un suelo global (`max-width`,
`max-height`, pies apilados a ancho completo) que protege incluso a un diálogo
que se escapara de la convención.

#### Formularios

Toda rejilla no responsiva pasa a **una columna** y recupera su forma desde `sm`:
Producto (2 y 3 columnas), Promociones, Correos por Proceso, Configuración del
Sistema, Almacén, Tickets, y las rejillas de detalle de Historial, Cierres,
Auditoría, Usuarios, Mesas y Notificaciones. Una rejilla de dos columnas a
360 px da ~160 px por control: más angosto que el propio texto que contiene.

Las filas de acción usan **`flex-col-reverse`**: el botón que confirma se
escribe último, así que en columna invertida queda **arriba, donde descansa el
pulgar**, y el de cancelar debajo.

Los insets de tarjeta pasan a `p-4 sm:p-6`. 24 px por lado son 48 px de una
pantalla de 360: una séptima parte, y es justo el padding que empujaba las
cifras largas fuera del borde.

#### iOS y el zoom al enfocar

Los controles de PrimeReact se fijan a **16 px de fuente en el teléfono**. Por
debajo de 16 px, Safari hace zoom sobre la página al enfocar un campo, y el
viewport salta en cada toque. Es el mismo tipo de problema que
`-webkit-text-size-adjust` resolvió para el correo en 61.2.

#### El POS

- Barra de turno: cajero y reloj en la primera fila; folio y fondo inicial en una
  segunda fila a ancho completo.
- El carrito **solo es `sticky` desde `lg`**, donde es un riel lateral. En un
  teléfono es una sección de la página y fijarlo taparía el catálogo.
- **Barra de cobro móvil (nueva).** En el teléfono el carrito queda debajo de
  todo el catálogo, así que el total corriente —el número que el cajero mira
  constantemente— se iba de pantalla tras unos pocos productos. Una barra fija
  mantiene visibles el total y el conteo de artículos y salta al ticket. Existe
  solo por debajo de `lg` (donde el carrito no está ya visible como riel) y solo
  mientras hay algo en él, con un espaciador equivalente para que **nunca tape la
  última fila de productos**.
- Steppers de cantidad de 24 → 36 px táctiles, tiles de producto con altura
  mínima, botón de quitar línea con área real.
- La **vista previa del ticket de 58 mm** es un artefacto de ancho fijo: se
  desplaza dentro de su propia caja en vez de ensanchar el diálogo de cobro.

---

### 62.5 FASE 4 — Dashboards, métricas y tarjetas de resumen

#### Rejillas

Todas las rejillas de indicadores son de una columna en el teléfono y escalan
por breakpoint: Dashboard y Monitor de Jobs (1/2/4), Panel Financiero (1/2/4),
Caja Chica y Almacén (1/3), Analítica Financiera (1/2/4 — antes eran **dos
columnas fijas** incluso a 360 px).

#### `min-w-0`: la pieza que de verdad arregla el desbordamiento

Una cifra en pesos como `$1,284,530.00` son 13 glifos. A `text-2xl` dentro de una
tarjeta a un cuarto de ancho **es más ancha que la tarjeta**. La corrección son
tres cosas juntas:

1. Un escalón menos de tipografía en el teléfono (`text-2xl` → `text-xl`,
   `text-xl` → `text-lg`), más `tabular-nums` para que las cifras no bailen.
2. `truncate` sobre el valor.
3. **`min-w-0` sobre la tarjeta.** Este es el que importa: un *grid item* tiene
   `min-width: auto` por defecto, así que **sin él el item se niega a encogerse
   por debajo de su contenido y `truncate` nunca llega a actuar**. La fila de
   KPIs ensancha la página entera en vez de recortar el número.

Las filas de desglose (Caja Chica, Almacén, desglose por método de pago) dejan
truncar la etiqueta y marcan la cifra como no encogible.

#### Gráficas y esqueletos

La tendencia horaria se dibuja a 220 px en el teléfono y 280 px desde `sm`. El
`DashboardSkeleton` **replica esa altura y los nuevos paddings**: si el
esqueleto y la tarjeta real no coinciden, el primer pintado salta cuando llegan
los datos, y eso se lee como un error, no como carga.

#### Chips, encabezados y paneles

- Los chips de estado de Plano de Mesas y Auditoría de Cierres **se desplazan
  como una tira** en vez de envolverse en tres filas que empujan el contenido
  fuera de la primera pantalla.
- Títulos `text-xl sm:text-2xl` en las 18 páginas.
- Plantillas de Correo convierte su layout de dos paneles en uno apilado por
  debajo de `lg`, con marco de previsualización `70dvh` en vez de 650 px fijos:
  650 px de iframe bajo un header y una barra no dejan nada de página visible.
- Controles de ancho fijo restantes (filtros de Usuarios y Mesas, selector de
  lada telefónica, etiquetas del arqueo ciego) pasan a fluidos. El arqueo ciego
  en concreto reservaba 10 rem para la etiqueta del método de pago y dejaba
  ~120 px para un `InputNumber` de moneda; ahora la etiqueta va **encima** del
  campo en el teléfono.

---

### 62.6 Verificación en navegador

No se dio nada por bueno leyendo clases. Se montó un arnés temporal con una
tabla de 8 columnas, valores largos y una rejilla de KPIs, se sirvió el build de
producción y se midió con Chromium a **320, 375, 767, 768 y 1280 px**:

| Comprobación | Resultado |
|---|---|
| `scrollWidth === innerWidth` en los cuatro anchos | ✅ sin desbordamiento del documento |
| `<= 767 px`: `thead` oculto, `tr` en bloque, `td` en flex a ancho completo | ✅ (351 px de celda en un viewport de 375) |
| `<= 767 px`: columnas `col-hide-md` fuera de la tarjeta | ✅ 7 de 8 celdas |
| `<= 767 px`: etiquetas `.p-column-title` visibles | ✅ |
| `>= 768 px`: tabla de escritorio intacta, 8 columnas, etiquetas ocultas | ✅ |
| Paginación operativa en modo tarjeta | ✅ |
| Drawer fuera de pantalla / abierto / `Escape` / scroll bloqueado / filas de 44 px | ✅ |
| Riel de escritorio 256 px ↔ 72 px con el margen del contenido siguiéndolo | ✅ |

El arnés se eliminó antes de consolidar. El lint quedó en la **misma línea base
que antes del trabajo** (47 errores preexistentes, ninguno nuevo).

### 62.7 Los dos defectos que solo el navegador reveló

Ambos habrían pasado una revisión de código sin problema:

1. **PrimeReact no inyectaba su hoja de apilado.** Se asumió que
   `responsiveLayout="stack"` traía consigo el media query que oculta el
   encabezado y convierte las celdas en líneas. No llegaba al documento. El
   resultado era un **híbrido a medio apilar**: encabezado visible, tabla aún
   dimensionada por sus columnas (766 px dentro de un wrapper de 351 px) y
   scroll lateral dentro de la caja. La corrección fue escribir el layout
   completo en `index.css` sobre la clase `pos-stack`.
2. **Las columnas ocultas reaparecían dentro de la tarjeta.** `.col-hide-md`
   (especificidad 0-1-0) perdía contra la regla de tarjeta
   `.pos-stack .p-datatable-tbody > tr > td` (0-2-3) **aunque ambas llevaran
   `!important`**: entre dos declaraciones `!important` decide la especificidad.
   La corrección fue darle a las reglas de visibilidad la misma forma de selector
   (`.p-datatable .p-datatable-tbody > tr > td.col-hide-md`, 0-3-3).

Se aprovechó además para alinear **todos** los breakpoints de tabla móvil en
`767.98px`, exactamente el `md` de Tailwind: antes el apilado (`max-width: 768px`)
y el ocultamiento de columnas (`max-width: 767.98px`) discrepaban justo en 768 px,
donde se veía una tarjeta que aún mostraba una columna secundaria.

### Archivos Modificados

**Núcleo compartido**
- `frontend/src/index.css` — capa mobile-first completa: diálogos, tarjetas
  apiladas (`pos-stack`), scroll táctil (`pos-table`), visibilidad de columnas
  (`col-hide-*`), paginador, pestañas y segmentados, paneles flotantes, toasts,
  tipografía de 16 px y áreas táctiles de 44 px
- `frontend/src/lib/responsive.js` — **nuevo**: `dialogClass()`, `DIALOG_PT`,
  `HIDE_BELOW`, `STACK_TABLE`, `STACK_CLASS`, `TABLE_CLASS`

**Fase 1 — layout**
- `components/layout/AppLayout.jsx`, `Sidebar.jsx`, `AppHeader.jsx`,
  `UserProfileDropdown.jsx`
- `components/notifications/NotificationBell.jsx`

**Fase 2 — tablas y listados**
- `pages/admin/` — `CashRegisterClosingsPage`, `CashClosingsAuditPage`,
  `UsersPage`, `TrashPage`, `PaymentMethodsPage`, `TablesPage`,
  `RolesPermissionsPage`, `JobsMonitorPage` (3 tablas)
- `pages/sales/SalesHistoryPage`, `pages/logistics/StockMovementsPage`,
  `pages/finance/PettyCashPage`, `pages/catalog/ProductsPage`,
  `pages/catalog/CategoriesPage`, `pages/promotions/PromotionsPage`,
  `pages/settings/TicketConfigPage`
- `components/settings/CacheSettingsPanel`, `EmailNotificationsPanel`

**Fase 3 — modales y formularios**
- `components/pos/CheckoutModal`, `PrintConfirmationModal`
- `components/catalog/DeleteDialog`, `components/finance/WithdrawModal`
- `components/dining/TableDetailModal`, `TableCancellationModal`
- `components/notifications/NotificationDetailTemplates`
- `pages/pos/POSPage` (barra de cobro móvil), `pages/catalog/ProductFormPage`,
  `pages/auth/LoginPage`, `pages/admin/SystemSettingsPage`

**Fase 4 — dashboards y métricas**
- `pages/DashboardPage`, `components/dashboard/DashboardSkeleton`,
  `MonthlyAnalyticsModal`
- `pages/finance/FinanceDashboardPage`, `pages/dining/TablesFloorPlanPage`,
  `pages/admin/MailTemplatesPage`, `pages/profile/ProfilePage`
