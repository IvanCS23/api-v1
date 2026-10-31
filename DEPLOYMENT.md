# Despliegue de FacturaPro

Checklist para preparar un servidor de pre-producción o producción. Cada
punto remite a la documentación de detalle (`BILLING_LIFECYCLE.md`) cuando
existe. Fase 6.29.

## 1. Requisitos

| Componente | Versión |
|---|---|
| PHP | 8.2 o superior, con `pdo_mysql`, `openssl`, `mbstring`, `fileinfo` |
| Laravel | 12 (lo fija `composer.json`) |
| Base de datos | **MySQL 8.0 o MariaDB 10.6 o superior** (recomendado) |
| Node.js | el que pida `app-front/package.json` (Next.js) |

Sobre la base de datos:

- Laravel 12 acepta MySQL 5.7 y MariaDB 10.3, pero en producción usa MySQL 8
  o MariaDB 10.6+. La MariaDB 10.4 del entorno local (XAMPP) ya no tiene
  soporte del fabricante.
- El esquema usa una columna generada virtual (`invoices.active_sale_id`) y
  columnas JSON, disponibles en esas versiones.
- No se necesitan las tablas de zonas horarias de MySQL: la aplicación sólo
  usa desfases fijos (`+00:00`).

## 2. Variables de entorno (`api-v1/.env`)

Parte de `.env.example`. Las que no deben quedarse con el valor de desarrollo:

| Variable | Producción | Notas |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | con `true` las excepciones muestran detalles internos |
| `APP_KEY` | generada una vez | ver la sección 6 |
| `APP_PREVIOUS_KEYS` | vacía, salvo durante una rotación | ver la sección 6 |
| `APP_URL` | URL pública de la API | |
| `FRONTEND_URL` | URL pública del frontend | único origen permitido por CORS |
| `LOG_LEVEL` | `info` o `warning` | los rechazos del guard PAC son `warning` |
| `DB_CONNECTION` | `mysql` o `mariadb` | |
| `DB_TIMEZONE` | **`+00:00`** | ver la sección 4 |
| `QUEUE_CONNECTION` | `database` (o `redis`) | nunca `sync` en producción |
| `SESSION_DRIVER`, `CACHE_STORE` | `database` (o `redis`) | |
| `FACTURAPI_BASE_URL` | `https://www.facturapi.io/v2` | |
| `FACTURAPI_USER_KEY` | User Key de la cuenta (`sk_user_…`) | ver la sección 7 |
| `FACTURAPI_TEST_KEY` | vacía | sólo la usa `billing:facturapi-link` al vincular |
| `FACTURAPI_TIMEOUT` / `FACTURAPI_CONNECT_TIMEOUT` | 15 / 5 (defaults) | |
| `BILLING_PAC_MODE` | `test` hasta la fase Live | con `live` exige CSD |
| `BILLING_CFDI_FOLIO_CHECK` | `true` (default) | consulta de folio duplicado (6.28) |
| `BILLING_ARTIFACTS_AUTO_DOWNLOAD` | `true` (default) | necesita el worker |
| `BILLING_FACTURAPI_STAMP_TIMEZONE` | `America/Mexico_City` (default) | zona de `stamp.date` de Facturapi |

Frontend (`app-front/.env`):

| Variable | Valor |
|---|---|
| `NEXT_PUBLIC_API_URL` | URL pública de la API |
| `NEXT_PUBLIC_SHOW_DEMO_SCREENS` | `false` (default): oculta las pantallas de maqueta |

