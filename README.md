# ce-portal-back

Backend del portal de clientes My Credit Experts (Laravel 13, PHP 8.5). Comparte la base MySQL con el Soft (amgsoftninjaback) y llama a sus endpoints internos para pagos, tarjetas y SMS.

## Puesta en marcha local

Todo corre en Docker; no hace falta PHP 8.5 en la máquina. La app usa la red del host, así que llega a la MySQL y al Redis locales del Soft.

```bash
cp .env.example .env                                   # completar DB_* (misma base local del Soft) y SOFT_API_KEY
docker compose build app
docker compose run --rm --no-deps app composer install
docker compose run --rm --no-deps app php artisan key:generate
docker compose up -d app horizon mailpit
curl http://localhost:8002/api/v1/status                # {"status":"ok","database":"ok"}
```

| Servicio | URL |
|---|---|
| API | http://localhost:8002/api/v1 |
| OpenAPI (JSON) / Swagger UI | http://localhost:8002/docs · http://localhost:8002/api/documentation |
| Horizon | http://localhost:8002/horizon |
| Mailpit | http://localhost:8025 |

El front corre en `http://localhost:3001`: el back debe usarse como `localhost` (no `127.0.0.1`) para que la cookie de sesión `SameSite=Strict` viaje.

## Calidad

```bash
docker compose run --rm --no-deps app composer check      # Pint, Larastan nivel 8 y Pest
```

## Tests de integración (MySQL con el esquema del Soft)

```bash
docker compose up -d mysql-test
./docker/mysql-test/load-soft-schema.sh                   # tablas, SP y triggers del Soft, sin datos
docker compose run --rm --no-deps app composer test:integration
```

## Estructura

- Un módulo por carpeta en `app/Modules/<Modulo>/`, con su `routes.php` (se monta solo bajo `/api/v1`).
- `app/Modules/Shared/Soft/SoftGateway.php`: única puerta a los endpoints internos del Soft (request firmado con HMAC, `X-Request-Id`, `Idempotency-Key`, reintentos de conexión).
- Las tablas del Soft no se crean ni alteran desde aquí; este proyecto solo migra las suyas (`migrations_backnew`).
