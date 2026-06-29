# Cronos POS — Guia de Despliegue en Produccion (DigitalOcean)

## Arquitectura Fisica

```
┌──────────────────────────────────────────────────────────┐
│  INTERNET (HTTPS :443)                                    │
│          │                                                │
│  ┌───────▼─────────────────────────────────────────────┐ │
│  │  Nginx Host (Proxy Inverso + SSL Termination)       │ │
│  │  Certbot/Let's Encrypt auto-renewal                 │ │
│  └──┬──────────┬──────────────┬─────────────────────┘  │
│     │          │              │                          │
│ :3000(SPA) :8000(API)   :8080(WSS)                      │
│     │          │              │                          │
│  ┌──▼──┐  ┌───▼───┐    ┌────▼────┐    ┌──────────┐    │
│  │React│  │Laravel│    │ Reverb  │    │  Redis   │    │
│  │Nginx│  │  API  │    │WebSocket│    │ Cache+Q  │    │
│  └─────┘  └───┬───┘    └─────────┘    └──────────┘    │
│               │          DROPLET (Ubuntu LTS)            │
└───────────────┼──────────────────────────────────────────┘
                │ SSL (puerto 25060)
        ┌───────▼───────────────┐
        │  DigitalOcean Managed │
        │  PostgreSQL Cluster   │
        │  (Trusted Sources)    │
        └───────────────────────┘
```

---

## Paso 1: Crear el Droplet

1. Ir a DigitalOcean > Create > Droplets
2. Seleccionar **Ubuntu 24.04 LTS**
3. Plan recomendado: **Basic — 4 GB RAM / 2 vCPUs / 80 GB SSD** (escalable)
4. Region: la mas cercana a tus usuarios (ej: `sfo3`, `nyc3`)
5. Autenticacion: **SSH Key** (nunca usar password)
6. Hostname: `cronos-pos-prod`

### Configuracion inicial del Droplet

```bash
# Conectar al Droplet
ssh root@IP_DEL_DROPLET

# Actualizar paquetes
apt update && apt upgrade -y

# Instalar dependencias del sistema
apt install -y curl git ufw nginx certbot python3-certbot-nginx

# Instalar Docker Engine
curl -fsSL https://get.docker.com | sh
apt install -y docker-compose-plugin

# Verificar instalacion
docker --version
docker compose version

# Crear usuario de despliegue (no root)
adduser deploy
usermod -aG docker deploy
usermod -aG sudo deploy

# Configurar firewall
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable
ufw status
```

---

## Paso 2: Crear la Base de Datos Gestionada (Managed PostgreSQL)

1. Ir a DigitalOcean > Databases > Create Database Cluster
2. Engine: **PostgreSQL 16**
3. Region: **misma region que el Droplet**
4. Plan: **Basic — 1 GB RAM / 1 vCPU** (escalable)
5. Nombre del cluster: `cronos-db-prod`

### Restriccion de Seguridad Perimetral (Trusted Sources)

**CRITICO**: Configurar inmediatamente despues de crear el cluster.

1. Ir a: Database Cluster > Settings > Trusted Sources
2. Agregar como fuente confiable: **el Droplet creado en Paso 1** (seleccionar por nombre)
3. Esto RECHAZA cualquier conexion que no provenga de la IP publica del Droplet
4. Verificar que NO haya entradas "0.0.0.0/0" (acceso publico)

### Crear base de datos y usuario dedicado

Desde la consola del cluster (DigitalOcean > Database > Users & Databases):

```
# Crear base de datos
Database name: cronos_pos

# Crear usuario aplicativo (NO usar doadmin en produccion)
Username: cronos_app
```

### Obtener cadena de conexion

Ir a: Database Cluster > Connection Details > Connection Parameters.
Copiar los valores para el `.env.production`:

```
Host: tu-cluster-db-do-user-XXXXX-0.db.ondigitalocean.com
Port: 25060
Database: cronos_pos
User: cronos_app
Password: (generado automaticamente por DigitalOcean)
SSL Mode: require
```

### Configuracion Laravel para SSL obligatorio

En el archivo `config/database.php` del backend, dentro de la conexion `pgsql`, agregar las opciones PDO de SSL:

```php
'pgsql' => [
    // ... configuracion existente ...
    'sslmode' => env('DB_SSLMODE', 'prefer'),
    'options' => extension_loaded('pdo_pgsql') ? [
        PDO::ATTR_EMULATE_PREPARES => false,
    ] : [],
],
```

En el `.env.production`:
```
DB_SSLMODE=require
```

Esto garantiza que **todas las conexiones** entre el Droplet y PostgreSQL viajan cifradas por SSL.

---

## Paso 3: Clonar el Repositorio y Configurar Variables

```bash
# Cambiar a usuario deploy
su - deploy

# Clonar repositorio
sudo git clone https://github.com/tu-org/cronos-pos.git /opt/cronos-pos
sudo chown -R deploy:deploy /opt/cronos-pos
cd /opt/cronos-pos

# Crear archivo de variables de produccion
cp .env.production.example .env.production

# Editar con los valores reales
nano .env.production
```

