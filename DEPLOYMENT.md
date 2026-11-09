# Despliegue de FacturaPro

Checklist para preparar un servidor de pre-producción o producción. Cada
punto remite a la documentación de detalle (`BILLING_LIFECYCLE.md`) cuando
existe. Fase 6.29; el modo Live (sección 10) es de la Fase 6.31.

## 1. Requisitos

| Componente | Versión |
|---|---|
| PHP | 8.2 o superior, con `pdo_mysql`, `openssl`, `mbstring`, `fileinfo`; **`zend.exception_ignore_args=On`** en `php.ini` |
| Laravel | 12 (lo fija `composer.json`) |
| Base de datos | **MySQL 8.0 o MariaDB 10.6 o superior** (recomendado) |
| Node.js | el que pida `app-front/package.json` (Next.js) |

Sobre PHP: con `zend.exception_ignore_args=Off` las trazas de `laravel.log`
incluyen los argumentos de cada llamada. Los parámetros que reciben llaves
llevan `#[SensitiveParameter]` (Fase 6.31), pero en producción se apagan
los argumentos de todas las trazas. `php -i | grep exception_ignore_args`
debe mostrar `On`.

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
| `MAIL_MAILER` | `smtp` | Fase 6.32: correo transaccional (enlaces de recuperación de contraseña). **Nunca `log`**: escribiría el enlace con su token en `laravel.log`; `mail:check` falla en producción con `log` o `array` |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME` | los del proveedor | el proveedor se elige en P1; SMTP genérico, sin paquetes de proveedor |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | credenciales SMTP del proveedor | secreto: sólo en `.env`; `mail:check` nunca las imprime |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | remitente propio verificado (SPF/DKIM) | no el de ejemplo |
| `FRONTEND_URL` | URL pública https de Next.js | además de CORS, base de los enlaces de recuperación (`/restablecer-contrasena#…`) |
| `SESSION_DRIVER` | **`database`** (requisito) | al cambiar o restablecer una contraseña se cierran las demás sesiones web borrando sus filas de `sessions` (Fase 6.31); con otro driver (`redis`, `file`, `cookie`) eso **no ocurre**: sólo se revoca el `api_token` |
| `CACHE_STORE` | `database` (o `redis`) | |
| `FACTURAPI_BASE_URL` | `https://www.facturapi.io/v2` | |
| `FACTURAPI_USER_KEY` | User Key de la cuenta (`sk_user_…`) | ver la sección 7 |
| `FACTURAPI_TEST_KEY` | vacía | sólo la usa `billing:facturapi-link` al vincular |
| `FACTURAPI_TIMEOUT` / `FACTURAPI_CONNECT_TIMEOUT` | 15 / 5 (defaults) | |
| `BILLING_PAC_MODE` | `test` (paro de emergencia) o `live` | techo del sistema, no el modo de cada empresa; cambiarlo a `live` va en la ventana de mantenimiento de P3 (sección 10) |
| `BILLING_CFDI_FOLIO_CHECK` | `true` (default) | consulta de folio duplicado (6.28) |
| `BILLING_ARTIFACTS_AUTO_DOWNLOAD` | `true` (default) | necesita el worker |
| `BILLING_EMAIL_COMPANY_DAILY_LIMIT` | 100 (default) | envíos de facturas por correo por empresa en 24 h (6.30) |
| `BILLING_FACTURAPI_STAMP_TIMEZONE` | `America/Mexico_City` (default) | zona de `stamp.date` de Facturapi y de los días hábiles de `billing:health` |
| `BILLING_HEALTH_CANCELLATION_RECEIVER_BUSINESS_DAYS` / `BILLING_HEALTH_CANCELLATION_MARGIN_HOURS` | 3 / 24 (defaults) | plazo del receptor en `billing:health` (6.31; sustituyen a `BILLING_HEALTH_CANCELLATION_PENDING_HOURS`) |

La llave Live **nunca** va en `.env`: se captura con
`billing:facturapi-live-key` (sección 10). No hay `FACTURAPI_LIVE_KEY`.

Frontend (`app-front/.env`):

| Variable | Valor |
|---|---|
| `NEXT_PUBLIC_API_URL` | URL pública de la API |
| `NEXT_PUBLIC_SHOW_DEMO_SCREENS` | `false` (default): oculta las pantallas de maqueta |

### Correo en desarrollo (Fase 6.32)

Mailpit captura los correos sin entregarlos a nadie. Por ejemplo, con Docker y
sólo en la interfaz local:

```bash
docker run -d --name mailpit -p 127.0.0.1:1025:1025 -p 127.0.0.1:8025:8025 axllent/mailpit
php artisan mail:check --connect     # MAIL_MAILER=smtp, 127.0.0.1:1025
```

