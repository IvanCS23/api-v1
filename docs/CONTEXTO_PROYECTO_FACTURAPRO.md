# CONTEXTO MAESTRO — FACTURAPRO SaaS

## 1. Rol

Actúa como un ingeniero de software senior y arquitecto especializado en Laravel, PHP, Next.js, React, TypeScript, SQL, sistemas multi-tenant, facturación electrónica mexicana (CFDI), integración con PAC, idempotencia, concurrencia y seguridad de APIs.

Trabaja sobre el código real del repositorio. No inventes archivos, servicios, rutas, contratos ni resultados de pruebas.

## 2. Objetivo del proyecto

Estamos desarrollando un SaaS de facturación electrónica llamado FacturaPro, con frontend Next.js y backend Laravel.

El sistema administra ventas, facturas comerciales, conceptos, snapshots fiscales de clientes, emisión de CFDI, cancelación, reconciliación, descarga de XML/PDF, acuses y trazabilidad.

El ERP y el ciclo fiscal PAC son máquinas de estados separadas. El sistema debe soportar múltiples empresas y mantener aislamiento de tenant, autorización, integridad fiscal, idempotencia y trazabilidad.

## 3. Repositorios

Directorio raíz conocido:

`C:\Users\PC\Desktop\app-frontend\`

Frontend:

`C:\Users\PC\Desktop\app-frontend\app-front`

Backend:

`C:\Users\PC\Desktop\app-frontend\api-v1`

Ramas de trabajo (desde 2026-10-20): todo el trabajo va directo en
`changes-ivan` (api-v1) e `integracion-ivan` (app-front). No se crean ramas
nuevas. Los commits se dejan locales; el push lo decide el responsable del
repositorio.

Frontend:
- Next.js 16.
- React.
- TypeScript.
- Tailwind CSS.
- Servicios HTTP centralizados.
- Autenticación mediante el mecanismo existente.
- Tipos explícitos; no introducir `any` para eludir contratos.

Backend:
- Laravel.
- PHP.
- Eloquent ORM.
- MySQL/MariaDB.
- Form Requests.
- API Resources.
- Policies.
- Scopes y contexto de tenant.
- Servicios de dominio y orquestación PAC.

Verifica las versiones y dependencias reales del workspace antes de modificarlas.

## 4. Arquitectura obligatoria

Flujo:

Next.js → cliente HTTP autenticado → API Laravel → autorización y validación → servicios de dominio → persistencia y, cuando corresponda, proveedor PAC.

El frontend nunca debe comunicarse directamente con Facturapi.

Laravel es la autoridad para validar permisos, transiciones, capacidades e invariantes fiscales.

Los Resources deben exponer únicamente campos explícitamente permitidos.

No exponer `pac_response`, `pac_last_error`, respuestas crudas del PAC, rutas privadas de artifacts, credenciales ni datos internos de diagnóstico.

No introducir secretos en el frontend ni nuevas variables `NEXT_PUBLIC` para credenciales PAC.

## 5. Máquinas de estados

ERP:

`draft → ready → issued`

Ruta alternativa:

`draft/ready → cancelled`

Estados definidos por `App\Enums\InvoiceStatus`:
- `draft`
- `ready`
- `issued`
- `cancelled`

PAC: utiliza los campos y servicios existentes para representar emisión, cancelación, borradores remotos, identidad CFDI, reconciliación y artifacts.

Campos relevantes:
- `pac_status`
- `pac_issue_status`
- `cancellation_status`
- `pac_reconciliation_required`
- `pac_external_id`
- `cfdi_uuid`
- `stamped_at`
- `pac_draft_*`

No asumas que `Invoice.status=issued` significa que existe un CFDI válido.

Las clasificaciones de salud definidas en la fase anterior incluyen:
- `VALID`
- `TRANSIENT`
- `RECOVERABLE`
- `INVALID`

Primero localiza el clasificador y las reglas reales implementadas en FASE 6.16. Reutilízalos y no crees un segundo sistema contradictorio.

## 6. Endpoints y semántica

ERP:
- `POST /api/invoices/{id}/ready`: transición ERP a ready.
- `POST /api/invoices/{id}/issue`: endpoint histórico ERP-only, deprecado.
- `POST /api/invoices/{id}/cancel`: endpoint histórico ERP-only, deprecado.

API empresarial recomendada:
- `POST /api/invoices/{invoice}/operations/issue`
- `POST /api/invoices/{invoice}/operations/cancel`

Primitivas PAC avanzadas:
- `POST /api/invoices/{invoice}/pac/issue`
- `POST /api/invoices/{invoice}/pac/cancel`
- `POST /api/invoices/{invoice}/pac/reconcile`
- `POST /api/invoices/{invoice}/pac/artifacts`
- `POST /api/invoices/{invoice}/pac/cancellation-receipt`

Lectura y descargas:
- `GET /api/invoices`
- `GET /api/invoices/{invoice}`
- `GET /api/invoices/{invoice}/billing`
- Endpoints privados de XML/PDF y acuses existentes.

Comprueba las rutas reales con `php artisan route:list --path=invoices`. Los nombres anteriores son el contrato documentado y deben verificarse contra el código actual.

No elimines endpoints legacy ni cambies su semántica sin una fase y autorización explícitas.

## 7. Funcionalidades ya implementadas

FASE 6.12:
- Panel fiscal.
- Estados fiscales y UUID.
- Timeline.
- Descargas autenticadas XML/PDF.
- Obtención de artifacts.
- Cancelación, reconciliación y acuse.
- Manejo sanitizado de errores.
- Controles de acceso y confirmaciones.

FASE 6.13:
- Consulta real de facturas.
- Eliminación de datos mock.
- Filtros client-side iniciales.
- Detalle rápido y navegación al panel fiscal.

FASE 6.14:
- `IndexInvoiceRequest`.
- `InvoiceResource` e `InvoiceItemResource` con whitelist.
- Paginación server-side.
- Filtros y ordenamiento validados.
- Optimización de consultas.
- Aislamiento de tenant.
- Pruebas de seguridad y contrato.

FASE 6.15:
- Detalle comercial integrado con el panel fiscal.
- Conceptos y snapshot fiscal.
- Edición de notas.
- Transición a ready.
- Eliminación controlada de borradores.
- Separación de estado comercial y fiscal.

FASE 6.16:
- Endpoints empresariales de emisión y cancelación.
- Capabilities calculadas en backend.
- Invariantes ERP/PAC.
- Idempotencia y control de concurrencia.
- Reconciliación de resultados ambiguos.
- Cancelación fiscal y convergencia ERP.
- Protección contra sustitución de UUID.

FASE 6.17 (reportada, **no presente en el código**):
- Se reportó la deprecación de endpoints ERP legacy (headers `Deprecation: true`, successor links, pruebas que prohíben su consumo).
- Verificado al cierre de la 6.22: no existen esos headers, links ni pruebas, y `POST /api/invoices/{id}/issue` y `/cancel` siguen sin marcar. Se rehace en la FASE 6.26.

FASE 6.18 — Observabilidad y salud del ciclo de facturación:
- `InvoiceHealthEvaluator` y comando read-only `php artisan billing:health` (clasificación `valid`/`transient`/`recoverable`/`invalid`, `--company`, `--invoice`, `--status` repetible, `--format`, `--fail-on`).
- Sin escrituras ni llamadas al PAC; la salida nunca expone payloads, errores crudos, llaves ni identidad fiscal.
- Commit api-v1: `75f51a7`.

FASE 6.19 — Seguridad de escrituras PAC:
- Guard de modo/llave (`billing.pac_mode=test` exige `sk_test_`; `live` rechazado) antes de cualquier HTTP.
- Política de reintentos por operación (solo errores de conexión en lecturas e idempotentes).
- Reserva de la cancelación bajo lock (`pac_cancel_request_status`, migración `add_pac_cancel_control`).
- `pac_external_id` enmascarado en logs; códigos explícitos en `/operations/cancel` con reserva abierta.
- Commits api-v1: `c743aaa`, `4d93dd5`, `04b4da4`, `3ff6fe4`, `1b1f2c7`. app-front: `633a11e`.

FASE 6.20 — Correcciones fiscales:
- `stamp.date` llega sin offset en hora del centro de México: se interpreta con `billing.facturapi.stamp_timezone` y se guarda en UTC (antes `stamped_at` quedaba 6 h atrasado).
- Errores de Facturapi mapeados por `body.code` antes que por HTTP status: `invoice_not_found` definitivo; `stamping_in_progress`, `invoice_cancellation_in_progress`, `draft_update_in_progress` e `idempotency_key_in_use` ambiguos.
- Recuperación de un borrador reservado sin id remoto por `draftExternalId` + `idempotency_key`.
- Mensaje específico para `PAC_AUTHENTICATION_FAILED`; el panel recarga la factura completa tras cada acción fiscal.
- Commits api-v1: `be2874a` (este documento), `9543a51`, `1ee53de`, `ba54523`. app-front: `58f0a99`, `3983a5a`.

FASE 6.21 — Expediente fiscal (XML/PDF):
- Descarga automática del XML/PDF en cola tras el timbrado (`InvoiceIssued` → `DownloadInvoiceArtifactsJob`); un fallo nunca altera la emisión. Requiere worker (`composer dev` o `php artisan queue:work`).
- XML/PDF obtenibles también de CFDI cancelados (verificado en Facturapi TEST).
- Capability `can_fetch_artifacts`; `can_reconcile` acotado al borrador `valid` sin vincular; salida por antigüedad de la descarga (`cfdi_artifacts_started_at`).
- Documentado el acuse de TEST (UUID ajeno, EstatusUUID 205) y la verificación obligatoria en Live.
- Commits api-v1: `8c8e58d`, `98852f2`, `b2dca04`, `86e948e`, `150d49b`, `b3ea7db`. app-front: `2fce56a`.

FASE 6.22 — Coherencia de reconciliación y acuse:
- La revisión fiscal (`pac_reconciliation_required`) ya no depende de los artifacts; corrige un 409 en `operations/issue` por la ruta del borrador `valid` con el CFDI ya válido.
- `billing:health`: hallazgos del acuse (bloqueo de identidad informativo en test y `recoverable` en live) y de su descarga en `pending`.
- Salida por antigüedad del acuse en `pending` (`cancellation_receipt_started_at`).
- `pac.mode` en `GET /billing` (sin la llave) y capability `can_fetch_cancellation_receipt` (límite de bloqueos de identidad: 1 en test, 3 en live); el panel explica el acuse de prueba.
- Commits api-v1: `0121198`, `17ff278`, `e2d1bcb`, `f778619`. app-front: `9ef9aec`.

Documentación principal:

`api-v1/BILLING_LIFECYCLE.md`

## 8. Estado de pruebas y línea base

Línea base al cierre de la FASE 6.22 (verificada el 2026-10-22 sobre
`changes-ivan` e `integracion-ivan`, sincronizadas con `origin`):
- Backend: 1208 pruebas aprobadas, 5030 assertions, 0 fallidas.
- Frontend: 92 pruebas aprobadas en 8 archivos, 0 fallidas.
- TypeScript (`tsc --noEmit`): aprobado.
- Build Next.js: aprobado, 31 páginas generadas.
- Pint y ESLint de archivos relacionados: aprobados.
- `git diff --check`: limpio.

Ejecuta las suites de nuevo antes de afirmar que el workspace actual está
validado. (Histórico: FASE 6.17 reportó 937 backend / 81 frontend.)

Estado de la BD local de desarrollo:
- Company 1 tiene `default_payment_form='03'` y `default_payment_method='PUE'` escritos manualmente (antes null); no hay endpoint para configurarlos.
- Migraciones de la 6.21 y 6.22 (`cfdi_artifacts_started_at`, `cancellation_receipt_started_at`) aplicadas.

Deuda conocida:
- Lint global frontend con errores y warnings anteriores.
- Vulnerabilidades de dependencias reportadas, pendientes de nueva auditoría.
- Búsqueda parcial `LIKE`.
- Índices de consulta pendientes de evaluación con datos reales.
- No existe endpoint real de envío por correo.
- Debe revisarse el diff del lockfile.
- Los endpoints legacy siguen disponibles por compatibilidad.

Hallazgos de producto pendientes (verificados en el código):
- Creación de facturas simulada: `/pages/invoices/invoice` es una maqueta; "enviar factura" y el envío por correo son `setTimeout` que muestran "¡Factura Generada!" sin llamar a la API.
- Enlace "Nueva venta" roto: `MenuItems.tsx` y `Navbar.tsx` apuntan a `/pages/invoices/nueva-venta`, que no existe (`app/pages/invoices/nueva/` está vacío).
- Forma y método de pago: `SaleToInvoiceConverter` copia siempre los predeterminados de la empresa; deben capturarse por venta y usar el de la empresa solo como valor por omisión.
- Sin validación SAT al capturar: clientes validan `rfc` solo con `max:13` y `regimen_fiscal`, `uso_cfdi` y `codigo_postal` como texto libre; productos no validan `clave_unidad` ni `objeto_imp` contra catálogo. Los errores llegan hasta el PAC. Además, `clave_producto` es única por empresa, lo que impide dos productos con la misma clave SAT.

Riesgos pendientes:
- Zona horaria de la conexión MySQL: la sesión usa `SYSTEM` (America/Mexico_City); `NOW()` en SQL da hora local y fijar `+00:00` desplazaría las fechas guardadas. Requiere fase propia con migración de datos (documentado en `BILLING_LIFECYCLE.md`).
- Organización Facturapi por empresa: hay una sola llave global (`services.facturapi.test_key`); todas las empresas timbran en la misma organización.
- Facturas 1-3 huérfanas: pertenecen a una organización Facturapi sin acceso con la llave actual (`invoice_not_found`); no se pueden reconciliar ni descargar.
- Acuse de cancelación solo verificable en Live: en TEST siempre trae un UUID ajeno (EstatusUUID 205) y se bloquea por diseño. Verificación obligatoria con la primera cancelación real en Live; no relajar la validación.
- Login con sesión única: `POST /api/login` reemplaza el `api_token` del usuario e invalida cualquier otra sesión abierta.

## 9. Reglas de trabajo

Antes de escribir código:

1. Inspecciona ambos repositorios y sus estados Git.
2. Lee `BILLING_LIFECYCLE.md`.
3. Revisa las implementaciones existentes antes de crear nuevos servicios.
4. Localiza las reglas de estados, las Policies, los scopes y los servicios escritores.
5. Identifica cambios anteriores sin commit y no los sobrescribas.
6. Define el alcance, los archivos que probablemente se modificarán y las pruebas necesarias.
7. Identifica riesgos de seguridad, concurrencia y efectos secundarios.

Durante el desarrollo:
- Trabaja únicamente dentro del alcance aprobado.
- Reutiliza contratos y servicios existentes.
- No elimines código previo por conveniencia.
- No cambies `.env` ni credenciales.
- No agregues dependencias sin justificación.
- No uses `withoutGlobalScopes()` para eludir aislamiento.
- No registres tokens ni respuestas sensibles.
- No realices operaciones fiscales reales sin autorización explícita.
- No introduzcas escrituras en herramientas de diagnóstico.
- No afirmes que ejecutaste una prueba si no la ejecutaste.

Al terminar:
- Ejecuta pruebas específicas y suites afectadas.
- Ejecuta typecheck, lint y build cuando correspondan.
- Ejecuta `git diff --check`.
- Revisa el diff de ambos repositorios.
- Documenta los archivos creados y modificados.
- Reporta pruebas aprobadas y fallidas con cifras reales.
- Enumera riesgos pendientes y cambios no comprometidos.
- No avances a la fase siguiente automáticamente.

## 10. Punto exacto de continuidad

La última fase completada y verificada es la FASE 6.22 (ver sección 7).

Hoja de ruta:
- FASE 6.23 — Inventario de pantallas: clasificar cada página de `app-front` como real, maqueta o rota (incluye los hallazgos de producto de la sección 8) y proponer qué conservar, conectar o retirar. Solo diagnóstico.
- FASE 6.24 — Flujo venta → factura: captura real de la venta y su factura desde la UI, forma y método de pago por venta y validación SAT al capturar.
- FASE 6.25 — Organización Facturapi por empresa: llave/organización por empresa en lugar de la llave global, y tratamiento de las facturas 1-3.
- FASE 6.26 — Deprecación de endpoints legacy: rehacer lo reportado en la 6.17 (headers `Deprecation`, successor links y pruebas).

## 11. Siguiente tarea

Prepara la FASE 6.23 antes de modificar archivos:

1. Confirma el estado real de ambos repositorios y la línea base de la sección 8.
2. Recorre las rutas de `app-front/app` y clasifica cada pantalla (real, maqueta, rota) con evidencia del código.
3. Identifica qué endpoints consume cada pantalla real y cuáles simulan resultados.
4. Propón alcance, archivos, pruebas, riesgos y orden de commits.

No inventes el estado actual del workspace. Si falta información, inspecciona el código y explica qué no pudo verificarse.
La meta es continuar desde el trabajo real existente, preservar las decisiones anteriores y avanzar de forma incremental y verificable.