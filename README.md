# Cronos - Sistema POS Gastronómico v1.0

Sistema de Punto de Venta (POS) optimizado para entornos Fast Food. Desarrollado bajo una arquitectura desacoplada y blindaje transaccional inmutable.

## Stack Tecnológico
- **Backend:** Laravel 11/12 (API-First) - PHP 8.3 Alpine
- **Frontend:** React SPA (Tailwind CSS v4 + PrimeReact)
- **Base de Datos:** PostgreSQL Gestionado (DigitalOcean)
- **Mensajería en Tiempo Real:** Laravel Reverb (WebSockets)
- **Seguridad:** Laravel Sanctum + Google Authenticator (2FA)

## Entorno de Desarrollo (Docker)

El sistema está completamente dockerizado para garantizar la paridad entre entornos de desarrollo y producción.

### Requisitos Previos
- Docker Desktop instalado
- Git

### Pasos para Arrancar
1. Clonar el repositorio.
2. Configurar los archivos de entorno `.env` en `./backend` y `./frontend` basándose en los `.env.example`.
3. Ejecutar el orquestador desde la raíz:

```bash
docker compose up --build