Los correos se ven en http://127.0.0.1:8025. El envío va en cola: necesita el
worker (`php artisan queue:work`).

### Reglas de contraseña y contraseñas filtradas (Fase 6.32)

Con `APP_ENV=production` toda contraseña nueva (cambio propio, recuperación,
contraseña temporal y cambio por un Admin) exige al menos 12 caracteres,
mayúsculas y minúsculas, números, símbolos y que **no aparezca en
filtraciones conocidas**. Fuera de producción sólo se piden 8 caracteres.

La última regla consulta `https://api.pwnedpasswords.com` (`PwnedPasswordVerifier`):

- **Qué sale del servidor:** sólo los 5 primeros caracteres del SHA-1 de la
  contraseña (k-anonimato); nunca la contraseña ni su hash completo.
- **Cuándo:** sólo si la contraseña ya cumplió las demás reglas.
- **Espera máxima:** 3 s para conectar y 3 s en total.
- **Si el servicio no responde, tarda más o responde un error:** la
  contraseña **se acepta** (falla abierto) y queda en `laravel.log` el evento
  `password_breach_check.unavailable` (warning) con `reason` (`connection` o
  `http_status`), `status` o la clase de la excepción, `timeout_seconds` y
  `outcome=accepted_fail_open`. No lleva la contraseña, el hash, el prefijo
  ni la URL.

El servidor necesita salida HTTPS hacia `api.pwnedpasswords.com`. Si ese
evento aparece seguido, revisar la red: mientras tanto se aceptan
contraseñas sin esa comprobación.

