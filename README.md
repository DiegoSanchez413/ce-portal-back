# amgsoftbacknew

Backend nuevo de AMG sobre Laravel 13 y PHP 8.5. Comparte la base MySQL con el Soft (amgsoftninjaback).

## Puesta en marcha local

```bash
php8.5 /usr/local/bin/composer setup        # instala dependencias, crea .env y la APP_KEY
# completar DB_PASSWORD en .env (misma base local del Soft)
php8.5 artisan serve --port=8001
curl http://127.0.0.1:8001/api/v1/status    # {"status":"ok","database":"ok"}
```

## Calidad

```bash
php8.5 /usr/local/bin/composer lint      # Pint
php8.5 /usr/local/bin/composer analyse   # Larastan nivel 8
php8.5 /usr/local/bin/composer test      # Pest
php8.5 /usr/local/bin/composer check     # las tres
```


