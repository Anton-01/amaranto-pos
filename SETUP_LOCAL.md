# Cronos POS - Guia de Instalacion Local

## Requisitos Previos

| Software | Version Minima | Verificar |
| :--- | :--- | :--- |
| PHP | 8.3+ | `php -v` |
| Composer | 2.x | `composer -V` |
| Node.js | 20+ LTS | `node -v` |
| npm | 10+ | `npm -v` |
| PostgreSQL | 15+ | `psql --version` |
| Git | 2.x | `git --version` |

### Extensiones PHP Requeridas
```
php-pgsql php-mbstring php-xml php-curl php-zip php-bcmath php-gd php-intl
```

Verificar extensiones instaladas:
```bash
php -m | grep -E "pgsql|mbstring|xml|curl|zip|bcmath|gd|intl"
```

---

## 1. Clonar el Repositorio

```bash
git clone https://github.com/anton-01/amaranto-pos.git
cd amaranto-pos
```

---

## 2. Configurar PostgreSQL

Crear la base de datos y el usuario:

```bash
sudo -u postgres psql
```

```sql
CREATE USER cronos_user WITH PASSWORD 'tu_password_seguro';
CREATE DATABASE cronos_pos OWNER cronos_user;
GRANT ALL PRIVILEGES ON DATABASE cronos_pos TO cronos_user;
\q
```

---

## 3. Configurar el Backend (Laravel)

### 3.1 Instalar dependencias PHP

```bash
cd backend
composer install
```

### 3.2 Configurar el archivo de entorno

```bash
cp .env.example .env
```

Editar `backend/.env` con los siguientes valores:

```env
APP_NAME="Cronos POS"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=cronos_pos
DB_USERNAME=cronos_user
DB_PASSWORD=tu_password_seguro

BROADCAST_CONNECTION=log
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database

MAIL_MAILER=log
MAIL_FROM_ADDRESS="no-reply@cronos.pos"
MAIL_FROM_NAME="Cronos POS"
```

### 3.3 Generar clave de aplicacion

```bash
php artisan key:generate
```

### 3.4 Ejecutar migraciones y seeder

```bash
php artisan migrate --seed
```

Esto crea:
- 18 tablas con ENUMs nativos de PostgreSQL
- 3 roles base: `admin`, `manager`, `vendor`
- Usuario administrador: `admin@cronos.pos` / `password`
- 6 tipos de notificacion
- Configuraciones globales (tax_rate, investment_split)

### 3.5 Verificar que el backend arranca

```bash
php artisan serve
```

Debe responder en `http://localhost:8000`. Probar con:

```bash
curl http://localhost:8000/api/auth/login \
  -X POST \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@cronos.pos","password":"password"}'
```

Detener el servidor (Ctrl+C) antes de continuar.

---

## 4. Configurar el Frontend (React)

### 4.1 Instalar dependencias Node

```bash
cd ../frontend
npm install
```

### 4.2 Verificar la configuracion del proxy

El archivo `vite.config.js` ya esta configurado para redirigir `/api` al backend:

```js
proxy: {
  '/api': {
    target: 'http://localhost:8000',
    changeOrigin: true,
  },
},
```

No requiere archivo `.env` en el frontend. El proxy de Vite maneja la comunicacion con el backend.

---

## 5. Levantar el Entorno Completo

### Opcion A: Dos terminales separadas (recomendado para depuracion)

**Terminal 1 — Backend:**
```bash
cd backend
php artisan serve
```

**Terminal 2 — Frontend:**
```bash
cd frontend
npm run dev
```

### Opcion B: Script concurrente del backend

```bash
cd backend
composer dev
```

Este comando ejecuta en paralelo: `php artisan serve`, `php artisan queue:listen`, `php artisan pail` (logs), y `npm run dev` (Vite del backend, no del frontend SPA).

**Nota:** Para el frontend SPA, siempre levantarlo desde la carpeta `frontend/`:

