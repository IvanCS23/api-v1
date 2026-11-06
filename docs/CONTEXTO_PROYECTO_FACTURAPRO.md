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
- Verificado al cierre de la 6.22: no existen esos headers, links ni pruebas, y `POST /api/invoices/{id}/issue` y `/cancel` siguen sin marcar. Pasó a "Después del lanzamiento" en la hoja de ruta del 2026-10-30 (sección 10); la 6.26 fue la validación SAT. Desde la 6.31 esos endpoints responden 409 para facturas Live.

FASE 6.18 — Observabilidad y salud del ciclo de facturación:
- `InvoiceHealthEvaluator` y comando read-only `php artisan billing:health` (clasificación `valid`/`transient`/`recoverable`/`invalid`, `--company`, `--invoice`, `--status` repetible, `--format`, `--fail-on`).
- Sin escrituras ni llamadas al PAC; la salida nunca expone payloads, errores crudos, llaves ni identidad fiscal.
- Commit api-v1: `75f51a7`.

FASE 6.19 — Seguridad de escrituras PAC:
- Guard de modo/llave (`billing.pac_mode=test` exige `sk_test_`; `live` rechazado hasta la 6.31, que lo habilita por empresa) antes de cualquier HTTP.
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

FASE 6.23 — Inventario del frontend (solo lectura):
- Clasificó las 27 páginas de `app/pages`: 7 reales, el resto maquetas; 16 mostraban un éxito sin hacer nada real; 24 enlaces apuntaban a rutas inexistentes; ninguna pantalla creaba ventas ni facturas.
- Derivó dos correcciones de seguridad: `console.log` con contraseñas del CSD/FIEL y datos de clientes y productos. Commits app-front: `a2b351f`, `06c9f87`.

FASE 6.24 — Honestidad de la interfaz:
- Regla ESLint `no-console` (sólo `warn`/`error`) en `app/**`, `src/**` y `lib/**`; `.agents/**` (scripts de herramientas) fuera del lint global.
- `src/data/screenStatus.ts`: registro único `real` / `demo` / `unavailable` de cada ruta. Menú, Navbar, Dashboard y Configuración lo usan: los destinos sin página se muestran como "Próximamente", sin enlace.
- `NEXT_PUBLIC_SHOW_DEMO_SCREENS` (por omisión `false`, documentada en `app-front/.env.example`): con `false` las maquetas no aparecen en la navegación; con `true` aparecen con la etiqueta "Demo". Entrando por URL siempre muestran el aviso de demostración.
- Maquetas fiscales (factura, facturación masiva, carga XML, descargas, recuperación, reportes, sellos): aviso y acción final deshabilitada. Demás maquetas: solo lectura (`<fieldset disabled>`). Ninguna simula ya un éxito. `settings/users` (maqueta huérfana) eliminada.
- Pantallas reales: "Facturas emitidas" de Configuración cuenta facturas reales (`GET /api/invoices?status=issued`, `meta.total`) en lugar de `/api/legacy-invoices`; aviso de datos de ejemplo en el Dashboard; `Toast` con variante `info`; Productos sin la columna "Unidad" duplicada.
- Commits app-front: `052c8d2`, `9516731`, `e5a774b`, `ba4c239`, `97e32f9`, `6fa2276`.

FASE 6.25 — Flujo real venta → factura:
- Forma y método de pago por venta (la empresa sólo como respaldo), con la regla PPD ↔ 99 al guardar y en el readiness.
- `GET /sales/{id}/billing-readiness` en modo previo a facturar (pago, retenciones, clave de unidad; sin exigir confirmación).
- `POST /api/sales/{sale}/invoice`: atómico e idempotente; conserva `draft → pending → confirmed` y deja la factura `ready`. 422 `SALE_NOT_BILLABLE`, 409 `SALE_CANCELLED`, 200 si ya existía.
- Venta cuya factura se borró: unicidad en la columna virtual `active_sale_id`; se puede facturar de nuevo. Los folios de facturas y ventas ya no se repiten tras borrar.
- `GET /api/tax-rates`, `GET /api/sales` paginado y `SaleResource` explícito; las líneas bloquean la venta.
- Pantallas reales `/pages/sales`, `/pages/sales/new` y `/pages/sales/[id]` (responsivas, panel "Antes de facturar", "Facturar" con una sola llamada y redirección a `/pages/invoices/{id}`). "Nueva venta" conectada en menú, Navbar y Dashboard. Maqueta `/pages/invoices/invoice` eliminada.
- Commits api-v1: `c66ab12`, `06637e6`, `3867a3a`, `990f307`. app-front: `39d012d`, `c7e264f`, `b7538f0`, `8370bbd`.

FASE 6.26 — Validación SAT al capturar:
- Catálogos locales desde el archivo oficial del Anexo 20 (`catCFDI_V_4_20261015.xls`, SHA-256 en `database/data/sat/README.md`), convertidos con un script propio repetible (`database/data/sat/tools`). Pequeños en CSV (`SatCatalog`); `c_ClaveProdServ` y `c_ClaveUnidad` en `sat_product_keys` / `sat_unit_keys` (`sat:import-catalogs`). Sin dependencia del PAC.
- `SatFiscalValidator`: RFC por tipo de persona, genéricos con 616/S01, C.P., régimen y uso con la matriz oficial; clave de producto, unidad y objeto de impuesto coherente con el IVA. Form Requests validan la combinación final al editar.
- `clave_producto` ya no es única por empresa. IVA del producto como `tax_rate_id` (16 %, 0 %, exento); el PAC recibe `factor: "Exento"` en líneas exentas (antes, IVA tasa 0 %).
- Ventas con el catálogo `c_FormaPago` completo; readiness con códigos de valores inválidos (`*_INVALID`, `CLIENT_REGIME_USE_INCOMPATIBLE`, `ITEM_TAX_OBJECT_MISMATCH`).
- `sat:audit-catalog-data` (solo lectura) y `fiscal_issues` en las APIs de clientes y productos.
- Frontend: Clientes con régimen/uso filtrados, validación espejo, aviso de datos inválidos y tarjetas responsivas; Productos con búsqueda de claves (`SatKeySearch`), objeto 02 por omisión e IVA como tasa; Ventas con forma de pago del catálogo y mensajes nuevos.
- Commits api-v1: `5409a98`, `1c527d9`, `6d4c6ac`, `d062f69`, `add0f03`. app-front: `fe25bb2`, `aeffc06`, `ad60844`.