### Variables CRITICAS a configurar

```bash
# Generar APP_KEY (copiar el output al .env.production)
docker run --rm php:8.4-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"

# Generar claves de Reverb
openssl rand -hex 16  # Para REVERB_APP_KEY
openssl rand -hex 16  # Para REVERB_APP_SECRET
```

---

## Paso 4: Configurar Nginx como Proxy Inverso

```bash
# Copiar configuracion del proxy
sudo cp /opt/cronos-pos/infrastructure/cronos-pos.conf /etc/nginx/sites-available/cronos-pos.conf

# Reemplazar dominio placeholder
sudo sed -i 's/TU_DOMINIO.com/tu-dominio-real.com/g' /etc/nginx/sites-available/cronos-pos.conf

# Habilitar el sitio
sudo ln -sf /etc/nginx/sites-available/cronos-pos.conf /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default

# Validar configuracion (ANTES de obtener SSL)
# Temporalmente comentar las lineas ssl_* para el primer test
sudo nginx -t
sudo systemctl reload nginx
```

---

## Paso 5: Obtener Certificados SSL con Certbot

```bash
# Obtener certificado SSL para el dominio
sudo certbot --nginx -d tu-dominio-real.com -d www.tu-dominio-real.com \
    --non-interactive \
    --agree-tos \
    --email admin@tu-dominio-real.com \
    --redirect

# Verificar renovacion automatica
sudo certbot renew --dry-run

# Certbot instala automaticamente un cron/timer de systemd
# para renovar certificados cada 60 dias. Verificar:
sudo systemctl status certbot.timer
```

### Nota sobre el primer despliegue

Para el primer certificado SSL, es necesario que Nginx pueda responder en puerto 80. El bloque `location /.well-known/acme-challenge/` en la configuracion ya lo permite. Secuencia:

1. Apuntar el DNS del dominio a la IP del Droplet (registro A)
2. Esperar propagacion DNS (5-30 minutos)
3. Ejecutar `certbot --nginx` (automaticamente modifica la config de Nginx)
4. Verificar HTTPS: `curl -I https://tu-dominio-real.com`

---

## Paso 6: Generar Certificados QZ Tray y Primer Despliegue

La impresion en Cronos POS opera bajo el esquema **QZ Tray (client-side)**. El servidor genera comandos ESC/POS en memoria via `DummyPrintConnector` y los retorna como Base64 al frontend, que los despacha al hardware local a traves de QZ Tray via WebSocket. No se requieren paquetes SMB, Samba, CUPS ni configuraciones de firewall para el puerto 445.

El backend firma cada solicitud de QZ Tray con RSA-SHA512 usando una llave privada almacenada en el Droplet. El certificado publico correspondiente se instala en cada maquina cliente.

### 6.1 Generar llaves RSA para firma digital de QZ Tray

Ejecutar los siguientes comandos directamente en el Droplet para crear las llaves dentro del almacenamiento persistente del backend:

```bash
cd /opt/cronos-pos

# Crear el directorio seguro de certificados dentro del almacenamiento persistente del backend
mkdir -p backend/storage/app/certs

# Generar la llave privada RSA de 2048 bits
openssl genrsa -out backend/storage/app/certs/private-key.pem 2048

# Generar el certificado publico digital firmado por 10 años (3650 dias) sin interactividad
openssl req -x509 -new -nodes \
    -key backend/storage/app/certs/private-key.pem \
    -days 3650 \
    -out backend/storage/app/certs/digital-certificate.txt \
    -subj "/C=MX/ST=Michoacan/L=Morelia/O=AntonLogic/CN=CronosPOS"

# Asegurar los permisos correctos para que el contenedor de PHP (www-data) pueda leer la llave
chmod 600 backend/storage/app/certs/private-key.pem
chmod 644 backend/storage/app/certs/digital-certificate.txt
```

Verificar que los archivos se crearon correctamente:

```bash
ls -la backend/storage/app/certs/
# Esperado:
# -rw-------  private-key.pem    (solo lectura por el propietario)
# -rw-r--r--  digital-certificate.txt  (lectura publica)
```

### 6.2 Descargar el certificado publico a las sucursales

El archivo `digital-certificate.txt` debe copiarse a cada computadora fisica que ejecute QZ Tray. Desde tu **maquina local**, ejecutar:

```bash
# Descargar el certificado publico desde el Droplet via SCP
scp root@IP_DEL_DROPLET:/opt/cronos-pos/backend/storage/app/certs/digital-certificate.txt ./

# Alternativa con usuario deploy:
scp deploy@IP_DEL_DROPLET:/opt/cronos-pos/backend/storage/app/certs/digital-certificate.txt ./
```

Una vez descargado, copiar el archivo `digital-certificate.txt` en la carpeta `auth/` de la instalacion de QZ Tray en cada maquina de sucursal:

- **Windows**: `C:\Program Files\QZ Tray\auth\digital-certificate.txt`
- **macOS**: `/Applications/QZ Tray.app/Contents/Resources/auth/digital-certificate.txt`
- **Linux**: `/opt/qz-tray/auth/digital-certificate.txt`

### 6.3 Primer despliegue de contenedores

```bash
cd /opt/cronos-pos

# Detener cualquier servicio previo
docker compose -f docker-compose.prod.yml down

# Compilar y levantar todos los servicios desde cero
docker compose -f docker-compose.prod.yml up -d --build

# Ejecutar migraciones iniciales con seed
docker compose -f docker-compose.prod.yml exec backend php artisan migrate --seed --force

# Limpiar y optimizar caches de Laravel
docker compose -f docker-compose.prod.yml exec backend php artisan config:clear
docker compose -f docker-compose.prod.yml exec backend php artisan optimize

# Verificar que todos los contenedores estan corriendo
docker compose -f docker-compose.prod.yml ps

# Verificar logs (Ctrl+C para salir)
docker compose -f docker-compose.prod.yml logs -f backend
```

### 6.4 Re-intento de despliegue limpio

Si el despliegue falla o necesitas reconstruir desde cero (por ejemplo, tras corregir variables de entorno o actualizar Dockerfiles), ejecutar la siguiente secuencia completa:

```bash
cd /opt/cronos-pos

# Detener y eliminar contenedores, redes y volumenes anonimos
docker compose -f docker-compose.prod.yml down

# Reconstruir imagenes y levantar servicios
docker compose -f docker-compose.prod.yml up -d --build

# Ejecutar migraciones pendientes
docker compose -f docker-compose.prod.yml exec backend php artisan migrate --force

# Limpiar caches obsoletas
docker compose -f docker-compose.prod.yml exec backend php artisan config:clear

# Optimizar rutas, config y vistas para produccion
docker compose -f docker-compose.prod.yml exec backend php artisan optimize
```

---

## Paso 7: Configurar Cron Jobs del Servidor

Laravel Schedule necesita un cron job en el host que invoque `schedule:run` cada minuto.

```bash
# Abrir crontab del usuario deploy
crontab -e

# Agregar la siguiente linea:
* * * * * cd /opt/cronos-pos && docker compose -f docker-compose.prod.yml exec -T backend php artisan schedule:run >> /var/log/cronos-schedule.log 2>&1
```

### Verificar que el scheduler funciona

```bash
# Ejecutar manualmente para verificar
cd /opt/cronos-pos
docker compose -f docker-compose.prod.yml exec backend php artisan schedule:list
```

---

## Paso 8: Despliegues Subsecuentes

Para cada actualizacion en produccion, ejecutar:

```bash
cd /opt/cronos-pos
bash deploy.sh
```

El script `deploy.sh` automatiza:
1. `git pull origin main` (fast-forward only)
2. `docker compose up --build -d` (rebuild + restart)
3. Espera a que el backend este saludable
4. `php artisan migrate --force` (migraciones pendientes)
5. Reconstruye caches (config, route, event, view)
6. `php artisan queue:restart` (reinicia workers)
7. Limpia imagenes Docker huerfanas

---

## Verificacion de Puertos (Sin Conflictos)

| Puerto | Servicio | Acceso |
| :--- | :--- | :--- |
| 80 | Nginx (HTTP → redirect HTTPS) | Publico |
| 443 | Nginx (HTTPS proxy) | Publico |
| 3000 | Frontend Nginx (SPA) | Solo 127.0.0.1 (via proxy) |
| 8000 | Backend Laravel (API) | Solo 127.0.0.1 (via proxy) |
| 8080 | Reverb WebSocket | Solo 127.0.0.1 (via proxy) |
| 6379 | Redis | Solo red Docker interna |
| 25060 | PostgreSQL (Managed) | Solo IP del Droplet (Trusted Sources) |

Ningun servicio interno expone puertos al mundo exterior. Todo el trafico publico pasa por Nginx HTTPS (443).

---

## Monitoreo y Mantenimiento

### Ver logs en tiempo real

```bash
# Todos los servicios
docker compose -f docker-compose.prod.yml logs -f

# Solo backend
docker compose -f docker-compose.prod.yml logs -f backend

# Solo errores de Laravel
docker compose -f docker-compose.prod.yml exec backend tail -f storage/logs/laravel.log
```

### Reiniciar servicios individuales

```bash
# Reiniciar solo el backend (sin rebuild)
docker compose -f docker-compose.prod.yml restart backend

# Reiniciar queue worker
docker compose -f docker-compose.prod.yml exec backend php artisan queue:restart
```

### Backup manual de Redis

```bash
docker compose -f docker-compose.prod.yml exec redis redis-cli BGSAVE
```

### Verificar estado de la base de datos

```bash
# Conexion directa desde el Droplet
docker compose -f docker-compose.prod.yml exec backend php artisan db:show
```
