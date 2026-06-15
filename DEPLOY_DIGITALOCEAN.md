# Cronos POS - Guia de Despliegue en DigitalOcean

## Arquitectura de Infraestructura

```
                    ┌──────────────────────────────────┐
                    │       DigitalOcean Cloud          │
                    │                                   │
┌──────────┐       │  ┌─────────────────────────────┐  │
│ Usuarios │──────▶│  │    Droplet (App Server)     │  │
│ (HTTPS)  │       │  │                             │  │
└──────────┘       │  │  ┌───────────────────────┐  │  │
                    │  │  │       Nginx            │  │  │
                    │  │  │   (Reverse Proxy)      │  │  │
                    │  │  │                        │  │  │
                    │  │  │  :443 → :8000 (API)    │  │  │
                    │  │  │  /ws  → :8080 (Reverb) │  │  │
                    │  │  │  /*   → React SPA      │  │  │
                    │  │  └───────────────────────┘  │  │
                    │  │                             │  │
                    │  │  ┌──────────┐ ┌──────────┐  │  │
                    │  │  │ PHP-FPM  │ │  Redis   │  │  │
                    │  │  │ 8.3     │ │  7.x     │  │  │
                    │  │  └────┬─────┘ └──────────┘  │  │
                    │  │       │                      │  │
                    │  │  ┌────▼─────┐ ┌──────────┐  │  │
                    │  │  │ Reverb   │ │ Queue    │  │  │
                    │  │  │ :8080   │ │ Worker   │  │  │
                    │  │  └──────────┘ └──────────┘  │  │
                    │  └──────────┼──────────────────┘  │
                    │             │                      │
                    │  ┌──────────▼──────────────────┐  │
                    │  │   Managed PostgreSQL 16     │  │
                    │  │   (DigitalOcean Database)   │  │
                    │  └─────────────────────────────┘  │
                    └──────────────────────────────────┘
```

| Componente | Servicio DigitalOcean | Especificacion |
| :--- | :--- | :--- |
| App Server | Droplet | Ubuntu 24.04, 2 vCPU, 4GB RAM (minimo) |
| Base de Datos | Managed Database | PostgreSQL 16, 1GB RAM, 10GB disco |
| Cache / Colas | Redis (en Droplet) | redis-server local |
| WebSockets | Laravel Reverb (en Droplet) | Puerto 8080 |
| DNS | DigitalOcean DNS | Registro A → IP del Droplet |
| SSL | Let's Encrypt (Certbot) | Certificado HTTPS gratuito |

### Nota sobre Docker

El proyecto incluye `docker-compose.yml` con `Dockerfile.dev` para desarrollo local. Para produccion en DigitalOcean se recomienda la instalacion nativa (sin Docker) en el Droplet para mejor rendimiento y control directo de los servicios. Esta guia cubre la instalacion nativa en produccion.

---

## Fase 1: Crear la Base de Datos Gestionada

### 1.1 Desde el Panel de DigitalOcean

1. Ir a **Databases** → **Create Database Cluster**
2. Seleccionar:
   - Engine: **PostgreSQL 16**
   - Region: La mas cercana a tus usuarios (ej: `nyc1`, `sfo3`)
   - Plan: **Basic** → 1 GB RAM / 1 vCPU / 10 GB Disco (~$15/mes)
   - Cluster name: `cronos-pos-db`
3. Click **Create Database Cluster**

### 1.2 Crear la Base de Datos

Una vez creado el cluster, ir a **Users & Databases**:

1. Crear database: `cronos_pos`
2. El usuario por defecto es `doadmin` (ya tiene permisos)

### 1.3 Guardar las Credenciales

Desde la pestaña **Connection Details**, seleccionar **Connection String** y guardar:

```
host=tu-cluster-do-user-xxxxx-0.db.ondigitalocean.com
port=25060
dbname=cronos_pos
user=doadmin
password=xxxxxxxxxxxxxxxx
sslmode=require
```

### 1.4 Configurar Trusted Sources

En la pestaña **Settings** → **Trusted Sources**, agregar la IP del Droplet una vez creado.

---

## Fase 2: Crear el Droplet (Servidor de Aplicacion)

### 2.1 Desde el Panel de DigitalOcean

1. Ir a **Droplets** → **Create Droplet**
2. Seleccionar:
   - Image: **Ubuntu 24.04 (LTS)**
   - Plan: **Basic** → 2 vCPU / 4 GB RAM / 80 GB SSD (~$24/mes)
   - Region: **Misma region que la base de datos**
   - Authentication: **SSH Key** (recomendado)
   - Hostname: `cronos-pos-app`