FASE 6.26.1 — Funciones simuladas dentro de pantallas reales:
- `app/components/ui/ComingSoon.tsx`: marca funciones parciales dentro de pantallas reales con el criterio de la 6.24 (deshabilitada, `aria-disabled`, `title`, etiqueta "Próximamente" de la navegación; sección con `<fieldset disabled>` y una nota de lo que el sistema sí hace). Nunca abre una maqueta ni simula un resultado.
- "Carga masiva" de Clientes y Productos: deshabilitada como "Próximamente"; se eliminaron sus modales (seis botones sin acción, sin campo de archivo ni endpoint).
- Productos: IVA RET, IEPS, ISR, cuenta predial, pedimento e impuestos locales se guardaban pero no llegaban a la venta, la factura ni al PAC. Ahora se muestran deshabilitados con su valor guardado, con la nota "Por ahora el sistema solo aplica IVA (16 %, 0 % o Exento)" y, si el producto tiene alguno, un aviso explícito. El frontend ya no los envía; el backend los conserva y sigue aceptándolos (prueba de contrato en `ProductFiscalValidationTest`).
- RFC genérico: un régimen distinto de 616 responde "Con un RFC genérico el régimen fiscal debe ser 616." antes de revisar el tipo de persona (antes, con 601, decía "no aplica a una persona física"). Backend y espejo del frontend.
- Commits api-v1: `8b46fe8`. app-front: `5a876e9`, `20bbbf2`, `22f933f`.

FASE 6.27a — Organización de Facturapi por empresa (sin User Key):
- `facturapi_organizations`: una organización por empresa con su llave Test cifrada (`encrypted`, oculta, sólo se muestra `test_key_hint`) y la copia de sus datos fiscales y CSD. `companies.api_key` eliminado.
- `PacProviderResolver` construye el provider con la llave de la empresa de cada factura; no hay llave global en ejecución. `PacCredentialGuard::forCompany()` agrega `pac_organization_missing`. `.env` (`services.facturapi.test_key`) sólo lo usa `billing:facturapi-link`.
- Identidad por factura: `invoices.pac_organization_id` (llenado al guardar cada respuesta del PAC y con `billing:backfill-pac-organization`). Una factura de otra organización no ofrece acciones y responde `409 PAC_ORGANIZATION_MISMATCH` antes de cualquier HTTP o transición ERP; el provider rechaza respuestas de otra organización.
- `billing:archive-inaccessible-invoices` (todo o nada, con motivo y evento `organization_archived`); `billing:health` reporta `pac_organization_mismatch` (invalid) y `pac_organization_archived` (informativo).
- `billing:pac-check` (solo lectura): organización, guard, identidad remota, RFC/régimen/C.P. contra Facturapi, CSD (ausente: aviso en test, fallo en live) y facturas por organización.
- Frontend: el panel fiscal explica "Factura archivada" / "Factura de otra organización fiscal" y el error `PAC_ORGANIZATION_MISMATCH`.
- Desviación del plan: no hay hallazgo `pac_organization_unknown` en `billing:health`; las facturas sin organización las reporta `pac-check` (`invoices_without_organization`).
- `BILLING_LIFECYCLE.md`: sección "Organización de Facturapi por empresa", rotación de `APP_KEY` con `APP_PREVIOUS_KEYS` y riesgo de respaldo de BD junto con `.env`.
- Aplicado en la BD local el 2026-10-28: migraciones, vínculo de la empresa 1 con `6ad00a7079761a6ddec6026a`, backfill de 11 facturas y archivado de #1–#3 (organización `69e1341a…`).
- Commits api-v1: `95841df`, `4063952`, `1765639`, `ad7258e`, `1f4da67`, `e2821ec`, `d5a10e1` y el de documentación. app-front: `0c564c6`.

FASE 6.27b — Administración de la organización (User Key):
- Seguridad de usuarios (commits 0 y 0b, subidos por separado): crear, editar, desactivar y las acciones de seguridad son sólo para un Admin activo (`UserPolicy`); nadie cambia su propio rol ni estado ni el rol de otro Admin, un Admin no cambia ni restablece la contraseña de otro, y la empresa conserva al menos un Admin activo (`CompanyAdminGuard`, con los Admin bloqueados en la transacción). Desactivar o suspender revoca el `api_token`, y el middleware `active` rechaza con 403 `USER_INACTIVE` a un usuario no activo en las 107 rutas autenticadas (API, web y Fortify).
- `FACTURAPI_USER_KEY` sólo en `.env` (vacía en `phpunit.xml`). `FacturapiOrganizationClient` con lista cerrada (consultar, buscar, crear, `PUT /legal`, `PUT /certificate`, `GET /apikeys/test`); nunca renueva llaves, usa Live ni borra.
- `billing:facturapi-create-organization` (sólo operador, a prueba de un POST no idempotente, con `--resume`) y `billing:facturapi-link --replace-key` (User Key o `.env`).
- Datos fiscales del emisor: `GET/PUT /api/company/fiscal`, `issuerIssues`, regla del RFC del CSD, Facturapi primero; readiness de emisión `COMPANY_FISCAL_DATA_INCOMPLETE`, `COMPANY_RFC_CSD_MISMATCH`, `COMPANY_CSD_MISSING` (fuera de test) y `CLIENT_GENERIC_POSTAL_CODE_MISMATCH` en venta y emisión.
- CSD real: `POST /api/company/csd` con lectura local del `.cer` (RFC, FIEL, vigencia), sin guardar archivos ni contraseña, `#[SensitiveParameter]`, `dontFlash` y límite por empresa y usuario.
- `facturapi_organization_audits` (append-only) con el usuario o `source=console`. `pac-check` verifica la cuenta con la User Key. `security:reencrypt` para retirar una `APP_KEY` anterior (incluye `invoice_pac_events.context`, excepción documentada).
- Frontend: pantallas reales de Datos fiscales y Sellos (FIEL como "Próximamente"), Usuarios oculta las acciones de administración a quien no es Admin y los mensajes en inglés de Laravel se muestran en español.
- Desviación: `CompanyBillingReadinessService::evaluate()` no tenía consumidores (sólo su prueba) y revisa la forma de pago de la empresa, obsoleta desde la 6.25; las reglas del emisor van en `issuerErrors()`, que sí aplica `InvoicePacReadinessService`.
- Commits api-v1: `b300861`, `da2b7e8`, `6e4c29b`, `9cc1c99`, `4b69216`, `82df2fd`, `5053e75`, `99fc7a5`, `cd05607` y el de documentación. app-front: `b57d3d6`, `c7aee8b`, `dc3b1a7`.

