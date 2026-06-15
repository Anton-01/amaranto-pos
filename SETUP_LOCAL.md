# Cronos POS - Guia de Instalacion Local

## Opcion A: Docker (Recomendado — Zero Dependencies)

Solo necesitas **Docker Desktop** y **Git** instalados en tu maquina.

### Requisitos

| Software | Version Minima | Verificar |
| :--- | :--- | :--- |
| Docker Desktop | 4.x+ | `docker --version` |
| Docker Compose | 2.x+ (incluido en Desktop) | `docker compose version` |
| Git | 2.x | `git --version` |

### 1. Clonar el Repositorio

```bash
git clone https://github.com/anton-01/amaranto-pos.git
cd amaranto-pos
```

### 2. Levantar Todo el Entorno

```bash
docker compose up --build
```

El primer arranque tarda ~2-3 minutos. El entrypoint del backend automatiza:
- Instala dependencias de Composer (si `vendor/` no existe)
- Crea `.env` desde `.env.example` y genera `APP_KEY`
- Ejecuta `migrate:fresh --seed` (crea las 18 tablas + datos base)
- Instala Laravel Reverb (WebSockets)
- Inicia el queue worker en segundo plano
- Inicia Reverb en puerto 8080
- Inicia el servidor Laravel en puerto 8000

### 3. Acceder a la Aplicacion

| Servicio | URL | Descripcion |
| :--- | :--- | :--- |
| Frontend (React SPA) | http://localhost:3000 | Vite dev server con HMR |
| Backend API | http://localhost:8000/api | Laravel API |
| WebSockets (Reverb) | ws://localhost:8080 | Tiempo real |
| PostgreSQL | localhost:5432 | DB local (user: `cronos`, pass: `cronos_secret`) |
| Redis | localhost:6379 | Cache y colas |

### Credenciales del Administrador

| Campo | Valor |
| :--- | :--- |
| Email | admin@cronos.pos |
| Password | password |

### 4. Comandos Docker Utiles

```bash
# Levantar en segundo plano
docker compose up -d

# Ver logs en tiempo real
docker compose logs -f backend
docker compose logs -f frontend

# Ejecutar artisan dentro del contenedor
docker compose exec backend php artisan migrate:status
docker compose exec backend php artisan route:list --path=api
docker compose exec backend php artisan tinker

# Ejecutar tests
docker compose exec backend php artisan test

# Rebuild completo (si cambias Dockerfiles)
docker compose down && docker compose up --build

# Destruir todo (incluyendo volumenes de datos)
docker compose down -v
```

### 5. Estructura Docker

```
amaranto-pos/
├── docker-compose.yml              # Orquestador: 4 servicios
├── backend/
│   ├── Dockerfile.dev              # PHP 8.3-FPM Alpine + extensiones
│   ├── docker-entrypoint.sh        # Automatizacion de arranque
│   └── .dockerignore               # Excluye vendor/, node_modules/
├── frontend/
│   ├── Dockerfile.dev              # Node 22-Alpine + Vite
│   └── .dockerignore               # Excluye node_modules/, dist/
```

### 6. Hot-Reload en Desarrollo

- **Frontend**: Vite HMR esta activo. Editar archivos en `frontend/src/` recarga el navegador al instante.
- **Backend**: El codigo PHP se monta como volumen. Los cambios en `backend/app/` se reflejan inmediatamente sin reiniciar.
- **Nota**: Si cambias `composer.json`, ejecutar `docker compose exec backend composer install` manualmente.

### 7. Conectar a la Base de Datos con un Cliente

Puedes usar pgAdmin, DBeaver, o TablePlus para conectarte a PostgreSQL:

| Campo | Valor |
| :--- | :--- |
| Host | localhost |
| Puerto | 5432 |
| Base de datos | cronos_pos |
| Usuario | cronos |
| Password | cronos_secret |

---