3. Click **Create Droplet**

### 2.2 Acceder al Servidor

```bash
ssh root@TU_IP_DEL_DROPLET
```

---

## Fase 3: Preparar el Servidor

### 3.1 Actualizar el Sistema

```bash
apt update && apt upgrade -y
```

### 3.2 Crear Usuario de Aplicacion (no usar root)

```bash
adduser cronos
usermod -aG sudo cronos
```

Copiar la clave SSH al nuevo usuario:

```bash
rsync --archive --chown=cronos:cronos ~/.ssh /home/cronos
```

Cerrar sesion y reconectar como `cronos`:

```bash
ssh cronos@TU_IP_DEL_DROPLET
```

### 3.3 Configurar Firewall

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
sudo ufw status
```

### 3.4 Instalar PHP 8.3

```bash
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

sudo apt install -y \
  php8.4-fpm \
  php8.4-pgsql \
  php8.4-mbstring \
  php8.4-xml \
  php8.4-curl \
  php8.4-zip \
  php8.4-bcmath \
  php8.4-gd \
  php8.4-intl \
  php8.4-redis \
  php8.4-pcntl
```

Verificar:

```bash
php -v
php -m | grep -E "pgsql|mbstring|xml|curl|zip|bcmath|gd|intl|redis|pcntl"
```

### 3.5 Instalar Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer -V
```

### 3.6 Instalar Node.js 20 LTS

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
node -v && npm -v
```

### 3.7 Instalar Nginx

```bash
sudo apt install -y nginx
sudo systemctl enable nginx
```

### 3.8 Instalar Redis

```bash
sudo apt install -y redis-server
sudo systemctl enable redis-server
```

Verificar:

```bash
redis-cli ping
# Debe responder: PONG
```

### 3.9 Instalar Certbot (SSL)

```bash
sudo apt install -y certbot python3-certbot-nginx
```

---

## Fase 4: Desplegar la Aplicacion

### 4.1 Clonar el Repositorio

```bash
cd /var/www
sudo git clone https://github.com/anton-01/amaranto-pos.git cronos-pos
sudo chown -R cronos:cronos /var/www/cronos-pos
cd /var/www/cronos-pos
```

### 4.2 Configurar el Backend

```bash
cd /var/www/cronos-pos/backend
composer install --optimize-autoloader --no-dev
```

Crear el archivo de entorno de produccion:

```bash
cp .env.example .env
nano .env
```

Configurar los valores de produccion:

```env
APP_NAME="Cronos POS"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tu-dominio.com
APP_KEY=

DB_CONNECTION=pgsql
DB_HOST=tu-cluster-do-user-xxxxx-0.db.ondigitalocean.com
DB_PORT=25060
DB_DATABASE=cronos_pos
DB_USERNAME=doadmin
DB_PASSWORD=tu_password_de_digitalocean
DB_SSLMODE=require

REDIS_HOST=127.0.0.1
REDIS_PORT=6379

BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=database

REVERB_APP_ID=cronos-production
REVERB_APP_KEY=tu-reverb-key-seguro
REVERB_APP_SECRET=tu-reverb-secret-seguro
REVERB_HOST=0.0.0.0
REVERB_PORT=8080

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST=tu-dominio.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https

MAIL_MAILER=smtp
MAIL_HOST=smtp.tu-proveedor.com
MAIL_PORT=587
MAIL_USERNAME=tu-email@dominio.com
MAIL_PASSWORD=tu_password_smtp
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="no-reply@tu-dominio.com"
MAIL_FROM_NAME="Cronos POS"

BCRYPT_ROUNDS=12
```

Generar clave, instalar Reverb, y ejecutar migraciones:

```bash
php artisan key:generate
composer require laravel/reverb --with-all-dependencies
php artisan install:broadcasting --force
php artisan migrate --seed --force
```

Optimizar para produccion:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Configurar permisos:

```bash
sudo chown -R cronos:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

### 4.3 Compilar el Frontend

```bash
cd /var/www/cronos-pos/frontend
npm ci
npm run build
```

Esto genera la carpeta `frontend/dist/` con los archivos estaticos.

---

## Fase 5: Configurar Nginx

### 5.1 Crear la Configuracion del Sitio

```bash
sudo nano /etc/nginx/sites-available/cronos-pos
```

Pegar la siguiente configuracion:

```nginx
server {
    listen 80;
    server_name tu-dominio.com;

    # Archivos estaticos del Frontend (React SPA)
    root /var/www/cronos-pos/frontend/dist;
    index index.html;

    # Gzip para performance
    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml text/javascript image/svg+xml;
    gzip_min_length 1000;

    # API → PHP-FPM (Laravel)
    location /api {
        alias /var/www/cronos-pos/backend/public;
        try_files $uri $uri/ @laravel;

        location ~ \.php$ {
            fastcgi_pass unix:/run/php/php8.4-fpm.sock;
            fastcgi_param SCRIPT_FILENAME /var/www/cronos-pos/backend/public/index.php;
            include fastcgi_params;
        }
    }

    location @laravel {
        rewrite ^/api/(.*)$ /api/index.php?/$1 last;
    }

    # WebSocket Reverb (upgrade de conexion)
    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 60s;
        proxy_send_timeout 60s;
    }

    # Sanctum CSRF cookie
    location /sanctum {
        try_files $uri $uri/ @laravel;
    }

    # Cache agresivo para assets estaticos
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff2?)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    # React SPA fallback — todas las rutas al index.html
    location / {
        try_files $uri $uri/ /index.html;
    }

    # Bloquear acceso a archivos sensibles
    location ~ /\.(?!well-known) {
        deny all;
    }

    # Limites de upload
    client_max_body_size 10M;
}
```

### 5.2 Activar el Sitio

```bash
sudo ln -s /etc/nginx/sites-available/cronos-pos /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

---

## Fase 6: Configurar SSL con Let's Encrypt

### 6.1 Apuntar el DNS

En tu proveedor de DNS (o DigitalOcean DNS):
- Crear registro **A** → `tu-dominio.com` → IP del Droplet
- Crear registro **A** → `www.tu-dominio.com` → IP del Droplet

Esperar la propagacion DNS (verificar con `dig tu-dominio.com`).

### 6.2 Obtener Certificado SSL

```bash
sudo certbot --nginx -d tu-dominio.com -d www.tu-dominio.com
```

Certbot modifica automaticamente la configuracion de Nginx para HTTPS, incluyendo el proxy WebSocket de Reverb.

### 6.3 Verificar Renovacion Automatica

```bash
sudo certbot renew --dry-run
```

---

## Fase 7: Configurar PHP-FPM para Produccion

### 7.1 Ajustar Pool de Workers

```bash
sudo nano /etc/php/8.3/fpm/pool.d/www.conf
```

Buscar y ajustar:

```ini
user = cronos
group = cronos