FASE 6.28 — Folio del CFDI y método de pago:
- PPD bloqueado hasta tener el complemento de pago (REP): 422 al guardar la venta; `SALE_PPD_NOT_AVAILABLE`, `INVOICE_PPD_NOT_AVAILABLE` y `COMPANY_PPD_NOT_AVAILABLE` en los readiness; el selector lo muestra deshabilitado ("próximamente") con la explicación. Constante en `SalePaymentRules`, no configuración. No había ventas ni facturas PPD en la BD local.
- El CFDI lleva la serie y el folio del ERP (`FAC-00000019` → Serie `FAC`, Folio 19, `InvoiceFolio`), al crear y al actualizar el borrador. `INVOICE_FOLIO_INVALID` en el readiness y en el provider; nunca se omiten. `default_cfdi_series` sigue sin uso (decisión: la serie sale del folio interno).
- Consulta de duplicados (`GET /invoices?series&folio_number`, `CfdiFolioAvailability`) en la emisión empresarial antes de la transición ERP a `issued`, y en `/pac/issue` antes de crear o actualizar el borrador: un comprobante ajeno con el mismo folio responde `409 PAC_FOLIO_ALREADY_USED` y la factura queda en `ready`, sin cambios. `billing.cfdi_folio_check` (encendido; `phpunit.xml` lo apaga).
- `invoices.cfdi_series`/`cfdi_folio_number` desde `pac_response` (hook del modelo) y `billing:backfill-cfdi-folio`; `VerifyIssuedCfdiFolio` registra `cfdi_folio_mismatch` si lo timbrado no es el folio del ERP; `billing:health` lo reporta (invalid) sólo con la serie `FAC`.
- API y panel: "Folio CFDI" junto al folio interno en el detalle y la consulta, con la nota "Asignado por Facturapi antes de la 6.28" en las facturas anteriores; la búsqueda acepta `19`, `FAC-19`, `FAC 19` y `FAC19`.
- Desviaciones del plan: una respuesta del PAC sin folio no cuenta como diferencia (no se puede verificar; las respuestas simuladas de las pruebas existentes no lo traen). La consulta de duplicados va detrás de `billing.cfdi_folio_check` para no reescribir 30 pruebas con HTTP simulado, igual que `auto_download`.
- Commits api-v1: `c4b9689`, `4f602a4`, `ab6feec`, `a03e698`, `5e73bc6`, `9452e17`, `71e31c5` (documentación) y `080833d` (consulta de folio antes de la transición ERP). app-front: `6d62634`, `ca9ef2a`.

FASE 6.29 — Infraestructura pre-producción:
- Rechazos del guard PAC en `laravel.log` (`billing.pac.guard_rejected`: código interno, empresa, factura, acción y usuario; nunca mensaje ni llaves), desde las acciones de factura, la descarga automática y Datos fiscales/CSD/logotipo.
- Login web de Fortify: `authenticateUsing` rechaza inactivos y suspendidos sólo con la contraseña correcta (con la incorrecta, el error genérico); con 2FA no llegan al reto.
- Folio CFDI visible sin separador (`FAC20`, `F10`), como el PDF de Facturapi.
- Zona horaria: `DB_TIMEZONE` (`+00:00`) en `mysql`/`mariadb`, migración `normalize_timestamps_to_utc` (`TimestampUtcNormalizer`: lee con la sesión en `SYSTEM` y escribe en `+00:00`; no hace nada si el servidor ya es UTC) y `db:timezone-check`.
- Emisión: el borrador remoto se prepara y valida antes de `ready -> issued` (`409 PAC_DRAFT_NOT_READY` deja la factura en `ready`); una emisión normal sigue haciendo tres llamadas.
- "Regresar a lista" (`POST /operations/revert-issue`, `actions.can_revert_issue`, evento `issue_reverted`) para facturas `issued` sin CFDI con el timbrado rechazado; consulta antes el borrador remoto.
- Logotipo del PDF: `POST/GET /api/company/logo` (Facturapi primero, copia privada para la vista previa, auditoría `logo_uploaded`) y tarjeta en Datos fiscales. El "Tecnologias S.A" del PDF era el logotipo de la organización, no un campo: `legal.name` ya era "Mi Empresa".
- `DEPLOYMENT.md`: requisitos (MySQL 8 o MariaDB 10.6+), variables, primer despliegue, zona horaria, supervisor, `APP_KEY`, User Key, respaldos y verificación.
- Desviaciones del plan: `billing:health` sigue sugiriendo `operations_issue` para `issue_failed`/`erp_issued_without_cfdi` (un reintento resuelve los transitorios; "Regresar a lista" es la alternativa). No se usa `PUT /customization`: no incluye el nombre y los folios los controla el ERP. Se corrigió una prueba intermitente de la 6.28 (`InvoiceCfdiFolioSearchTest`).
- Commits api-v1: `b59978e`, `462ae15`, `324e9a8`, `376ee39`, `f3cb807`, `4028401`, `d571ad4` y el de documentación. app-front: `208855d`, `8f873d8`, `b4b237d`.