La contraseña temporal que genera el sistema al restablecer la de un usuario
cumple las mismas reglas; se intenta hasta 5 veces y, si ninguna cumple,
la API responde `503 TEMPORARY_PASSWORD_UNAVAILABLE` sin cambiar nada.

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
(`DownloadInvoiceArtifactsJob`) y el envío automático por correo
(`SendInvoiceEmailJob`, si la empresa lo activa) corren en la cola. Sin
worker los jobs se acumulan en la tabla `jobs`; los botones "Obtener
XML/PDF" y "Enviar por correo" siguen funcionando.

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
php artisan tinker --execute="echo config('session.driver');"   # database
php artisan mail:check --connect     # correo transaccional OK, sin enviar nada
php artisan billing:health          # sin hallazgos invalid nuevos
php artisan billing:pac-check --company=<id>
php artisan queue:restart
```

Y en `laravel.log`, revisar `billing.pac.guard_rejected` (configuración del
PAC rechazada: código interno, empresa y factura) y
`password_breach_check.unavailable` (la comprobación de contraseñas filtradas
no respondió y se aceptó la contraseña; ver la sección 2).

Si `session.driver` no es `database`, corregir `SESSION_DRIVER` en `.env`,
`php artisan config:cache` y repetir la verificación: con otro driver un
cambio de contraseña no cierra las demás sesiones web.

## 10. Modo Live con una empresa piloto (Fase 6.31)

### Pendiente antes del piloto

El código del modo Live está listo y probado con HTTP simulado, pero **el
piloto no puede empezar** hasta tener:

1. una **empresa piloto con CSD vigente** (no la empresa 1, que es sólo de
   desarrollo);
2. su **contador**, que define los casos reales de emisión y cancelación;
3. un **servidor con `APP_ENV=production`** (Live se niega en cualquier otro
   `APP_ENV`, también en `local` y `testing`);
4. la **cuenta de Facturapi con suscripción activa** y el costo por timbre
   confirmado.

Hasta entonces `BILLING_PAC_MODE` se queda en `test`, ninguna empresa
cambia a Live y no se crea ni se importa ninguna llave Live.

### Cómo funciona

- Cada empresa tiene su ambiente (`test` o `live`) y cada factura queda
  fijada en el ambiente de su primera reserva. Detalle en
  `BILLING_LIFECYCLE.md`, "Modo Live con una empresa piloto".
- `BILLING_PAC_MODE` es el techo: con `live` cada empresa opera en su
  ambiente; con `test` (o vacío/desconocido) **ninguna empresa Live opera**:
  es el paro de emergencia. No usa la llave Test como respaldo: las
  operaciones responden `PAC_CONFIGURATION_INVALID` sin contactar al PAC y
  sin marcar ninguna factura. Al reactivar, XML/PDF y reconciliación se
  recuperan; las cancelaciones pendientes se reconcilian entonces.
- Para activar o quitar el paro: cambiar `BILLING_PAC_MODE`,
  `php artisan config:cache` y `php artisan queue:restart`.

### Procedimiento del piloto (cada paso con autorización explícita)

**P1. Preparar la empresa piloto**

- Carta Manifiesto firmada en Facturapi.
- Suscripción activa y costo por timbre confirmado.
- Datos fiscales capturados desde la aplicación (incluido el correo de
  contacto: es el "Responder" de los correos al cliente), CSD vigente y
  logotipo.
- `auto_email_invoices` **apagado** para la empresa piloto.
- `billing:pac-check --company=<id>` sin fallos en Test.

**P2. Llave Live**

- Crearla en el panel de Facturapi. Según la documentación actual
  (`PUT /organizations/{id}/apikeys/live`, User Key) crea una llave sin
  invalidar las anteriores; un comentario del SDK dice lo contrario: **la
  rotación no está verificada**.
- `php artisan billing:facturapi-live-key --company=<id>` (dry-run) y luego
  con `--apply`. La llave se pega en el prompt oculto; nunca va en argumentos,
  `.env` ni historial. Si `GET /organizations/me` no la acepta, no se guarda
  nada (la alternativa por `first_12` requiere aprobación).

**P3. Cambiar la empresa a Live (en una ventana de mantenimiento)**

- El cambio se hace en una ventana de mantenimiento acordada: avisar a los
  usuarios, dejar que la cola termine (`php artisan queue:work
  --stop-when-empty`; `billing:pac-environment` se niega con jobs de la
  empresa en la cola), `php artisan down` y detener el worker. Al terminar,
  `php artisan up` y reiniciar el worker. Ninguna factura de la empresa
  debe estar a medio emitir o cancelar (el comando también lo revisa).
- Antes: respaldo de la base de datos **restaurado y comprobado** en otro
  servidor; worker supervisado (sección 5) y la cola vacía;
  `zend.exception_ignore_args=On`; `php artisan db:timezone-check` en OK;
  `php artisan sat:import-catalogs` ejecutado; Carta Manifiesto, suscripción
  y `auto_email_invoices` apagado (P1) confirmados.
- `BILLING_PAC_MODE=live`, `config:cache`, `queue:restart`.
- `php artisan billing:pac-environment --company=<id> --set=live` (revisión)
  y luego con `--apply` y el RFC escrito.

**P4. Antes de la primera emisión**

El contador revisa la Constancia de Situación Fiscal del cliente (RFC,
nombre exacto, régimen y C.P.): el SAT valida el nombre al timbrar.

**P5. Primer CFDI**

Total de hasta $1,000, con una operación real que defina el contador.

**P6. Revisar la emisión**

XML (`Serie="FAC"` y el folio del ERP), PDF, descarga automática,
`billing:health` y `billing:pac-check`. El correo sólo de forma manual
(`auto_email_invoices` sigue apagado).

**P7. Primera cancelación (verificación obligatoria del acuse)**

- El caso y el motivo (02 o 03; en Live no hay otros) los define el contador.
- Sin aceptación del receptor: total de hasta $1,000 o dentro de las 24 horas
  de la emisión. Con aceptación: el receptor tiene tres días hábiles; en la
  primera solicitud el silencio cancela, tras un rechazo el silencio en
  solicitudes posteriores deja el CFDI vigente. (Reglas dadas por la
  revisión de la fase; contrastarlas con la RMF vigente.)
- **Un responsable nombrado reconcilia la factura hasta su estado final.**
- El acuse se acepta sólo si `Acuse/Folios/UUID` es el del CFDI y su
  `EstatusUUID` es 201 o 202. Si se bloquea:
  `php artisan billing:cancellation-receipt-inspect <invoice>` (solo
  lectura) y revisión manual. No se relaja la validación.

### Respuesta a incidentes

- **Llave Live expuesta**: revocarla en el panel de Facturapi, crear otra e
  importarla con `billing:facturapi-live-key`; si se expuso con la base de
  datos y el `.env`, rotar `APP_KEY` (sección 6). Una llave Live también
  puede **cambiar los datos fiscales y borrar o reemplazar el CSD** de la
  organización: revisar `billing:pac-check` y los datos fiscales en
  Facturapi después de un incidente.
- **User Key expuesta**: abre todas las organizaciones de la cuenta y puede
  **crear llaves Live** (la lista cerrada del sistema no lo hace, pero un
  atacante no pasa por el código). Revocarla, revisar la lista de llaves Live
  de cada organización en el panel y revocar las desconocidas.
- **Timbres compartidos**: los timbres de la suscripción se comparten entre
  organizaciones; una llave comprometida consume también los de las demás
  empresas.
- **Detener todo Live de inmediato**: `BILLING_PAC_MODE=test`,
  `config:cache`, `queue:restart` (paro de emergencia).