pm = dynamic
pm.max_children = 20
pm.start_servers = 5
pm.min_spare_servers = 3
pm.max_spare_servers = 10
pm.max_requests = 500
```

```bash
sudo systemctl restart php8.4-fpm
```

---

## Fase 8: Configurar Supervisor (Queue Worker + Reverb)

El sistema usa colas para emails (PasswordResetLinkMail) y notificaciones (PettyCashWithdrawalNotification), y Reverb para WebSockets en tiempo real.

### 8.1 Instalar Supervisor

```bash
sudo apt install -y supervisor
```

### 8.2 Crear Configuracion del Queue Worker

```bash
sudo nano /etc/supervisor/conf.d/cronos-worker.conf
```

```ini
[program:cronos-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/cronos-pos/backend/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=cronos
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/cronos-pos/backend/storage/logs/worker.log
stopwaitsecs=3600
```

### 8.3 Crear Configuracion de Reverb

```bash
sudo nano /etc/supervisor/conf.d/cronos-reverb.conf
```

```ini
[program:cronos-reverb]
process_name=%(program_name)s
command=php /var/www/cronos-pos/backend/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=cronos
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/cronos-pos/backend/storage/logs/reverb.log
stopwaitsecs=10
```

### 8.4 Activar los Servicios

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start cronos-worker:*
sudo supervisorctl start cronos-reverb
sudo supervisorctl status
```

---

## Fase 9: Configurar Cron (Scheduler)

```bash
sudo crontab -u cronos -e
```

Agregar:

```cron
* * * * * cd /var/www/cronos-pos/backend && php artisan schedule:run >> /dev/null 2>&1
```

---

## Fase 10: Script de Despliegue (Actualizaciones)

Crear un script para futuros despliegues:

```bash
nano /var/www/cronos-pos/deploy.sh
```

```bash
#!/bin/bash
set -e

echo "=== Cronos POS - Deploy ==="
cd /var/www/cronos-pos

echo "→ Pulling latest code..."
git pull origin main

echo "→ Installing backend dependencies..."
cd backend
composer install --optimize-autoloader --no-dev

echo "→ Running migrations..."
php artisan migrate --force

echo "→ Clearing and rebuilding caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "→ Building frontend..."
cd ../frontend
npm ci
npm run build

echo "→ Restarting services..."
sudo systemctl restart php8.4-fpm
sudo supervisorctl restart cronos-worker:*
sudo supervisorctl restart cronos-reverb

echo "=== Deploy completed ==="
```

```bash
chmod +x /var/www/cronos-pos/deploy.sh
```

Para desplegar actualizaciones futuras:

```bash
cd /var/www/cronos-pos && ./deploy.sh
```

---

## Fase 11: Verificacion Post-Despliegue

### 11.1 Checklist de Salud

```bash
# PHP-FPM activo
sudo systemctl status php8.4-fpm

# Nginx activo
sudo systemctl status nginx

# Redis activo
sudo systemctl status redis-server

# Queue Workers y Reverb activos
sudo supervisorctl status

# Probar API
curl -s https://tu-dominio.com/api/auth/login \
  -X POST \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@cronos.pos","password":"password"}' | head -c 200

# Verificar HTTPS
curl -I https://tu-dominio.com

# Verificar WebSocket (debe responder upgrade)
curl -s -o /dev/null -w "%{http_code}" \
  -H "Upgrade: websocket" \
  -H "Connection: upgrade" \
  https://tu-dominio.com/app/cronos-reverb-key
```

### 11.2 Acceder a la Aplicacion

1. Abrir `https://tu-dominio.com` en el navegador
2. Login con `admin@cronos.pos` / `password`
3. **Cambiar la contraseña del admin inmediatamente**
4. Verificar funcionalidad: Dashboard, POS, Productos, Categorias

---

## Fase 12: Seguridad Post-Despliegue

### 12.1 Cambiar Credenciales por Defecto

Despues del primer login:
- Cambiar la contraseña del usuario `admin@cronos.pos`
- Habilitar 2FA (Google Authenticator)
- Crear usuarios adicionales con roles apropiados

### 12.2 Hardening del Servidor

```bash
# Deshabilitar login con root por SSH
sudo nano /etc/ssh/sshd_config
# PermitRootLogin no
sudo systemctl restart sshd

# Instalar fail2ban
sudo apt install -y fail2ban
sudo systemctl enable fail2ban
```

### 12.3 Asegurar Redis

```bash
sudo nano /etc/redis/redis.conf
```

Verificar que estas lineas esten activas:
```ini
bind 127.0.0.1 ::1
protected-mode yes
```

```bash
sudo systemctl restart redis-server
```

### 12.4 Backups Automaticos

La base de datos gestionada de DigitalOcean incluye backups automaticos diarios.
Para backups adicionales on-demand:

```bash
# Backup manual de la BD
PGPASSWORD=tu_password pg_dump \
  -h tu-cluster-do-user-xxxxx-0.db.ondigitalocean.com \
  -p 25060 \
  -U doadmin \
  -d cronos_pos \
  --no-owner \
  -F c \
  -f backup_$(date +%Y%m%d_%H%M%S).dump
```

### 12.5 Monitoreo

Desde el panel de DigitalOcean:
- **Droplet Monitoring**: CPU, RAM, Disco, Bandwidth (activar en el Droplet)
- **Database Metrics**: Conexiones activas, queries lentas, almacenamiento

---

## Resumen de Costos Estimados (USD/mes)

| Recurso | Plan | Costo |
| :--- | :--- | :--- |
| Droplet (2 vCPU / 4 GB) | Basic | ~$24 |
| Managed PostgreSQL | Basic 1 GB | ~$15 |
| Dominio (.com) | Externo | ~$1 |
| SSL (Let's Encrypt) | Gratuito | $0 |
| Redis (en Droplet) | Incluido | $0 |
| **Total estimado** | | **~$40/mes** |

---

## Estructura de Servicios en Produccion

| Servicio | Puerto | Gestion |
| :--- | :--- | :--- |
| Nginx | 80, 443 | `systemctl` |
| PHP-FPM 8.4 | unix socket | `systemctl` |
| Redis | 6379 (local) | `systemctl` |
| Queue Workers (x2) | — | `supervisorctl` |
| Laravel Reverb | 8080 (via Nginx) | `supervisorctl` |
| Cron Scheduler | — | `crontab` |
| PostgreSQL | 25060 (remoto) | DigitalOcean Panel |