FASE 6.30 — Envío de facturas por correo:
- Clientes: `email_optional_1` y `email_optional_2` (el formulario ya los mostraba pero no se guardaban), validados y sin unicidad.
- `PacProvider::sendInvoiceEmail` (`POST /invoices/{id}/email`, siempre con la lista explícita, máximo 10, sin reintentos).
- `POST /api/invoices/{id}/operations/email`: sólo CFDI vigente (ver abajo la cancelada); Admin, Contador y Ventas envían, sólo Admin y Contador cambian la lista (Ventas: `403 INVOICE_EMAIL_RECIPIENTS_LOCKED`); máximo 5 destinatarios; eventos `email_sent`/`email_failed`/`email_uncertain`/`email_skipped` con destinatarios en el contexto cifrado; limitador `invoice-email` y tope diario por empresa (100) contado en la BD.
- Envío automático opcional por empresa (`companies.auto_email_invoices`, `SendInvoiceEmailJob` de un intento, nunca afecta la emisión) y pantalla real Configuración > Correo.
- Correo de contacto de la empresa → `legal.support_email` (aparece en PDF y correos), con aviso en Datos fiscales.
- Verificado con dos envíos reales en TEST (2026-11-04, a un correo del usuario): llegan a la bandeja principal desde `"Mi Empresa" <facturacion@mg.facturapi.io>` con XML, PDF, logotipo y nombre comercial; "Responder" va al `support_email` de la organización (no documentado: funciona como reply-to). Un CFDI cancelado se enviaba sin ninguna marca de cancelación, así que se bloqueó: `can_send_email=false` y `409 INVOICE_EMAIL_CFDI_CANCELED`, con aviso en el panel.
- Panel fiscal: "Enviar/Reenviar por correo" con destinatarios editables según el rol y avisos de modo test y de CFDI cancelado.
- Commits api-v1: `d5a1a3e`, `fb79f0b`, `a7a2d6c`, `c3c1082`, `0acda6b`, `46780cd` (documentación), `d5f60e8` (cancelada no se envía) y el de documentación del cierre. app-front: `01488ba`, `5577bd0`, `46cb890`, `3f45607`.

FASE 6.31 — Modo Live con una empresa piloto (código y R0 completos; P1 a P7 pendientes):
- Ambiente por empresa (`facturapi_organizations.pac_environment`) y `BILLING_PAC_MODE` como techo: `test` es el paro de emergencia (una empresa Live no opera ni usa su llave Test); Live sólo con `APP_ENV=production` (`billing.live_allowed_app_envs`). `PacEnvironment` es la única clase que lee `billing.pac_mode` (una prueba lo exige).
- Ambiente de cada factura (`invoices.pac_environment`) fijado con su primera reserva bajo lock; `409 PAC_ENVIRONMENT_MISMATCH` para facturas de otro ambiente o con operaciones sin ambiente; jobs que revalidan y fallan sin reintentar; `billing:backfill-pac-environment` (`--assume-test` con aborto ante rastros de Live).
- `livemode` exigido según el ambiente, en ambos sentidos. Llave Live cifrada, capturada con prompt oculto (`billing:facturapi-live-key`), en `security:reencrypt`; `#[SensitiveParameter]` en todo parámetro con llave.
- Live: endpoints legacy `/issue`, `/cancel`, `/pac/issue`, `/pac/cancel` con `409 LEGACY_OPERATION_UNAVAILABLE`; motivos 02 y 03 (el 04 se retiró en ambos ambientes, el 01 sólo en Test); readiness del emisor (CSD vigente, lista para producción, correo de contacto igual al `support_email`).
- Acuse: además del UUID, `EstatusUUID` 201 o 202 (`CANCELLATION_RECEIPT_STATUS_REJECTED`); diagnóstico de solo lectura `billing:cancellation-receipt-inspect` en cualquier ambiente.
- `billing:pac-environment` (sólo consola, RFC escrito, datos remotos frescos, lista exacta de "factura Test sin terminar", cola vacía, sin vuelta a Test con facturas Live). `billing:pac-check` para Live (pasos pendientes, `support_email`, logotipo heredado, facturas de otro ambiente). `billing:health`: plazo del receptor en días hábiles + 24 h.
- Frontend: etiqueta Pruebas/Producción, advertencias en Live al emitir y cancelar, motivos por ambiente, avisos de ambiente y de correo de contacto, Datos fiscales con el ambiente.
- Hallazgos corregidos: un acuse con el UUID correcto y cualquier `EstatusUUID` se guardaba; con una configuración inválida `/pac/reconcile` marcaba la factura en revisión y `/pac/artifacts` la dejaba `failed` sin haber contactado al PAC.
- Desviaciones: sólo en Test, en la emisión legacy y en las búsquedas por `external_id` y folio se tolera la ausencia de `livemode` (55 respuestas simuladas anteriores no lo traen); en Live se rechaza siempre (commit 13). El comando de importación de la llave quedó en el commit 3 porque necesita el guard por ambiente.
- Commits api-v1: `a859f97`, `6a83b91`, `48f7e9c`, `9905fdd`, `f356172`, `53c4712`, `e45ccb8`, `d513714`, `2c77c44`, `15531c7`, `d93ebde`, `d088776` (documentación), `ee74271` (livemode estricto en Live y emisión legacy cerrada en Live), `8ed1191` (documentación de R0), `6477cce` (legacy-invoices), `8e2d364` (auditoría), `f00e635` (sin autoborrado web), `31e53fd` (disco privado sin `/storage/{path}`), `6b6b128` (contraseña web), `51fd7a0` (perfil web), `3f39d83` (2FA web) y el de documentación de la auditoría ampliada. app-front: `de1a40c`.
- Regresión R0 en Test (2026-11-05): ver el estado de la BD local en la sección 8.
- Seguridad (commit `6477cce`): se retiraron las cinco rutas de `legacy-invoices` (tabla sin `company_id` ni Policy, accesible entre empresas).
- Auditoría de aislamiento por empresa, ampliada (2026-11-05/06, solo lectura). `route:list` completo: 125 rutas (`api` 91, `web` 9 con 3 de Inertia, Fortify 22, otras 3: `storage/{path}` GET/PUT y `up`); 111 con sesión, todas con `active` (las 107 de la 6.27b se midieron en su commit 0b; después +3 en la 6.27b, +3 en la 6.29, +3 en la 6.30 y −5 en la 6.31). La primera pasada (95 de 97) excluyó las de paquetes. Orden real: `Authenticate` > `ThrottleRequests` > `SubstituteBindings` > `TenantMiddleware` > `EnsureUserIsActive` > controlador; 66 rutas con parámetros, ninguno tipado como modelo y sin `Route::model`/`bind` (las `{invoice}` reciben `int` y el controlador hace `findOrFail` con el tenant ya fijado). Fortify: registro público desactivado; `email/verify/{id}/{hash}` exige el id y el hash del propio usuario. Hallazgos corregidos: autoborrado de la cuenta por web (`f00e635`), `/storage/{path}` del disco privado (`31e53fd`), contraseña web sin revocar token ni sesiones (`6b6b128`), correo editable por cualquier rol en la web (`51fd7a0`) y opción de activar una 2FA que la API no exige (`3f39d83`). Primera pasada: Todos los modelos de negocio usan `BelongsToCompany` (`CompanyScope`, que sin tenant no devuelve nada) y `TenantMiddleware` fija el tenant desde el usuario en el grupo `api`; no hay binding implícito de modelos. Las reglas `exists` sobre `clients`, `products` y `sales` filtran por `company_id`. De 83 `withoutGlobalScope`, 68 vuelven a filtrar por empresa en la misma consulta, 7 están en comandos del operador (sin HTTP) y 8, en servicios de ventas y facturación, buscan por `sale_id`/`quote_id` de una venta o cotización ya bloqueada con su `company_id`, o por sus `client_id`/`product_id`, que sólo se guardan validados por empresa. Sin Policy, a propósito: `AuthController@user`/`logout` y Settings (el propio usuario), catálogos globales (`SatCatalogController`, `TaxRateController`) y `UserController@index`/`show`, que filtran por la empresa del usuario (`UserController.php:36-38` y `:174-177`): cualquier rol ve a los usuarios de su propia empresa.
- Pendiente del piloto (ver `DEPLOYMENT.md`, sección 10): empresa con CSD vigente, contador, servidor con `APP_ENV=production` y cuenta de Facturapi con suscripción activa.