```bash
cd frontend
npm run dev
```

---

## 6. Acceder a la Aplicacion

| Servicio | URL |
| :--- | :--- |
| Frontend (React SPA) | http://localhost:3000 |
| Backend API | http://localhost:8000/api |

### Credenciales del Administrador

| Campo | Valor |
| :--- | :--- |
| Email | admin@cronos.pos |
| Password | password |

---

## 7. Comandos Utiles

### Backend (desde `backend/`)

```bash
# Ejecutar migraciones pendientes
php artisan migrate

# Revertir y re-ejecutar migraciones + seed (DESTRUCTIVO)
php artisan migrate:fresh --seed

# Limpiar caches
php artisan config:clear && php artisan cache:clear && php artisan route:clear

# Ver rutas API registradas
php artisan route:list --path=api

# Ejecutar tests
php artisan test

# Procesar cola de trabajos (emails, notificaciones)
php artisan queue:work

# Formatear codigo PHP
./vendor/bin/pint
```

### Frontend (desde `frontend/`)

```bash
# Servidor de desarrollo con HMR
npm run dev

# Build de produccion
npm run build

# Preview del build de produccion
npm run preview

# Linter
npm run lint
```

---

## 8. Estructura del Proyecto

```
amaranto-pos/
├── backend/                    # Laravel 13 API
│   ├── app/
│   │   ├── Events/             # PettyCashTransactionRegistered
│   │   ├── Http/
│   │   │   ├── Controllers/    # 13 controladores
│   │   │   ├── Middleware/     # EnsureUserIsActive, EnsureUserHasRole
│   │   │   └── Requests/      # 12 FormRequests
│   │   ├── Listeners/          # NotifyPettyCashWithdrawal
│   │   ├── Mail/               # PasswordResetLinkMail
│   │   ├── Models/             # 15 modelos Eloquent
│   │   ├── Notifications/      # PettyCashWithdrawalNotification
│   │   └── Traits/             # AdvancedSoftDeletes
│   ├── database/
│   │   ├── migrations/         # 18 migraciones PostgreSQL
│   │   └── seeders/            # DatabaseSeeder con roles, admin, settings
│   ├── routes/
│   │   ├── api.php             # ~53 endpoints RESTful
│   │   └── web.php             # Ruta password.reset (URL firmada)
│   └── .env.example
│
├── frontend/                   # React 19 SPA
│   ├── src/
│   │   ├── api/                # axios.js (interceptors, proxy)
│   │   ├── components/
│   │   │   ├── layout/         # AppLayout
│   │   │   ├── finance/        # WithdrawModal, AuditTicket
│   │   │   └── pos/            # TicketPreview, CheckoutModal
│   │   ├── context/            # AuthContext (login, logout, fetchUser)
│   │   └── pages/              # 14 paginas
│   ├── vite.config.js          # Puerto 3000, proxy /api -> :8000
│   └── package.json
│
├── CONTEXT.md                  # Documentacion tecnica completa del sistema
└── README.md
```

---

## 9. Resolucion de Problemas Comunes

### Error: "could not find driver" (PDO PostgreSQL)

```bash
# Ubuntu/Debian
sudo apt install php8.3-pgsql
sudo systemctl restart php8.3-fpm

# macOS (Homebrew)
brew install php@8.3
```

### Error: ENUM type already exists al re-migrar

Los ENUMs nativos de PostgreSQL no se eliminan con `migrate:rollback`. Usar:

```bash
php artisan migrate:fresh --seed
```

### Error: CORS al hacer requests desde el frontend

Verificar que el frontend se ejecuta en `http://localhost:3000` y que el proxy de Vite esta activo. No acceder directamente al backend en `:8000` desde el navegador para las paginas del SPA.

### El queue worker no procesa emails

```bash
php artisan queue:work --tries=3
```

Verificar `QUEUE_CONNECTION=database` en `.env` y que la tabla `jobs` exista (se crea con las migraciones).
