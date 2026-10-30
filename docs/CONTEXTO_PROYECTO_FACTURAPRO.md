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

Documentación principal:

`api-v1/BILLING_LIFECYCLE.md`

## 8. Estado de pruebas y línea base

Línea base al cierre de la FASE 6.28 (verificada el 2026-10-30 sobre
`changes-ivan` e `integracion-ivan`):
- Backend: 1587 pruebas aprobadas, 6727 assertions, 0 fallidas.
- Frontend: 239 pruebas aprobadas en 27 archivos, 0 fallidas.
- TypeScript (`tsc --noEmit`): aprobado. Tras borrar una página hace falta un build para regenerar `.next/types` antes del typecheck.
- Build Next.js: aprobado, 32 páginas generadas.
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
- 6.28, aplicado con autorización el 2026-10-30: migración `add_cfdi_folio_to_invoices_table` y `billing:backfill-cfdi-folio --apply` (13 facturas con la serie `F`: #1–#3 de la organización anterior y #8–#13, #16, #17, #19 y #20 de la actual, F-1 a F-10). No había ventas ni facturas PPD. Venta de prueba #30 (VTA-00000022, cliente 7, producto 14, 03/PUE, IVA 16 %, total 116.00) creada en borrador para emitirse desde la interfaz; su factura será `FAC-00000020` y su CFDI `FAC-20`.
- `sat:audit-catalog-data` en la BD local: 2 de 3 clientes y 10 de 11 productos con problemas (claves inventadas, unidades inexistentes, objeto 01 con IVA). No se corrigió nada. El producto 14 guardó `iva = 0.16` (16 % como fracción) y el backfill sólo traduce 16 y 0, así que quedó sin tasa y se reporta como objeto 02 sin IVA. La venta de prueba #23 (producto 14) ya está facturada (FAC-00000012, `issued`); su línea tiene IVA 16 %, así que el readiness la sigue dando por lista.

Deuda conocida:
- Lint global frontend: 31 errores / 51 avisos previos (sobre todo `no-unused-vars`, `no-explicit-any` y reglas de React Hooks en maquetas).
- `CompanyBillingReadinessService::evaluate()` (forma de pago de la empresa) no tiene consumidores desde la 6.25: decidir si se elimina.
- Vulnerabilidades de dependencias reportadas, pendientes de nueva auditoría.
- Búsqueda parcial `LIKE`.
- Índices de consulta pendientes de evaluación con datos reales.
- No existe endpoint real de envío por correo.
- Debe revisarse el diff del lockfile.
- Los endpoints legacy siguen disponibles por compatibilidad.

Hallazgos de producto pendientes (verificados en el código):
- 17 pantallas siguen siendo maquetas (registradas como `demo` en `app-front/src/data/screenStatus.ts`).
- Complemento de pago (REP): requisito para reactivar PPD (bloqueado en la 6.28). Al construirlo, quitar PPD de `SalePaymentRules::UNAVAILABLE_METHODS`; la regla PPD ↔ 99 ya está lista.
- `companies.default_cfdi_series` no tiene uso: la serie del CFDI sale del folio interno (`FAC`). Decidir si se elimina o si se permite configurar el prefijo del folio interno.
- Un rechazo definitivo del PAC al timbrar (p. ej. `PAC_VALIDATION_FAILED`) deja la factura ERP en `issued` sin CFDI, porque la transición ERP ocurre antes de contactar al PAC. `PAC_FOLIO_ALREADY_USED` ya no: se consulta antes de la transición y la factura queda en `ready`. La ruta para validar con el borrador remoto antes de la transición y recuperar esas facturas es parte de la 6.29 (ver la hoja de ruta).
- El login web de Fortify (sesión) no revisa el estado del usuario: uno inactivo inicia sesión, pero su primera petición autenticada recibe 403 y la sesión se cierra. `POST /api/login` sí lo rechaza.
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
- Los rechazos del guard PAC no quedan en `laravel.log`: `InvoicePacActionExceptionResponder` convierte `PacConfigurationException` en `PAC_CONFIGURATION_INVALID` sin registrar nada, así que el código interno (`pac_mode_unsupported`, `pac_organization_missing`, `pac_key_missing`, `pac_key_mode_mismatch`) solo se conoce reproduciendo el error. Registrar el código interno, la empresa y la factura, sin llaves ni respuestas del PAC, para que un operador diagnostique desde el log (encontrado el 2026-10-28, cuando la empresa 1 aún no tenía vínculo).

Riesgos pendientes:
- Zona horaria de la conexión MySQL: la sesión usa `SYSTEM` (America/Mexico_City); `NOW()` en SQL da hora local y fijar `+00:00` desplazaría las fechas guardadas. Requiere fase propia con migración de datos (documentado en `BILLING_LIFECYCLE.md`).
- Respaldos: una copia de la BD junto con el `.env` (`APP_KEY`) expone las llaves de Facturapi; ver `BILLING_LIFECYCLE.md` (rotación con `APP_PREVIOUS_KEYS`, `security:reencrypt` y `billing:facturapi-link --replace-key`).
- User Key: abre todas las organizaciones de la cuenta y vive en el proceso web (datos fiscales y CSD). Mitigado con la lista cerrada del cliente, sólo Admin, límites de intentos, auditoría y redacción; si el `.env` se filtra, reemplazarla en Facturapi.
- Sin verificar contra Facturapi real: la forma exacta de los errores del CSD, si `PUT /legal` borra los campos de dirección omitidos (se envían todos los que tiene la empresa), si una organización nueva sin `tax_id` timbra en TEST y qué responde `GET /organizations/{id}` con una organización de otra cuenta.
- Acuse de cancelación solo verificable en Live: en TEST siempre trae un UUID ajeno (EstatusUUID 205) y se bloquea por diseño. Verificación obligatoria con la primera cancelación real en Live; no relajar la validación.
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

La última fase completada es la FASE 6.28 — Folio del CFDI y método de pago (ver sección 7). En la BD local ya se aplicaron la migración `add_cfdi_folio_to_invoices_table` y `billing:backfill-cfdi-folio --apply` (13 facturas, todas con la serie `F` anterior a la 6.28). Falta la emisión real en TEST de la venta #30 (VTA-00000022), que hará el usuario desde la interfaz, para confirmar `Serie="FAC" Folio="20"` en el XML y cómo lo imprime el PDF.

Hoja de ruta (acordada el 2026-10-30, en tres hitos):

Hito 1 — Piloto (ventas de contado):
- FASE 6.28 — Folio del CFDI y bloqueo de PPD. Completa.
- FASE 6.29 — Infraestructura pre-producción:
  - Zona horaria de la conexión MySQL (sesión `SYSTEM`; requiere migración de datos, ver `BILLING_LIFECYCLE.md`).
  - Registrar en `laravel.log` los rechazos del guard PAC (código interno, empresa y factura; sin llaves ni respuestas del PAC).
  - Login de usuarios inactivos: que el login web de Fortify los rechace como `POST /api/login`.
  - Checklist de despliegue (variables, migraciones, `sat:import-catalogs`, worker de colas, respaldos con `APP_KEY`).
  - Ruta para facturas `issued` cuyo timbrado fue rechazado: validar con el borrador remoto (`is_ready_to_stamp`) antes de la transición ERP y permitir recuperar las que ya quedaron `issued` sin CFDI.
- FASE 6.30 — Envío del CFDI por correo.
- FASE 6.31 — Modo Live con una empresa piloto (incluye la verificación obligatoria del acuse de cancelación con la primera cancelación real).

Hito 2 — Lanzamiento general:
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
- Deprecación de endpoints legacy (`/api/invoices/{id}/issue`, `/cancel`, `/pac/issue`, `/pac/cancel`, `legacy-invoices`): headers `Deprecation`, successor links y pruebas.
- Lint y Pint globales.
- Aprobación de ventas por rol.
- Sesiones múltiples por usuario (hoy `POST /api/login` reemplaza el `api_token`).

## 11. Siguiente tarea

Prepara la FASE 6.29 — Infraestructura pre-producción antes de modificar archivos:

1. Confirma el estado real de ambos repositorios y la línea base de la sección 8.
2. Para cada punto de la 6.29 (sección 10), revisa el código y los hallazgos ya documentados (sección 8 y `BILLING_LIFECYCLE.md`).
3. Propón alcance, archivos, pruebas, riesgos y orden de commits.

No inventes el estado actual del workspace. Si falta información, inspecciona el código y explica qué no pudo verificarse.
La meta es continuar desde el trabajo real existente, preservar las decisiones anteriores y avanzar de forma incremental y verificable.