## 3. Primer despliegue

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate            # sólo la primera vez; ver la sección 6
php artisan migrate --force
php artisan sat:import-catalogs     # catálogos SAT (c_ClaveProdServ, c_ClaveUnidad); en cada ambiente nuevo
php artisan config:cache && php artisan route:cache
php artisan db:timezone-check       # debe terminar en OK
```

Después, por cada empresa:

1. Crear su organización en Facturapi (sólo el operador):
   `php artisan billing:facturapi-create-organization --company=<id> --apply`
   (sin `--apply` sólo muestra lo que haría; no es idempotente: si se
   interrumpe, `--resume`).
2. O vincular una existente: `php artisan billing:facturapi-link --company=<id> --apply`
   (`--replace-key` para cambiar la llave Test guardada).
3. Capturar Datos fiscales y subir el CSD (y el logotipo) desde la
   aplicación.
4. Verificar: `php artisan billing:pac-check --company=<id>` (sólo lectura)
   y `php artisan billing:health`.

## 4. Zona horaria de la base de datos

- **Un servidor nuevo arranca con `DB_TIMEZONE=+00:00`** (el default). Ahí la
  migración `normalize_timestamps_to_utc` no hace nada: no hay fechas
  guardadas con otra zona.
- **Un servidor con datos guardados antes de la 6.29** (sesión en la zona del
  servidor) necesita esa migración, en este orden:
  1. `php artisan down` y detener el worker (ninguna escritura durante el
     cambio);
  2. respaldo de la base (`mysqldump --single-transaction`);
  3. desplegar el código (que ya trae `DB_TIMEZONE=+00:00`);
  4. `php artisan migrate --force` en **el mismo servidor** donde se
     escribieron los datos (lee con la zona de ese servidor);
  5. `php artisan db:timezone-check` debe terminar en OK;
  6. `php artisan up` y reiniciar el worker.
- Una escritura hecha con la sesión ya en UTC **antes** de migrar quedaría
  corrida: por eso el modo mantenimiento.
- `db:timezone-check` falla si la sesión no está en UTC o si alguna fecha
  reciente aparece en el futuro (síntoma de datos sin migrar).

## 5. Worker de colas (supervisor)

La descarga automática de XML/PDF tras el timbrado
(`DownloadInvoiceArtifactsJob`) corre en la cola. Sin worker los jobs se
acumulan en la tabla `jobs`; el botón "Obtener XML/PDF" sigue funcionando.

```ini
[program:facturapro-worker]
command=php /ruta/a/api-v1/artisan queue:work --sleep=3 --tries=3 --max-time=3600
directory=/ruta/a/api-v1
user=www-data
numprocs=1
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=60
redirect_stderr=true
stdout_logfile=/var/log/facturapro-worker.log
```

- `stopwaitsecs` debe ser mayor que lo que tarda un job (dos descargas con
  `FACTURAPI_TIMEOUT` de 15 s) para no cortarlo a la mitad.
- `DB_QUEUE_RETRY_AFTER` (90 s por default) debe ser mayor que ese tiempo.
- Después de cada despliegue: `php artisan queue:restart`.
- No hay tareas programadas (`schedule:run` no es necesario por ahora).

## 6. `APP_KEY` y `APP_PREVIOUS_KEYS`

`APP_KEY` cifra las llaves Test de Facturapi de cada organización, las
respuestas del PAC (`pac_response`, `pac_draft_response`) y el contexto del
historial fiscal. Si se pierde, esos datos no se pueden leer.

- Respáldala fuera del servidor y **separada** del respaldo de la base: con
  las dos juntas se pueden leer las llaves de Facturapi.
- Rotación: pon la llave actual en `APP_PREVIOUS_KEYS`, genera una nueva en
  `APP_KEY`, corre `php artisan security:reencrypt --apply` y, cuando
  termine, vacía `APP_PREVIOUS_KEYS`.
- Si una llave Test se expuso: `billing:facturapi-link --replace-key --apply`.
- Detalle en `BILLING_LIFECYCLE.md`, "Llaves cifradas con APP_KEY".

## 7. Dónde vive la User Key de Facturapi

- Sólo en `api-v1/.env` del servidor (`FACTURAPI_USER_KEY`). Nunca en la base
  de datos, en el repositorio, en el frontend ni en los logs.
- Abre **todas** las organizaciones de la cuenta: trátala como la credencial
  más sensible del sistema. La usan sólo Datos fiscales, CSD, logotipo y los
  comandos del operador.
- Si el `.env` se filtra, revócala y genera otra en el panel de Facturapi.
- Las llaves Test de cada organización viven cifradas en
  `facturapi_organizations` (sección 6).

## 8. Respaldos

| Qué | Dónde | Notas |
|---|---|---|
| Base de datos | `mysqldump --single-transaction` | diario |
| Archivos fiscales | `api-v1/storage/app/private/` | XML, PDF, acuses y logotipos; no se pueden regenerar sin el PAC |
| `.env` (con `APP_KEY` y la User Key) | gestor de secretos | **separado** de los dos anteriores |

Probar una restauración completa (base + archivos + `.env`) antes de operar
con datos reales.

## 9. Verificación después de cada despliegue

```bash
php artisan migrate:status          # sin migraciones pendientes
php artisan db:timezone-check       # OK
php artisan billing:health          # sin hallazgos invalid nuevos
php artisan billing:pac-check --company=<id>
php artisan queue:restart
```

Y en `laravel.log`, revisar `billing.pac.guard_rejected` (configuración del
PAC rechazada: código interno, empresa y factura).