## Opcion B: Instalacion Nativa (Sin Docker)

Si prefieres ejecutar los servicios directamente en tu maquina.

### Requisitos Previos

| Software | Version Minima | Verificar |
| :--- | :--- | :--- |
| PHP | 8.4+ | `php -v` |
| Composer | 2.x | `composer -V` |
| Node.js | 20+ LTS | `node -v` |
| npm | 10+ | `npm -v` |
| PostgreSQL | 15+ | `psql --version` |
| Redis | 7+ (opcional) | `redis-cli --version` |
| Git | 2.x | `git --version` |

### Extensiones PHP Requeridas
```
php-pgsql php-mbstring php-xml php-curl php-zip php-bcmath php-gd php-intl php-redis
```

Verificar extensiones instaladas:
```bash
php -m | grep -E "pgsql|mbstring|xml|curl|zip|bcmath|gd|intl"
```

### 1. Clonar el Repositorio

```bash
git clone https://github.com/anton-01/amaranto-pos.git
cd amaranto-pos
```

### 2. Configurar PostgreSQL

```bash
sudo -u postgres psql
```

```sql
CREATE USER cronos_user WITH PASSWORD 'tu_password_seguro';
CREATE DATABASE cronos_pos OWNER cronos_user;
GRANT ALL PRIVILEGES ON DATABASE cronos_pos TO cronos_user;
\q
```

### 3. Configurar el Backend

```bash
cd backend
composer install
cp .env.example .env
```

Editar `backend/.env` — cambiar hosts a localhost:

```env
DB_HOST=127.0.0.1
DB_USERNAME=cronos_user
DB_PASSWORD=tu_password_seguro

REDIS_HOST=127.0.0.1

# Si no tienes Redis, usar database como fallback:
CACHE_STORE=database
QUEUE_CONNECTION=database
```

```bash
php artisan key:generate
php artisan migrate --seed
```

### 4. Configurar el Frontend

```bash
cd ../frontend
npm install
```

### 5. Levantar el Entorno

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

**Terminal 3 — Queue Worker (opcional, para emails/notificaciones):**
```bash
cd backend
php artisan queue:work
```

### 6. Acceder a la Aplicacion

| Servicio | URL |
| :--- | :--- |
| Frontend (React SPA) | http://localhost:3000 |
| Backend API | http://localhost:8000/api |

| Campo | Valor |
| :--- | :--- |
| Email | admin@cronos.pos |
| Password | password |

---

## Resolucion de Problemas Comunes

### Docker: El backend no conecta a PostgreSQL

Verificar que postgres este healthy:
```bash
docker compose ps
```
Si el estado no es "healthy", revisar logs:
```bash
docker compose logs postgres
```

### Docker: Cambios en composer.json no se reflejan

```bash
docker compose exec backend composer install
```

### Docker: Permisos de storage en Linux

Si Laravel reporta errores de escritura en storage/:
```bash
docker compose exec backend chown -R www-data:www-data storage bootstrap/cache
```

### Nativo: Error "could not find driver" (PDO PostgreSQL)

```bash
# Ubuntu/Debian
sudo apt install php8.4-pgsql
sudo systemctl restart php8.4-fpm

# macOS (Homebrew)
brew install php@8.4
```

### Error: ENUM type already exists al re-migrar

Los ENUMs nativos de PostgreSQL no se eliminan con `migrate:rollback`. Usar:
```bash
php artisan migrate:fresh --seed
# O en Docker:
docker compose exec backend php artisan migrate:fresh --seed
```

### Error: CORS al hacer requests desde el frontend

Verificar que el frontend corre en `http://localhost:3000` y que el proxy de Vite esta activo. No acceder al backend `:8000` directamente desde el navegador para las paginas del SPA.

### El queue worker no procesa emails

```bash
# Docker
docker compose exec backend php artisan queue:work --tries=3

# Nativo
php artisan queue:work --tries=3
```