Documentación principal:

`api-v1/BILLING_LIFECYCLE.md` y `api-v1/DEPLOYMENT.md`

## 8. Estado de pruebas y línea base

Línea base de la FASE 6.31 (verificada el 2026-11-06 sobre `changes-ivan` e
`integracion-ivan`, después de R0 y de la auditoría ampliada):
- Backend: 1857 pruebas aprobadas, 7842 assertions, 0 fallidas, 1 omitida (la prueba de MySQL de la zona horaria; corre sólo contra MySQL/MariaDB).
- Frontend: 280 pruebas aprobadas en 28 archivos, 0 fallidas.
- TypeScript (`tsc --noEmit`): aprobado. Tras borrar una página hace falta un build para regenerar `.next/types` antes del typecheck.
- Build Next.js: aprobado, 33 páginas generadas.
- ESLint global del frontend: 31 errores / 51 avisos, todos previos (antes de la 6.24: 36 / 68); 0 hallazgos de `no-console`.
- Pint de archivos relacionados: aprobado.
- `git diff --check`: limpio.

Ejecuta las suites de nuevo antes de afirmar que el workspace actual está
validado. (Histórico: FASE 6.17 reportó 937 backend / 81 frontend.)

Estado de la BD local de desarrollo:
- Company 1 tiene `default_payment_form='03'` y `default_payment_method='PUE'` escritos manualmente (antes null); no hay endpoint para configurarlos.
- Migraciones de la 6.21, 6.22 y 6.25 (`cfdi_artifacts_started_at`, `cancellation_receipt_started_at`, `add_payment_to_sales_table`, `scope_invoice_sale_uniqueness_to_active_invoices`) aplicadas.
- Migraciones de la 6.26 aplicadas (`create_sat_product_keys_table`, `create_sat_unit_keys_table`, `drop_unique_clave_producto_on_products_table`, `add_tax_rate_id_to_products_table`) y catálogos importados (52 513 / 2 418). Cada ambiente nuevo debe correr `php artisan sat:import-catalogs`.
- Migraciones de la 6.27 aplicadas. Empresa 1 vinculada con la organización `6ad00a7079761a6ddec6026a` (llave `sk_test_…hMMB`); #1–#3 archivadas; `billing:health`: 17 facturas `valid`. `billing:pac-check` falla por los datos de la empresa 1, que difieren de Facturapi y se corrigen a mano: RFC `XAXX010101000` frente a `XIA190128J61`, C.P. vacío frente a `06010` y razón social "Mi Empresa" frente a "TECNOLOGIAS S.A". La organización no tiene CSD (en TEST timbra igual).
- 6.27b, aplicado con autorización el 2026-10-29: migración `create_facturapi_organization_audits_table` (lote 36) y `PUT /legal` real de la empresa 1 como el usuario 2 (razón social XENON INDUSTRIAL ARTICLES, RFC XIA190128J61, régimen 601, C.P. 06010; nombre comercial "Mi Empresa"; auditoría #1). `billing:pac-check --company=1`: WARN, sólo por el CSD ausente (en TEST no bloquea); la cuenta de la User Key es dueña de la organización. La empresa 1 vuelve a emitir: venta #29 (VTA-00000021) → factura #20 (FAC-00000019), `issued`/`valid` en TEST. CSD sin cargar por decisión del usuario.
- 6.28, aplicado con autorización el 2026-10-30: migración `add_cfdi_folio_to_invoices_table` y `billing:backfill-cfdi-folio --apply` (13 facturas con la serie `F`: #1–#3 de la organización anterior y #8–#13, #16, #17, #19 y #20 de la actual, F-1 a F-10). No había ventas ni facturas PPD. Venta de prueba #30 (VTA-00000022, cliente 7, producto 14, 03/PUE, IVA 16 %, total 116.00) creada en borrador para emitirse desde la interfaz; el usuario la emitió: factura #21 (`FAC-00000020`), XML con `Serie="FAC" Folio="20"`, sin `cfdi_folio_mismatch`.
- 6.29, aplicado con autorización el 2026-10-30: respaldo `backups/facturapro-2026-10-30-pre-6.29-timezone.sql` (fuera de los repos) y migración `normalize_timestamps_to_utc` (236 filas en 20 tablas; huella y 23 fechas de muestra idénticas antes y después; `db:timezone-check` OK). `GET /organizations/{id}` de la empresa 1: `legal.name` "Mi Empresa", `has_logo` true (imagen "Tecnologias S.A"). Migración `add_logo_to_facturapi_organizations_and_companies` aplicada después (2026-10-30); el logotipo lo sube el usuario desde Datos fiscales.
- 6.30, aplicado con autorización el 2026-11-04: migraciones `add_optional_emails_to_clients_table` y `add_auto_email_invoices_to_companies_table` (envío automático de la empresa 1 apagado). Dos envíos reales en TEST, sólo a un correo del usuario: #22 (`FAC-00000021`, vigente) y #19 (`FAC-00000018`, cancelada), ambos `email_sent`. Los clientes 1 "prueba" y 2 "MOM" pasaron de `prueba.com` (dominio real) a `prueba@example.com` y `prueba2@example.com` (cambio de datos de desarrollo autorizado); los tres clientes locales usan `example.com`. La organización de desarrollo conserva un `support_email` heredado (del usuario) mientras la empresa 1 no capture su correo de contacto.
- 6.31, aplicado con autorización el 2026-11-05 (regresión R0, sólo Facturapi Test y la llave Test de la empresa 1): respaldo `backups/facturapro-2026-11-05-pre-6.31-r0.sql` (28 tablas, iguales a la BD); migraciones `add_live_environment_to_facturapi_organizations_table`, `add_pac_environment_to_invoices_table` y `add_legal_support_email_to_facturapi_organizations_table`; `billing:backfill-pac-environment --apply` (15 facturas en `test` por su respuesta guardada, 0 asumidas, 0 en conflicto; las demás nunca llegaron al PAC). La empresa 1 quedó en `pac_environment=test`, sin llave Live.
  - Venta #32 (`VTA-00000024`, cliente 7, producto 14, 03/PUE, $116.00) → factura #23 (`FAC-00000022`) → CFDI `FAC22` (serie `FAC`, folio 22), `pac_environment=test`. Emisión en 6 llamadas: consulta de folio, crear, sincronizar, actualizar, sincronizar y timbrar (el borrador nació con `is_ready_to_stamp=false`, igual que en #20–#22; causa no verificada). XML guardado comparado con la factura: serie, folio, receptor, concepto, forma, método, subtotal, IVA y total coinciden.
  - Cola: el job viejo de XML/PDF de la #22 no hizo nada (ya guardados); el de la #23 los descargó. `pac-check` empresa 1: WARN por CSD ausente y sin correo de contacto; Facturapi reportó `is_production_ready=false` y `pending_steps` `certificate` y `manifiesto`.
  - Cancelación con motivo 02: `canceled`/`accepted`; no hizo falta reconciliar. Acuse: `CANCELLATION_RECEIPT_UUID_MISMATCH`, como se esperaba en TEST. Estructura real del acuse de TEST: raíz `Acuse` sin namespace, `Acuse/Folios` con `UUID` y `EstatusUUID` hermanos (`EstatusUUID=205`, UUID ajeno `CF5138A2...2E90`) y `Acuse/Signature`.
  - `billing:health`: 21 facturas activas `valid`, sin `pac_environment_mismatch`. 0 coincidencias de `sk_test_`, `sk_live_` o `sk_user_` en los logs de R0. 13 llamadas reales a Facturapi Test, todas 200. El envío por correo se omitió (no se indicó dirección).
  - Conteo: 22 facturas en total, 1 borrada (#14), 21 activas, 3 archivadas (#1–#3). El backfill cuenta las borradas; `billing:health`, no.
- `sat:audit-catalog-data` en la BD local: 2 de 3 clientes y 10 de 11 productos con problemas (claves inventadas, unidades inexistentes, objeto 01 con IVA). No se corrigió nada. El producto 14 guardó `iva = 0.16` (16 % como fracción) y el backfill sólo traduce 16 y 0, así que quedó sin tasa y se reporta como objeto 02 sin IVA. La venta de prueba #23 (producto 14) ya está facturada (FAC-00000012, `issued`); su línea tiene IVA 16 %, así que el readiness la sigue dando por lista.

Deuda conocida:
- Build del frontend de Inertia (web de Laravel, `api-v1/resources/js`) roto desde el primer commit (2026-04-16): `pages/auth/register.tsx` importa `@/routes/register`, que Wayfinder no genera porque el registro está desactivado (`tsc`: 3 errores en `login.tsx`, `register.tsx` y `welcome.tsx`; `vite build` falla). `public/build` no está versionado (`.gitignore`) y el local es del 2026-03-12; `DEPLOYMENT.md` no compila esos assets. En un servidor nuevo las páginas web de Laravel (login de Fortify, restablecer contraseña, 2FA, settings) no cargarían.
- Cerrar las demás sesiones web al cambiar la contraseña sólo funciona con `SESSION_DRIVER=database` (`PasswordChangeSecurity::terminateWebSessions`); con otro driver no hace nada.
- Sin uso desde que se retiraron las rutas de `legacy-invoices` (6.31), conservados a propósito: `App\Http\Controllers\Api\LegacyInvoiceController`, el modelo `App\Models\LegacyInvoice` (sólo lo referencia la relación siguiente) y `Company::legacyInvoices()` (`app/Models/Company.php:142`), que nunca resolvió nada porque `legacy_invoices` no tiene `company_id`. Decidir si se eliminan junto con la tabla, después de respaldar su registro.
- Lint global frontend: 31 errores / 51 avisos previos (sobre todo `no-unused-vars`, `no-explicit-any` y reglas de React Hooks en maquetas).
- `CompanyBillingReadinessService::evaluate()` (forma de pago de la empresa) no tiene consumidores desde la 6.25: decidir si se elimina.
- Vulnerabilidades de dependencias reportadas, pendientes de nueva auditoría.
- Búsqueda parcial `LIKE`.
- Índices de consulta pendientes de evaluación con datos reales.
- Debe revisarse el diff del lockfile.
- Los endpoints legacy siguen disponibles por compatibilidad.

Hallazgos de producto pendientes (verificados en el código):
- `billing:pac-check` no compara el `support_email` de Facturapi con el correo de contacto local ni avisa si la organización trae un logotipo que no se subió desde el sistema (en desarrollo, ambos heredados).
- Correo propio del sistema (fuera de Facturapi): necesario para notificar cancelaciones con su acuse; Facturapi no envía el acuse y un CFDI cancelado ya no se envía.
- 17 pantallas siguen siendo maquetas (registradas como `demo` en `app-front/src/data/screenStatus.ts`).
- Complemento de pago (REP): requisito para reactivar PPD (bloqueado en la 6.28). Al construirlo, quitar PPD de `SalePaymentRules::UNAVAILABLE_METHODS`; la regla PPD ↔ 99 ya está lista.
- `companies.default_cfdi_series` no tiene uso: la serie del CFDI sale del folio interno (`FAC`). Decidir si se elimina o si se permite configurar el prefijo del folio interno.
- Un `/stamp` rechazado por una validación del SAT que sólo ocurre al timbrar deja la factura `issued` sin CFDI; se recupera con "Regresar a lista" (6.29) y, para corregir datos, eliminándola y volviendo a facturar la venta. La cancelación sólo ERP no se ofrece a facturas con borrador remoto.
- Columnas `TIMESTAMP`: límite 2038-01-19 (convertir a `DATETIME` en una fase futura).
- Facturapi no documenta cómo quitar un logotipo: sólo se reemplaza.
- El C.P. del cliente con RFC genérico sólo se valida en el readiness de venta y de emisión; el formulario de Clientes no lo avisa (no conoce el C.P. de la empresa y Sales/Employee no pueden leer los datos fiscales).
- La regla de razón social sin régimen societario es un patrón (S.A., S.A. de C.V., S. de R.L., S.C., A.C., S.A.S., S.A.P.I.): puede tener falsos positivos con nombres que terminen así.
- Folios de cotizaciones: `QuoteNumberGenerator` ignora las cotizaciones borradas (suave); borrar la última y crear otra repite el folio y choca con el único `(company_id, folio)` (500). Facturas y ventas ya se corrigieron en la 6.25; cotizaciones quedaron fuera de alcance.
- Aprobación de ventas: `submit`/`confirm` se conservan, pero `SalePolicy` no distingue roles (cualquiera de la empresa confirma su propia venta) y no se registra quién confirmó.
- RFC genéricos en la unicidad de clientes: excluir `XAXX010101000` y `XEXX010101000` del único `(company_id, rfc)` para permitir varios clientes extranjeros (y más de un "público en general") en la misma empresa. Hoy el índice de BD y `Store/UpdateClientRequest` lo impiden; requiere migración (p. ej. columna generada que sea null para los genéricos) y ajustar la regla `unique`.
- IVA 8 % del estímulo fiscal de la región fronteriza: agregarlo a `tax_rates` con sus condiciones de aplicación (contribuyentes inscritos en el padrón del estímulo, operaciones realizadas en la región, vigencia del decreto) y validarlas al capturar y facturar. Fase propia. Mientras tanto se retiró de Productos porque no existe en `tax_rates`.
- Objeto de impuesto 05–08 no se valida contra el IVA.
- Importación masiva real (fase propia): CSV con plantilla, validación por fila con las mismas reglas de la 6.26 vía los Form Requests (`StoreClientRequest` / `StoreProductRequest`, nunca `create()` directo) y reporte de errores por renglón. Falta decidir si un archivo con errores no guarda nada o importa solo las filas válidas. Mientras tanto el botón de Clientes y Productos se muestra como "Próximamente".
- IEPS, retenciones (IVA RET, ISR), cuenta predial, pedimento e impuestos locales: soporte real de punta a punta (fase propia): captura, cálculo en la línea de venta, copia a la factura y envío al PAC (hoy el PAC rechaza retenciones con `INVOICE_ITEM_WITHHOLDING_UNSUPPORTED`). Hoy esas columnas de `products` se guardan pero nada las lee; Productos las muestra deshabilitadas y ya no las envía (en la BD local, el producto 2 tiene IVA RET 8, IEPS 50 e ISR 5.4). Problemas de diseño encontrados que esa fase no debe heredar:
  - Precisión de dos decimales: las columnas son `decimal(5,2)`, así que 10.6667 % se guarda como 10.67 (y 0.5333 % como 0.53).
  - IEPS tasa y cuota en la misma columna `ieps`: no se distingue cuál es, y al editar siempre vuelve como tasa, así que la cuota se pierde.
  - Solo se guarda la primera cuenta predial y el primer pedimento; los demás se descartaban sin aviso.
  - Impuestos locales como JSON en un texto de 255 caracteres: a partir de unas tres entradas el guardado falla con 422.
  - Esa fase debe rediseñar el modelo de impuestos por línea (varios impuestos por concepto, traslado/retención, tasa/cuota, precisión del SAT), no reutilizar estas columnas.
- `default_payment_form` de la empresa no tiene endpoint y `CompanyBillingReadinessService` sólo exige 2 dígitos; uno fuera de catálogo aparece como `SALE_PAYMENT_FORM_INVALID` en el readiness de la venta.
- Los datos existentes inválidos (ver estado de la BD local) deben corregirse a mano desde las pantallas; `sat:audit-catalog-data` los lista.

Pendientes antes de P1 (de la auditoría ampliada de la 6.31):
- `must_change_password` no se hace cumplir. Lo activa un Admin al crear un usuario, al cambiarle la contraseña o al restablecerla desde Next.js (`ResetPasswordModal`, marcado por omisión con la contraseña temporal) y lo publica `UserResource`, pero ningún middleware ni pantalla lo exige: en producción un usuario seguiría usando indefinidamente la contraseña temporal que vio el Admin. Además, en Next.js un usuario que no es Admin no puede cambiar su propia contraseña (`PATCH /api/users/{id}/password` exige `manageSecurity`, sólo Admin; la pantalla `/pages/settings/seguridad/password` está como `unavailable`) y "¿Olvidó su contraseña?" del login no hace nada. En la BD local 1 usuario tiene la bandera activa.
- Web de Laravel e Inertia: Next.js no usa ninguna de sus rutas (sólo `/api/...`). Lo único que dependería de ellas es la recuperación de contraseña de Fortify (el correo apunta a `reset-password/{token}`, una página Inertia), el cambio de contraseña y la 2FA web, todo sobre un build roto. Retirarlas rompería el login web de Fortify, `reset-password`, la verificación de correo, la 2FA web y `settings/*`, que hoy no usa ningún flujo real. Decisión propuesta antes de P1: llevar a la API y a Next.js el cambio de la propia contraseña, la recuperación por correo (con `ResetPassword::createUrlUsing` hacia Next.js) y el cumplimiento de `must_change_password`; después retirar las rutas web de Laravel y dejar Fortify sin vistas. Mientras no se decida, no se retira nada.
- 2FA del producto: `POST /api/login` todavía no la exige (la web dejó de ofrecer activarla en la 6.31). Pendiente antes del lanzamiento general.

Riesgos pendientes:
- Zona horaria (resuelto en la 6.29): un servidor con datos anteriores debe migrar en mantenimiento y en el mismo servidor donde se escribieron (ver `DEPLOYMENT.md`).
- Respaldos: una copia de la BD junto con el `.env` (`APP_KEY`) expone las llaves de Facturapi; ver `BILLING_LIFECYCLE.md` (rotación con `APP_PREVIOUS_KEYS`, `security:reencrypt` y `billing:facturapi-link --replace-key`).
- User Key: abre todas las organizaciones de la cuenta y vive en el proceso web (datos fiscales y CSD). Mitigado con la lista cerrada del cliente, sólo Admin, límites de intentos, auditoría y redacción; si el `.env` se filtra, reemplazarla en Facturapi.
- Sin verificar contra Facturapi real: la forma exacta de los errores del CSD, si `PUT /legal` borra los campos de dirección omitidos (se envían todos los que tiene la empresa), si una organización nueva sin `tax_id` timbra en TEST y qué responde `GET /organizations/{id}` con una organización de otra cuenta.
- Acuse de cancelación solo verificable en Live: en TEST siempre trae un UUID ajeno (EstatusUUID 205) y se bloquea por diseño. Verificación obligatoria con la primera cancelación real en Live; no relajar la validación. Desde la 6.31 también se exige `EstatusUUID` 201 o 202; la estructura `Folios/UUID` + `Folios/EstatusUUID` viene de fuentes secundarias y se confirma con ese primer acuse.
- Llaves Live (6.31): una llave Live puede cambiar los datos fiscales y borrar o reemplazar el CSD de su organización; una User Key filtrada puede crear llaves Live en cualquier organización de la cuenta; los timbres de la suscripción se comparten entre organizaciones, así que una llave comprometida consume los de todas. Respuesta a incidentes en `DEPLOYMENT.md`, sección 10.
- Rotación de llaves Live sin verificar: la documentación dice que `PUT /apikeys/live` no invalida las anteriores; un comentario del SDK dice lo contrario. Crear la nueva, importarla y comprobarla antes de revocar la anterior.
- Sin verificar contra Facturapi Live: que `GET /organizations/me` acepte una llave Live (si no, la importación no guarda nada y la alternativa por `first_12` requiere aprobación), los tipos de `pending_steps`, que las facturas Test y Live estén aisladas entre sí y cómo reporta Facturapi el silencio del receptor tras un rechazo.
- `billing:health` no modela los días festivos oficiales en el plazo del receptor: puede escalar antes de tiempo.
- Resuelto en la 6.31: `legacy_invoices` no tiene `company_id` ni Policy (sistema de tickets anterior) y cualquier usuario activo de cualquier empresa podía listar, ver, crear, modificar y borrar todos sus registros. Se retiraron sus cinco rutas (`/api/legacy-invoices`); la tabla, el modelo, la migración y el registro existente se conservan. `LegacyInvoiceRoutesRemovedTest` falla si reaparecen.
- Login con sesión única: `POST /api/login` reemplaza el `api_token` del usuario e invalida cualquier otra sesión abierta.
- Folio del CFDI (6.28), sin verificar contra Facturapi: si rechaza folios duplicados (no lo documenta; lo cubre la consulta previa salvo emisiones simultáneas desde fuera), si el `PUT` del borrador respeta `series`/`folio_number` y cómo imprime el PDF la serie y el folio. Se confirma con la primera emisión real posterior a la 6.28.

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

La última fase completada es la FASE 6.31 — Modo Live con una empresa piloto (ver sección 7): código y R0 completos; P1 a P7 pendientes de empresa con CSD vigente, contador, servidor con `APP_ENV=production` y suscripción activa de Facturapi.

Hoja de ruta (acordada el 2026-10-30, en tres hitos):

Hito 1 — Piloto (ventas de contado):
- FASE 6.28 — Folio del CFDI y bloqueo de PPD. Completa.
- FASE 6.29 — Infraestructura pre-producción (zona horaria de MySQL, logs del guard, login de inactivos, checklist de despliegue en `DEPLOYMENT.md`, borrador validado antes de la transición y "Regresar a lista", logotipo del PDF). Completa.
- FASE 6.30 — Envío del CFDI por correo. Completa.
- FASE 6.31 — Modo Live con una empresa piloto. Código y R0 completos; P1 a P7 pendientes (incluye la verificación obligatoria del acuse en la primera cancelación real; ver `DEPLOYMENT.md`, sección 10).

Hito 2 — Lanzamiento general:
- 2FA del producto: exigirla en `POST /api/login` y en Next.js (hoy sólo existía en la web de Laravel, que dejó de ofrecerla en la 6.31).
- Modelo de impuestos por línea: retenciones (IVA RET, ISR) e IEPS (ver los problemas de diseño en la sección 8).
- Complemento de pago (REP) y reactivación de PPD.
- Notas de crédito.
- Cancelación con sustitución.
- IVA 8 % de la región fronteriza.
- Importación masiva de clientes y productos.

Hito 3 — Contabilidad y estadísticas:
- Reportes.
- Cuentas por cobrar y por pagar.
- Descarga masiva de CFDI recibidos (requiere FIEL).
- Contabilidad electrónica.

Después del lanzamiento:
- Deprecación de endpoints legacy (`/api/invoices/{id}/issue`, `/cancel`, `/pac/issue`, `/pac/cancel`): headers `Deprecation`, successor links y pruebas. `legacy-invoices` ya no está: sus rutas se retiraron en la 6.31.
- Lint y Pint globales.
- Aprobación de ventas por rol.
- Sesiones múltiples por usuario (hoy `POST /api/login` reemplaza el `api_token`).

## 11. Siguiente tarea

No avanzar al piloto (P1 a P7) hasta que existan la empresa piloto con CSD vigente, su contador, un servidor con `APP_ENV=production` y la suscripción activa de Facturapi. Cada paso del piloto requiere autorización explícita.

No inventes el estado actual del workspace. Si falta información, inspecciona el código y explica qué no pudo verificarse.
La meta es continuar desde el trabajo real existente, preservar las decisiones anteriores y avanzar de forma incremental y verificable.