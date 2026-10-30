# Ciclo de vida de Invoice: ERP y PAC

`Invoice.status` y el estado fiscal no representan la misma máquina.

## Estado ERP

- `draft`: factura creada desde una venta confirmada y facturable.
- `ready`: factura comercial revisada, todavía editable.
- `issued`: documento ERP emitido. Por compatibilidad histórica no implica por sí solo que exista CFDI.
- `cancelled`: documento ERP cancelado.

`InvoiceWorkflow` es la autoridad de estas transiciones. Los endpoints
históricos `/ready`, `/issue` y `/cancel` sólo cambian el ERP y no deben ser
usados por consumidores nuevos para representar una operación fiscal completa.

## Estado PAC/CFDI

- `pac_status`: estado remoto normalizado (`draft`, `pending`, `valid`, `canceled`).
- `pac_issue_status`: intento local (`pending`, `succeeded`, `failed`, `reconciliation_required`).
- `pac_reconciliation_required`: bloquea reintentos fiscales ambiguos.
- `cfdi_uuid` y `pac_external_id`: identidad fiscal final; nunca se reemplazan por otra identidad.
- `cancellation_status`: `pending`, `verifying`, `accepted`, `rejected` o `expired`.

Los servicios `CreatePacDraftInvoiceService`, `SyncPacDraftInvoiceService`,
`UpdatePacDraftInvoiceService`, `StampPacDraftInvoiceService`,
`ReconcileInvoiceWithPacService` y `CancelInvoiceWithPacService` escriben estos
campos. Todo I/O remoto ocurre fuera de transacciones con locks de fila.

## Operaciones empresariales

### Emitir factura

`POST /api/invoices/{invoice}/operations/issue`

Payload: `{ "confirm": true }`.

1. valida tenant, Policy, estado e integridad fiscal;
2. prevalida readiness mientras la factura sigue `ready`;
3. confirma `ready -> issued` en una transacción corta;
4. delega draft/sync/update/stamp al orquestador PAC existente;
5. sólo responde 200 final cuando ERP=`issued`, PAC=`valid`, existe identidad,
   `pac_issue_status=succeeded` y no se requiere reconciliación.

Un timeout o resultado ambiguo conserva ERP=`issued`, marca reconciliación y
nunca repite el stamp a ciegas. La misma operación es idempotente cuando el
CFDI ya quedó confirmado.

### Cancelar factura

`POST /api/invoices/{invoice}/operations/cancel`

Payload mínimo sin CFDI: `{ "confirm": true }`.

Con CFDI válido agrega `motive` (`01`-`04`) y, para `01`,
`substitution_uuid`.

- sin identidad CFDI: converge únicamente el ERP;
- con CFDI válido: solicita cancelación PAC y sólo converge ERP cuando
  `pac_status=canceled` está confirmado;
- pending/verifying/ambiguo: responde como operación no final y ERP no se
  marca cancelado;
- CFDI ya cancelado: converge ERP sin repetir la llamada PAC.

El acuse es un artifact posterior y no condiciona la cancelación. El código
`CANCELLATION_RECEIPT_UUID_MISMATCH` continúa bloqueando artifacts ajenos sin
modificar `cfdi_uuid`.

#### Acuse en Facturapi TEST (observado)

`DownloadCancellationReceiptService::assertValidXml()` compara el texto de
`Acuse/Folios/UUID` (por nombre local) contra `cfdi_uuid` con `strcasecmp`
tras `trim`: ignora mayúsculas/minúsculas y basta un `Folios` que coincida.
Hay pruebas con el UUID del acuse en mayúsculas.

En Facturapi TEST, los acuses obtenidos **nunca han correspondido** al CFDI
cancelado. Verificación de solo lectura (2026-10-21, FAC-00000010, CFDI
`canceled`/`accepted`): `GET /invoices/{id}/cancellation_receipt/xml`
respondió 200 con raíz `Acuse` y un único `Folios/UUID`, un UUID válido
pero **ajeno** (no coincide ni ignorando mayúsculas, no es genérico ni el de
otra factura local) y `EstatusUUID=205`. La factura #2 (otra organización,
agosto) tuvo el mismo rechazo. Hipótesis: en TEST la cancelación no llega
al SAT real y el acuse no describe este comprobante; no se verificó si TEST
devuelve siempre el mismo acuse.

Consecuencia esperada en TEST: "Obtener acuse" termina en
`CANCELLATION_RECEIPT_UUID_MISMATCH`, `cancellation_receipt_status` queda
`reconciliation_required` y no se guarda ningún archivo. No es un fallo de
comparación ni debe relajarse la validación: el bloqueo protege contra
acuses ajenos en LIVE. La cancelación del CFDI no depende del acuse.

**Verificación obligatoria en Live.** En Facturapi TEST el acuse de
cancelación contiene un UUID ajeno (EstatusUUID 205) y el sistema lo bloquea
por diseño. La aceptación de un acuse legítimo solo puede verificarse con la
primera cancelación real en modo Live; es una verificación obligatoria de esa
fase.

**Reintentos del acuse (Fase 6.22).** El backend calcula
`actions.can_fetch_cancellation_receipt`: CFDI `canceled` + `accepted`, sin
acuse almacenado ni descarga vigente, permiso `managePacArtifacts` y por
debajo del límite de bloqueos de identidad, contados con los eventos
`cancellation_receipt_identity_mismatch`. En `test` el límite es 1 (un
reintento sólo repetiría el acuse ajeno); en `live`,
`billing.cancellation_receipt.live_identity_mismatch_limit` (3). Al
alcanzarlo, el panel deja de ofrecer "Obtener acuse" y `billing:health`
sugiere `manual_review`. El endpoint y el comando de diagnóstico siguen
pudiendo reintentar explícitamente.

## Flujo venta → factura (Fase 6.25)

La UI captura la venta en `/pages/sales/new` y `/pages/sales/{id}` y la
factura con **una sola llamada**:

`POST /api/sales/{sale}/invoice` (`InvoiceSaleService`), dentro de una
transacción y con la venta bloqueada:

1. Si la venta ya tiene factura vigente responde **200** con ella, sin crear
   otra. Una factura en `draft` del flujo anterior se marca `ready`; `ready`,
   `issued` y `cancelled` se devuelven sin tocar nada.
2. Venta cancelada: **409 `SALE_CANCELLED`**.
3. Readiness previo a facturar; si falla, **422 `SALE_NOT_BILLABLE`** con
   `errors[{code, field, message}]` y la venta queda sin cambios.
4. Conserva los estados de la venta `draft → pending → confirmed`
   (`SaleWorkflow`), convierte (`SaleToInvoiceConverter`) y deja la factura
   `ready` (**201**). Cualquier fallo revierte todo (savepoints).

Una segunda petición concurrente espera el bloqueo y recibe la misma
factura. Los pasos de aprobación (`submit`/`confirm`) se conservan, pero hoy
no hay separación de roles: cualquier usuario de la empresa puede ejecutarlos.
Los endpoints `submit`, `confirm`, `POST /api/invoices` y `ready` siguen
disponibles.

**Readiness previo a facturar** (`GET /sales/{id}/billing-readiness`,
`SaleBillingReadinessService::evaluate($sale, beforeInvoicing: true)`):
borrador y pendiente pueden facturarse; además del cliente y los productos,
exige el pago efectivo (`SALE_PAYMENT_FORM_MISSING`,
`SALE_PAYMENT_METHOD_MISSING`, `SALE_PPD_NOT_AVAILABLE`,
`SALE_PUE_FORBIDS_FORM_99`), rechaza retenciones
(`ITEM_TAX_WITHHOLDING_UNSUPPORTED`) y bloquea la clave de unidad faltante.
El modo base (sin `beforeInvoicing`) no cambia: lo usa `POST /api/invoices`.

**Forma y método de pago por venta** (`sales.payment_form`,
`sales.payment_method`): la venta guarda los suyos y la empresa
(`default_payment_*`) sólo es respaldo, campo por campo
(`SalePaymentRules::effective()`). PUE no admite la forma 99, tanto al
guardar (Store/UpdateSaleRequest) como al revisar el readiness.

**PPD bloqueado (Fase 6.28).** Un CFDI PPD obliga a emitir un complemento de
pago (REP) por cada cobro, y el sistema aún no lo genera. PPD sigue en el
catálogo, pero `SalePaymentRules::UNAVAILABLE_METHODS` lo rechaza: 422 al
guardar la venta y, en los readiness, `SALE_PPD_NOT_AVAILABLE` (método
efectivo, incluido el heredado de la empresa), `INVOICE_PPD_NOT_AVAILABLE`
(emisión) y `COMPANY_PPD_NOT_AVAILABLE`. El selector del frontend lo muestra
deshabilitado con la explicación. Es una constante, no configuración: se
reactiva junto con el complemento de pago, y entonces vuelve a aplicar la
regla PPD ↔ 99 (sigue en `SalePaymentRules::violation()`). `POST
/api/invoices` (conversión directa, modo base del readiness) no revisa el
pago; una venta PPD guardada antes del bloqueo se convierte, pero la emisión
la detiene `INVOICE_PPD_NOT_AVAILABLE`.

**Venta cuya factura se borró.** `invoices` usa borrado suave. La unicidad de
la venta vive en la columna generada virtual `active_sale_id` (`sale_id`
mientras la factura no esté borrada): la venta sigue `confirmed`, conserva el
historial de la factura borrada y puede facturarse de nuevo. Los generadores
de folio de facturas y ventas incluyen los registros borrados para no
repetir folios.

`GET /api/tax-rates` lista las tasas de traslado activas para las líneas
(sin retenciones). `GET /api/sales` es paginado (`search`, `status`, `page`,
`per_page`) y cada venta trae su factura vigente resumida. Las mutaciones de
líneas bloquean la venta, así que ninguna se cuela mientras se factura.

## Validación SAT al capturar (Fase 6.26)

**Fuente de los catálogos.** El archivo oficial del Anexo 20
(`catCFDI_V_4_20261015.xls`, sat.gob.mx) convertido a CSV en
`database/data/sat` con un script versionado y repetible (procedencia,
versiones y SHA-256 en su README). Los catálogos pequeños (régimen fiscal,
uso de CFDI con la matriz régimen ↔ uso ↔ tipo de persona, forma de pago y
objeto de impuesto) se leen de los CSV; `c_ClaveProdServ` (52 513 claves) y
`c_ClaveUnidad` (2 418) viven en `sat_product_keys` / `sat_unit_keys`,
cargadas con `php artisan sat:import-catalogs` (idempotente). Sólo cuentan
claves vigentes. **Nada de esto consulta al PAC**: capturar y validar
funciona aunque Facturapi no responda. Facturapi sólo ofrece búsqueda de
claves de producto y unidad, no de régimen, uso o forma de pago.

Endpoints: `GET /api/sat/catalogs` (catálogos pequeños con la matriz),
`GET /api/sat/product-keys?q=` y `GET /api/sat/unit-keys?q=` (prefijo de
clave o texto, máximo 20).

**Reglas** (`App\Services\Sat\SatFiscalValidator`, compartido por Form
Requests, readiness, reporte y Resources):

| Dato | Regla | Código |
|---|---|---|
| RFC | moral 12 (3 letras), física 13 (4 letras); fecha AAMMDD válida; homoclave de 3 | `CLIENT_RFC_INVALID` |
| RFC genérico | `XAXX010101000` / `XEXX010101000` exigen régimen 616 y uso S01 | `CLIENT_REGIME_USE_INCOMPATIBLE` |
| Código postal | 5 dígitos | `CLIENT_POSTAL_CODE_INVALID` |
| Régimen | en `c_RegimenFiscal` y aplicable al tipo de persona | `CLIENT_FISCAL_REGIME_INVALID` |
| Uso de CFDI | en `c_UsoCFDI`, aplicable al tipo de persona y admitido por el régimen | `CLIENT_CFDI_USE_INVALID` / `CLIENT_REGIME_USE_INCOMPATIBLE` |
| Clave de producto | 8 dígitos y existente | `PRODUCT_SAT_KEY_INVALID` (venta: `ITEM_PRODUCT_SAT_KEY_INVALID`) |
| Unidad | obligatoria y existente | `PRODUCT_UNIT_KEY_INVALID` (venta: `ITEM_PRODUCT_UNIT_KEY_INVALID`, misma severidad que la faltante) |
| Objeto de impuesto | en `c_ObjetoImp`; 02 exige IVA, 01/03/04 no lo admiten; 05–08 sin validar | `PRODUCT_TAX_OBJECT_INVALID` / `PRODUCT_TAX_OBJECT_MISMATCH` (venta: `ITEM_PRODUCT_OBJETO_IMP_INVALID`, `ITEM_TAX_OBJECT_MISMATCH` contra el IVA de la línea) |
| Forma de pago de la venta | en `c_FormaPago` (22), además de PPD ↔ 99 | 422 al guardar; `SALE_PAYMENT_FORM_INVALID` en el readiness |

Al editar se valida la combinación final (lo guardado más lo enviado): tocar
un dato fiscal obliga a corregir el conjunto; editar sólo nombre, correo,
domicilio o precio no lo exige. `clave_producto` ya no es única por empresa
(es una clasificación); el identificador propio sigue siendo
`no_identificacion`.

**IVA del producto como tasa.** `products.iva` (decimal) no distinguía exento
de tasa 0 %: "Exento" llegaba como null, se guardaba el default 16 al crear y
respondía 500 al editar. Ahora `products.tax_rate_id` referencia IVA 16 %,
IVA 0 % o IVA Exento de `tax_rates` e `iva` queda derivado (el campo heredado
16/0 se traduce a su tasa). En el PAC, una línea exenta se envía con
`factor: "Exento"`; antes llegaba como IVA tasa 0 %.

**Datos existentes.** `php artisan sat:audit-catalog-data` (solo lectura,
`--company=`, `--format=table|json`, `--fail-on-issues`) lista los clientes y
productos que ya no cumplen; de los clientes sólo muestra códigos y campos,
nunca el RFC. La API expone lo mismo en `fiscal_issues` de clientes y
productos, y las pantallas avisan al editar. Nada se corrige solo.

**Pendiente:** el RFC genérico exige además el código postal del emisor
(lugar de expedición), y `companies.postal_code` aún no se configura; se
valida en la 6.27. La unicidad de `(company_id, rfc)` impide registrar dos
clientes con `XEXX010101000` en la misma empresa.

## Folio del CFDI (Fase 6.28)

El CFDI lleva la serie y el folio del ERP: `FAC-00000019` se timbra como
Serie `FAC` y Folio `19` (`InvoiceFolio`). `FacturapiProvider` los envía como
`series`/`folio_number` al crear el borrador y en cada `PUT` que lo
actualiza, así que un borrador anterior a la 6.28 también los recibe antes de
timbrarse. `folio_number` es entero: los ceros se pierden y la forma visible
es `FAC-19`. `companies.default_cfdi_series` sigue sin uso.

- **Folio inválido.** Un folio que no sea `FAC-` + 8 a 10 dígitos (y mayor
  que 0) da `INVOICE_FOLIO_INVALID` en el readiness, y el provider lo rechaza
  antes de cualquier HTTP. Nunca se omiten los campos: Facturapi asignaría su
  propio consecutivo y el CFDI dejaría de corresponder a la factura.
- **Reintentos e idempotencia.** El folio no cambia tras crear la factura,
  así que cada intento manda el mismo payload con la misma llave.
- **Duplicados.** Facturapi no documenta que rechace folios repetidos. El ERP
  los evita entre sus facturas (único `(company_id, folio)` y una
  organización por empresa); frente a lo emitido por fuera,
  `CfdiFolioAvailability` consulta `GET /invoices?series=…&folio_number=…`
  antes de crear o actualizar el borrador. Un comprobante de la organización
  con ese folio que no sea de esta factura (por `external_id`) detiene todo
  con `409 PAC_FOLIO_ALREADY_USED`, sin escribir nada en el PAC ni en la
  factura. `billing.cfdi_folio_check` (`BILLING_CFDI_FOLIO_CHECK`, encendido
  por defecto) lo controla; `phpunit.xml` lo apaga.
- **Lo timbrado.** `invoices.cfdi_series`/`cfdi_folio_number` los llena el
  modelo desde `pac_response` cada vez que se guarda una respuesta del PAC
  (una respuesta sin folio no borra lo guardado). Las facturas timbradas
  antes de las columnas se llenan con `billing:backfill-cfdi-folio` (dry-run
  por defecto, `--apply`, `--company=`; sin HTTP; sólo columnas vacías).
- **Verificación.** Al emitirse el CFDI (`InvoiceIssued`),
  `VerifyIssuedCfdiFolio` compara lo timbrado con el folio del ERP; si
  difiere, registra el evento `cfdi_folio_mismatch` (folio esperado y
  recibido) y un warning. No revierte nada: el CFDI ya existe. Una respuesta
  sin folio no se puede verificar y no registra nada.
- **Facturas anteriores a la 6.28.** Conservan el folio que asignó Facturapi
  (serie `F`, consecutivo de la organización). La API las marca con
  `cfdi_folio_matches_erp=false` y el panel muestra la nota "Asignado por
  Facturapi antes de la 6.28".
- **API.** `InvoiceResource` (`cfdi_series`, `cfdi_folio_number`,
  `cfdi_folio`, `cfdi_folio_matches_erp`) e `InvoiceBillingResource`
  (`cfdi.series`, `cfdi.folio_number`, `cfdi.folio`, `cfdi.folio_matches_erp`).
  La búsqueda de `GET /api/invoices` acepta el folio CFDI como `19`, `FAC-19`,
  `FAC 19` o `FAC19`: el timbrado (respeta la serie si se escribe) y, para la
  serie `FAC`, el folio interno del que sale, aunque aún no esté timbrado.

**Sin verificar todavía contra Facturapi:** que el `PUT` del borrador
respete `series`/`folio_number` (lo detectaría la verificación posterior) y
cómo los imprime el PDF de Facturapi.

## Endpoints compatibles

- `/invoices/{id}/ready`: ERP local, vigente.
- `/invoices/{id}/issue`: ERP local legacy; no PAC.
- `/invoices/{id}/cancel`: ERP local legacy; no PAC.
- `/invoices/{invoice}/pac/issue`: operación PAC de bajo nivel conservada.
- `/invoices/{invoice}/pac/cancel`: operación PAC de bajo nivel conservada.

Los consumidores de UI nuevos deben usar `/operations/issue` y
`/operations/cancel`. `GET /api/invoices/{invoice}/billing` publica
`actions.can_issue`, `actions.can_cancel`, `actions.can_reconcile`,
`actions.can_fetch_artifacts`, `actions.can_fetch_cancellation_receipt`,
`actions.cancellation_mode` y `pac.mode` (`test`|`live`, o `null` si el
modo configurado no es reconocido); no expone llaves, payloads, respuestas
crudas ni claves de idempotencia.

`can_reconcile` por un borrador `valid` sólo aplica mientras no exista
`pac_external_id` (Fase 6.21): al timbrar, el borrador se transforma en el
CFDI y conserva `pac_draft_status=valid` sin que haya nada que reconciliar.

## Expediente fiscal: XML/PDF (Fase 6.21)

`InvoiceBusinessCapabilitiesService::artifactsFetchable()` es la única
regla para obtener el XML/PDF de un CFDI y la comparten
`DownloadInvoiceArtifactsService`, `actions.can_fetch_artifacts` (más el
permiso `managePacArtifacts`) y el hallazgo `cfdi_artifacts_missing` de
`billing:health`: identidad completa, `pac_status` `valid` **o
`canceled`** y artifacts ni `stored` ni `pending`.

Los CFDI cancelados se pueden descargar: verificado en Facturapi TEST
(`GET /invoices/{id}/xml` y `/pdf` de un CFDI cancelado responden 200 y el
UUID del XML coincide con `cfdi_uuid`); la documentación de Facturapi no
lo restringe ni lo confirma. El PDF descargado antes de cancelar no lleva
marca de cancelación: la prueba de la cancelación es el acuse.

### Descarga automática tras el timbrado

`InvoiceIssued` (emitido después del commit por el timbrado, la
reconciliación y la emisión legacy) lo escucha
`QueueInvoiceArtifactsDownload`, que encola
`DownloadInvoiceArtifactsJob(company_id, invoice_id)`:

- `ShouldBeUnique` por factura, `tries=3`, `backoff=[60, 300, 900]` s.
- Recibe ids, no el modelo: fija el tenant de la factura y restaura el
  previo al terminar (el worker reutiliza el proceso entre jobs).
- No hace nada si los artifacts ya están `stored` o hay una descarga vigente.
- Fallo definitivo (`cfdi_artifacts_status=failed`) → `fail()` sin
  reintento; fallo ambiguo → se relanza y la cola reintenta.
- **Nunca altera la emisión**: el servicio sólo escribe `cfdi_artifacts_*`
  y el listener descarta cualquier error al encolar (con la cola `sync`
  el job corre en línea y su excepción tampoco llega al timbrado).

**Requiere un worker activo.** Con `QUEUE_CONNECTION=database`, sin worker
los jobs esperan en la tabla `jobs` y el XML/PDF no se descarga:

- desarrollo: `composer dev` (incluye `queue:listen`) o
  `php artisan queue:work`;
- producción: un proceso supervisado (p. ej. supervisor) con
  `php artisan queue:work`.

El botón "Obtener XML/PDF" (`actions.can_fetch_artifacts`) sigue disponible
como respaldo. `billing.artifacts.auto_download`
(`BILLING_ARTIFACTS_AUTO_DOWNLOAD`, por omisión `true`) lo apaga; las
pruebas lo apagan en `phpunit.xml` y lo activan explícitamente.

### Reserva de descarga

`DownloadInvoiceArtifactsService` reserva con `cfdi_artifacts_status=pending`
y `cfdi_artifacts_started_at`. Una reserva más vieja que
`billing.artifacts.pending_minutes` (10 min), o sin fecha (anterior a la
columna), se considera abandonada (proceso caído a mitad del HTTP) y puede
retomarse; una vigente rechaza una segunda descarga.

Fase 6.22: el acuse de cancelación sigue el mismo patrón.
`DownloadCancellationReceiptService` reserva con
`cancellation_receipt_status=pending` y `cancellation_receipt_started_at`;
una reserva más vieja que `billing.cancellation_receipt.pending_minutes`
(10 min), o sin fecha, puede retomarse.

## Seguridad de escrituras PAC

### Modo y llave

`billing.pac_mode` (`BILLING_PAC_MODE`, por omisión `test`) define qué llave
se acepta: `test` exige prefijo `sk_test_`; `live` (`sk_live_`) **no está
soportado** en esta fase. Desde la Fase 6.27 la llave es la de la organización
de Facturapi de la empresa de cada factura (`facturapi_organizations`); no hay
llave global en ejecución (ver "Organización de Facturapi por empresa").

`PacCredentialGuard::forCompany()` se evalúa en `FacturapiProvider::client()`
antes de construir cualquier petición (escrituras, consultas y descargas) y en
los orquestadores antes de cualquier transición ERP. Motivos, en este orden:
`pac_mode_unsupported`, `pac_organization_missing`, `pac_key_missing`,
`pac_key_mode_mismatch`. Una configuración inválida lanza
`PacConfigurationException` (fallo definitivo, `503 PAC_CONFIGURATION_INVALID`):
el PAC nunca recibe la petición y el ERP no cambia. La respuesta no incluye el
motivo y hoy tampoco se registra en `laravel.log` (pendiente en el documento
maestro); `billing:pac-check` lo muestra.

Los comandos `billing:facturapi-test-*` usan el trait
`GuardsFacturapiTestCommand`: sólo `local`/`testing`, `pac_mode=test` y llave
con el prefijo del modo; nunca imprimen la llave.

### Logs

Los logs estructurados `billing.invoice.*` nunca contienen `pac_external_id`
completo: `pac_issue_attempt`, `pac_stamp_attempt` y `pac_cancellation` lo
enmascaran (`8 caracteres...4 caracteres`). Tampoco contienen UUID, payloads,
llaves ni errores crudos del PAC.

### Política de reintentos

| Operación | `idempotency_key` | Reintento ante `ConnectionException` |
|---|---|---|
| GET (consultas y descargas) | n/a | 1 reintento |
| POST/PUT de borrador, `createInvoice` | sí (cuerpo JSON) | 1 reintento |
| `POST /invoices/{id}/stamp` | no | nunca |
| `DELETE /invoices/{id}` (cancelación) | no | nunca |

Un timeout de lectura no distingue "no llegó" de "llegó y se procesó". Por eso
el timbrado y la cancelación nunca se reenvían: la `ConnectionException` se
propaga y el servicio la clasifica como ambigua (`reconciliation_required`).
Ninguna respuesta HTTP ya recibida (4xx/5xx) se reintenta.

### Códigos de error de Facturapi (Fase 6.20)

`FacturapiProvider::assertSuccessful()` evalúa `body.code` (docs.facturapi.io,
"Error handling") **antes** que el HTTP status, que Facturapi no documenta
por código. Un código desconocido conserva el mapeo por status.

| `body.code` | Excepción | Clasificación | Código público |
|---|---|---|---|
| `invoice_not_found` | `PacInvoiceNotFoundException` | definitivo (`failed`) | `409 PAC_INVOICE_NOT_FOUND` |
| `stamping_in_progress`, `invoice_cancellation_in_progress`, `draft_update_in_progress`, `idempotency_key_in_use` | `PacOperationInProgressException` (hereda de `PacConflictException`) | ambiguo (`reconciliation_required`) | `409 PAC_OPERATION_IN_PROGRESS` |

Antes, un `stamping_in_progress` con 400 se habría tratado como validación
definitiva y habría habilitado repetir el timbrado.

`invoice_not_found` sólo prueba que la operación no ocurrió **con la llave
configurada**: Facturapi responde lo mismo si la llave es de otra
organización. Por eso la reconciliación nunca lo interpreta como "el remoto
no existe" y mantiene la revisión pendiente.

### Llave de idempotencia repetida (verificado en Facturapi TEST)

Experimento controlado de la Fase 6.20 (borradores `exp-6.20-*`, ya
eliminados):

1. `POST /invoices` (`status=draft`) con la llave K → 200, borrador A.
2. Mismo `POST` con K → **409 `idempotency_key_in_use`**. No devuelve A ni
   crea otro recurso, aunque la primera petición ya haya terminado.
3. `GET /invoices?external_id=` → incluye el borrador (`status=draft`) con
   su `idempotency_key`.
4. `DELETE /invoices/{id}` de un borrador → 200; luego `GET` → 404
   `invoice_not_found`.

Consecuencia: si la creación del borrador falla después de reservar
`pac_draft_idempotency_key` y nunca se guarda `pac_draft_external_id`,
reintentar la emisión responde siempre `PAC_OPERATION_IN_PROGRESS`.
`InvoiceBusinessCapabilitiesService::hasUnlinkedDraftReservation()` detecta
ese estado (llave del borrador sin `pac_draft_external_id`,
`pac_external_id`, `pac_idempotency_key` ni `pac_issue_status`), habilita
`actions.can_reconcile` y `billing:health` lo reporta como
`draft_reserved_without_remote_id`.

`ReconcileInvoiceWithPacService` busca entonces por
`PacIdentifiers::draftExternalId()`, relee el borrador con
`retrieveDraftInvoice()` (exige `livemode=false`) y sólo lo vincula si
`external_id` e `idempotency_key` coinciden con la reserva. Nunca toca
`pac_external_id` ni `cfdi_uuid`; el siguiente `/operations/issue` continúa
desde el borrador vinculado. Si no hay borrador remoto, libera la revisión:
reintentar con la misma llave es seguro, porque Facturapi nunca crea un
segundo recurso con ella.

### Fechas del PAC (Fase 6.20)

Facturapi no publica todas sus fechas en el mismo formato:

| Campo remoto | Formato | Columna local |
|---|---|---|
| `created_at`, `date` | UTC con `Z` | `pac_draft_created_at` |
| `stamp.date` (FechaTimbrado) | **sin offset**, hora del centro de México | `stamped_at` |

`FacturapiDates::stampDate()` interpreta `stamp.date` en
`billing.facturapi.stamp_timezone` (`BILLING_FACTURAPI_STAMP_TIMEZONE`, por
omisión `America/Mexico_City`) y lo normaliza a UTC; un offset explícito en
el texto siempre manda. Antes de esta fase se parseaba como UTC y
`stamped_at` quedaba 6 h atrasado (también el `context.stamped_at` del
evento `StampSucceeded`). Una zona inválida lanza
`PacConfigurationException` (`stamp_timezone_invalid`) en
`FacturapiProvider::client()`, antes de cualquier petición: nunca se
descubre después de timbrar.

Los registros previos a la corrección no se reparan (no hay datos de
producción). `ReconcileInvoiceWithPacService` sólo llena `stamped_at`
cuando está vacío, así que no los corrige por sí solo.

### Reserva de cancelación

`CancelInvoiceWithPacService` reserva la solicitud bajo `lockForUpdate` antes
del DELETE (mismo patrón que la reserva de timbrado):

1. revalida `assertCancellable()` sobre la fila bloqueada;
2. rechaza `pac_cancel_request_status=in_flight`
   (`409 PAC_CANCELLATION_IN_PROGRESS`) o `reconciliation_required`
   (`409 PAC_RECONCILIATION_REQUIRED`);
3. marca `in_flight`, `pac_cancel_started_at` y `pac_cancel_attempts`, y hace
   commit antes de la red; recién entonces registra `CancellationRequested`.

Desenlace: respuesta del PAC → `completed` (aunque la cancelación quede
`pending`); fallo definitivo → `failed` (permite una nueva solicitud
explícita); fallo ambiguo o timeout → `reconciliation_required` +
`pac_reconciliation_required`.

`pac_cancel_request_status` describe la solicitud local, no
`cancellation_status`. `actions.cancellation_mode` es `null` mientras la
solicitud está `in_flight` o `reconciliation_required`, y
`actions.can_reconcile` la libera: si el PAC registra la solicitud o el CFDI
está cancelado queda `completed`; si no, se limpia, salvo una `in_flight` más
reciente que `billing.cancel_in_flight_minutes` (5 min), que puede seguir en
curso. `billing:health` reporta `cancellation_request_in_flight`
(transient) y `cancellation_request_in_flight_stale` (recoverable).

## Salud del ciclo (diagnóstico)

`php artisan billing:health` clasifica cada Invoice comparando el estado ERP
con los campos PAC locales. Es exclusivamente read-only: sólo ejecuta
`SELECT`, nunca hace HTTP al PAC (ni `GET /invoices`, ni `PUT .../status`,
ni descargas), no emite, no cancela, no reconcilia y no modifica facturas ni
eventos. Puede ejecutarse en producción.

`InvoiceHealthEvaluator` reutiliza las reglas de
`InvoiceBusinessCapabilitiesService`; `BillingHealthReportService` recorre las
empresas fijando `CurrentTenant`, de modo que todas las consultas pasan por
`CompanyScope`.

Niveles:

- `valid`: ERP y PAC coherentes.
- `transient`: operación PAC en curso dentro de su ventana esperada.
- `recoverable`: divergencia que una operación existente resuelve.
- `invalid`: combinación que ningún flujo debería producir; revisión manual.

Un hallazgo con nivel `valid` es informativo (`"informational": true` en
JSON, sufijo `(info)` en tabla): se cuenta en `by_code` pero no eleva el
estado de la factura ni dispara `--fail-on`.

| Código | Nivel | Acción sugerida |
|---|---|---|
| `issue_pending_recent` / `issue_pending_stale` | transient / recoverable | wait / reconcile |
| `cancellation_pending_recent` / `cancellation_pending_stale` | transient / recoverable | wait / reconcile |
| `reconciliation_required` | recoverable | reconcile |
| `issue_failed`, `erp_issued_without_cfdi` | recoverable | operations_issue |
| `draft_reserved_without_remote_id` (ERP `issued`, llave de borrador reservada sin `pac_draft_external_id` ni `pac_issue_status`) | recoverable | reconcile |
| `cfdi_canceled_erp_not_converged` | recoverable | operations_cancel |
| `cancellation_request_in_flight` / `cancellation_request_in_flight_stale` | transient / recoverable | wait / reconcile |
| `erp_cancelled_cfdi_valid` (ERP `cancelled`, CFDI `valid`, sin cancelación en curso; incluye rechazo/expiración) | recoverable | manual_review |
| `cancellation_accepted_not_canceled`, `identity_incomplete`, `remote_draft_valid_unlinked` | recoverable | reconcile |
| `cfdi_artifacts_missing` (sólo si `artifactsFetchable()`: CFDI `valid` o `canceled`) | valid (informativo) | fetch_artifacts |
| `artifacts_download_pending` / `artifacts_download_pending_stale` | transient / recoverable | wait / fetch_artifacts |
| `cancellation_receipt_download_pending` / `cancellation_receipt_download_pending_stale` | transient / recoverable | wait / fetch_receipt |
| `receipt_without_cancellation` (acuse sin ninguna solicitud registrada) | valid (informativo) | manual_review |
| `cancellation_receipt_identity_mismatch` (acuse bloqueado: `Acuse/Folios/UUID` no corresponde al CFDI) | valid (informativo) con `billing.pac_mode=test`; recoverable con `live` | manual_review |
| `cancellation_receipt_retry_pending` (acuse en `reconciliation_required` por otro motivo: no disponible, 5xx, archivo local faltante) | valid (informativo) | fetch_receipt |
| `remote_draft_date_window_risk` | transient (≥48 h) / recoverable (≥72 h) | operations_issue / manual_review |
| `pac_organization_archived` (factura de otra organización, archivada con `billing:archive-inaccessible-invoices`; sustituye a todos sus demás hallazgos) | valid (informativo) | — |
| `pac_organization_mismatch` (factura de otra organización sin archivar; sustituye a todos sus demás hallazgos) | invalid | manual_review |
| `erp_cancelled_before_pac_confirmation` (ERP `cancelled`, CFDI `valid`, cancelación `pending`/`verifying`) | invalid | manual_review |
| `identity_uuid_without_external_id`, `erp_behind_cfdi`, `issue_succeeded_without_valid_cfdi`, `pac_status_without_identity`, `idempotency_key_mismatch` | invalid | manual_review |
| `cfdi_folio_mismatch` (CFDI timbrado con la serie `FAC` pero un folio distinto al del ERP; la serie `F` anterior a la 6.28 no se reporta) | invalid | manual_review |

El acuse nunca escala por sí mismo:

- ERP emitido + `status=valid` + `cancellation_status=pending` dentro del
  plazo (con o sin acuse): `transient`.
- `rejected`/`expired`: el CFDI sigue vigente; con ERP emitido es `valid`.
- ERP `cancelled` con CFDI `valid` y sin cancelación en curso:
  `recoverable` (`erp_cancelled_cfdi_valid`).
- ERP `cancelled` con CFDI `valid` y cancelación en curso: `invalid`
  (`erp_cancelled_before_pac_confirmation`), porque el ERP sólo debe
  converger con `pac_status=canceled` confirmado.

`reconciliation_required` no se reporta por separado cuando lo explica una
emisión o cancelación pendiente, salvo que `pac_issue_status` sea
`reconciliation_required`. La antigüedad de una cancelación pendiente se mide
desde el último evento `CancellationRequested` (o `last_pac_sync_at` si no
existe). El acuse nunca cuenta como prueba de cancelación.

Hallazgos del acuse (Fase 6.22): el bloqueo de identidad es la única
excepción a "el acuse nunca escala", y sólo en `live`, donde un acuse ajeno
exige revisión. En `test` Facturapi siempre entrega un acuse ajeno
(`EstatusUUID` 205), así que es informativo y no dispara `--fail-on`. El
motivo se distingue por el prefijo `[CODIGO]` de
`cancellation_receipt_last_error` (`PacErrorCode`), que el reporte lee pero
nunca publica.

Umbrales en `config/billing.php` (`billing.health.*`):

- Emisión pendiente: 60 min (Facturapi reintenta recuperar un CFDI aceptado
  con 202 hasta 5 veces cada 10 min).
- Cancelación pendiente: 96 h = 72 h de plazo del receptor para aceptar o
  rechazar (durante el cual Facturapi reporta `status=valid` +
  `cancellation_status=pending`) + 24 h de margen operativo propio, no
  normativo: el ERP sólo conoce el desenlace al reconciliar.
- Borrador remoto: 48 h aviso / 72 h límite (un CFDI no puede fecharse más
  de 72 h atrás).

Opciones: `--company=`, `--invoice=`, `--status=` (repetible:
`--status=valid --status=transient`; valores `valid|transient|recoverable|invalid`;
por omisión los no válidos; `*` es la sintaxis de Laravel en la firma del
comando, no un valor aceptado), `--format=table|json`, `--limit=50` y `--fail-on=invalid`
(`transient|recoverable|invalid|none`). Sale con código 1 cuando algún estado
alcanza `--fail-on`, apto para cron o alertas. La salida sólo contiene IDs,
folio, estados y códigos: nunca payloads PAC, errores crudos, rutas, llaves de
idempotencia, `pac_external_id` ni `cfdi_uuid`.

## Organización de Facturapi por empresa (Fase 6.27a)

Cada empresa opera con su propia organización de Facturapi. La tabla
`facturapi_organizations` (una fila por empresa, `organization_id` único)
guarda el id de la organización, su llave Test cifrada (`test_secret_key`,
cast `encrypted`, oculta en serialización) y sólo una pista para mostrar
(`test_key_hint`, `sk_test_…últimos 4`), además de la copia local de sus datos
fiscales y su CSD tal como los reporta `GET /organizations/me`.
`companies.api_key` se eliminó.

`PacProviderResolver::forInvoice()` / `forCompany()` construye el provider con
la llave de la empresa de la factura. Nunca lanza: sin organización o con una
llave inválida devuelve un provider cuyo guard falla antes de cualquier HTTP.

Comandos del operador (todos en dry-run salvo `--apply`):

- `billing:facturapi-link --company= --organization= [--apply]`: vincula la
  empresa con la llave de `services.facturapi.test_key` (`.env`). Sólo hace
  `GET /organizations/me` y exige que el id coincida con `--organization`.
  Muestra las facturas de la empresa por organización y las diferencias de
  datos fiscales, que **no corrige**: los datos de la empresa se corrigen a
  mano.
- `billing:backfill-pac-organization [--company=*] [--apply]`: llena
  `invoices.pac_organization_id` desde la respuesta guardada del PAC, sólo
  donde está vacío. Las facturas nuevas lo llenan solas al guardar cada
  respuesta.
- `billing:archive-inaccessible-invoices --invoice=* --reason= [--apply]`:
  archiva facturas de otra organización (`pac_organization_archived_at` y
  motivo, más el evento `organization_archived`). Es todo o nada: rechaza el
  lote si alguna es de la organización actual, de organización desconocida o
  no existe. No toca datos fiscales.
- `billing:pac-check [--company=*] [--format=table|json] [--fail-on=fail|warn|none]`:
  solo lectura. Verifica la organización, el guard, que la llave pertenezca a
  la organización (`GET /organizations/me`), RFC, régimen y C.P. contra
  Facturapi, el CSD (ausente: aviso en `test`, porque Facturapi timbra sin él;
  fallo en cualquier otro modo; vencido: fallo; menos de 30 días: aviso) y las
  facturas sin organización, de otra organización o archivadas. Con User Key
  (6.27b), `account` comprueba con `GET /organizations/{id}` que la
  organización pertenezca a la cuenta; sin ella es informativo.

Identidad por factura: una factura cuyo `pac_organization_id` no es el de su
empresa (`PacOrganizationAccess`: `other` o `archived`) no ofrece acciones
(`InvoiceBusinessCapabilitiesService`) y cualquier operación PAC responde
`409 PAC_ORGANIZATION_MISMATCH` antes de cualquier HTTP o transición ERP
(orquestadores, controlador y provider). El provider rechaza además una
respuesta del PAC que traiga otra `organization`. El panel fiscal muestra
"Factura archivada" o "Factura de otra organización fiscal".

### Administración con la User Key (Fase 6.27b)

La User Key (`FACTURAPI_USER_KEY`, `sk_user_`) abre todas las organizaciones
de la cuenta. Vive sólo en `.env` (las pruebas la vacían en `phpunit.xml`) y
sólo la usa `FacturapiOrganizationClient`, con una lista cerrada de
operaciones (una prueba por reflexión falla si aparece otra): consultar,
buscar por nombre, crear, `PUT /legal`, `PUT /certificate` y obtener la llave
Test con `GET /apikeys/test`. Nunca renueva llaves (`PUT /apikeys/test`
invalidaría la actual), nunca usa llaves Live ni borra. Sin User Key:
`pac_user_key_missing`; con otro prefijo: `pac_user_key_invalid`; en ambos
casos no hay petición. Sólo en peticiones síncronas de un Admin y en
comandos, nunca en jobs.

Comandos del operador:

- `billing:facturapi-create-organization --company= [--name=] [--apply]
  [--resume] [--allow-duplicate-name]`: no hay endpoint. `POST /organizations`
  no es idempotente: se niega si la cuenta ya tiene una organización con ese
  nombre, toma un lock por empresa y guarda el id en cuanto Facturapi
  responde; si falla después, la fila queda sin llave (`pac_key_missing`) y
  `--resume --apply` obtiene y comprueba la llave Test sin volver a crear.
- `billing:facturapi-link --company= --replace-key [--from=user-key|env]
  [--apply]`: reemplaza sólo la llave Test de una empresa vinculada (nunca
  la organización), comprobada con `GET /organizations/me`.

Datos fiscales (`GET /api/company/fiscal`: Admin y Accountant;
`PUT`: sólo Admin, `throttle:company-fiscal`):

- `SatFiscalValidator::issuerIssues`: RFC obligatorio y no genérico,
  régimen aplicable al tipo de persona, C.P. de 5 dígitos y razón social
  sin régimen societario.
- Si la organización ya tiene RFC (Facturapi lo toma del CSD), el de la
  empresa debe ser ése (`COMPANY_RFC_CSD_MISMATCH`). Cambiar de RFC queda
  fuera de alcance.
- Facturapi primero: `PUT /legal` con todos los campos que tiene la empresa
  (el RFC no se envía) y sólo con 200 se guardan empresa, copia de la
  organización y auditoría. Un fallo no guarda nada y se registra sin
  secretos (`billing.company.fiscal_update_failed`).

CSD (`POST /api/company/csd`: sólo Admin, `throttle:company-csd`, 5 cada 10
minutos por empresa y por usuario):

- Antes de cualquier petición se lee el `.cer` en memoria
  (`CsdCertificateReader`) y se rechaza un certificado ilegible, una FIEL
  (sin sucursal en OU), uno vencido o uno de otro RFC: subirlo cambiaría el
  RFC de la organización sin forma de deshacerlo.
- Se reenvía con `PUT /certificate`; nunca se guardan los archivos ni la
  contraseña. La contraseña y la llave van marcadas `#[SensitiveParameter]`
  en todo el camino: con `zend.exception_ignore_args` apagado, las trazas de
  `laravel.log` incluirían los argumentos. `dontFlash` cubre
  `csd_password`, `cer` y `key`.

La empresa y su organización salen siempre del usuario autenticado:
`organization_id` o `company_id` en la petición (cuerpo o query) se rechazan.

Auditoría: `facturapi_organization_audits` (append-only) registra creación,
llave guardada o reemplazada, datos fiscales (campos cambiados) y CSD (serie,
vigencia, RFC), con el usuario desde la API o `source=console` desde
comandos; rechaza metadata con forma de llave.

Readiness de emisión (`InvoicePacReadinessService`, vía
`CompanyBillingReadinessService::issuerErrors`, con la copia local de la
organización): `COMPANY_FISCAL_DATA_INCOMPLETE`, `COMPANY_RFC_CSD_MISMATCH` y
`COMPANY_CSD_MISSING` (sólo fuera de `test`). Readiness de venta y de
emisión: `CLIENT_GENERIC_POSTAL_CODE_MISMATCH` (con RFC genérico, el C.P. del
cliente es el de la empresa, lugar de expedición).

### Llaves cifradas con APP_KEY

Las columnas cifradas usan `APP_KEY`: `facturapi_organizations.test_secret_key`,
`invoices.pac_response`, `invoices.pac_draft_response` e
`invoice_pac_events.context`. Perder `APP_KEY` las vuelve ilegibles; las
llaves Test se recuperan con `billing:facturapi-link --replace-key` (User
Key), las respuestas del PAC no.

Rotación de `APP_KEY` (Laravel descifra con `APP_KEY` y, si falla, con cada
llave de `APP_PREVIOUS_KEYS`, separadas por comas; cifra siempre con
`APP_KEY`):

1. Pasa la llave actual a `APP_PREVIOUS_KEYS` y pon la nueva en `APP_KEY`
   (`php artisan key:generate --show` la genera sin escribir el `.env`).
2. Limpia y vuelve a generar la caché de configuración y reinicia los
   workers de cola.
3. Corre `php artisan security:reencrypt` (dry-run: cuenta por columna lo
   cifrado con la llave actual, con una anterior y lo ilegible) y luego con
   `--apply`. Vuelve a cifrar con la llave actual, comprueba la ida y vuelta
   antes de escribir y no toca `updated_at`. Reescribe también
   `invoice_pac_events.context`, excepción documentada a su inmutabilidad:
   cambia el cifrado, no el contenido.
4. Retira la llave anterior de `APP_PREVIOUS_KEYS` sólo cuando el dry-run
   reporte 0 en "Llave anterior" e "Ilegibles"; si no, esas filas quedan
   ilegibles.

Respaldos: un respaldo de la base de datos junto con el `.env` (o cualquier
copia de `APP_KEY`) expone en claro todas las llaves de Facturapi y las
respuestas del PAC. Guárdalos por separado y con acceso restringido. Si ambos
se filtran, renueva la llave Test de cada organización en Facturapi (la
anterior queda invalidada), reemplázala con `billing:facturapi-link
--replace-key --apply`, rota `APP_KEY` y corre `security:reencrypt --apply`.
La User Key nunca está en la BD, pero sí en el `.env`: si éste se filtra,
trátala como comprometida y reemplázala desde la cuenta de Facturapi.

## Riesgos pendientes

### Resuelto en la Fase 6.22: revisión fiscal acoplada a los archivos

Hasta la Fase 6.21, `ReconcileInvoiceWithPacService` persistía
`pac_reconciliation_required = ! $artifactsStored` para un CFDI `valid`.
Reconciliar un CFDI vigente sin XML/PDF lo marcaba en revisión: la emisión
orquestada por la ruta del borrador `valid` respondía 409
`PAC_RECONCILIATION_REQUIRED` con el CFDI ya válido, "Cancelar factura"
desaparecía y descargar los archivos no limpiaba la marca. Ahora la marca
sólo depende del estado remoto (`valid` y `canceled` la liberan); la falta
de archivos la cubren `cfdi_artifacts_missing`, `can_fetch_artifacts` y la
descarga automática.

### Zona horaria de la conexión MySQL (no corregido)

`config/database.php` no fija `timezone` en la conexión `mysql`, así que
cada sesión usa la del servidor (`time_zone=SYSTEM`; en desarrollo,
`America/Mexico_City`). Laravel escribe y lee las columnas `TIMESTAMP` como
texto en UTC (`app.timezone=UTC`) y MySQL las convierte con la zona de la
sesión, de modo que el ida y vuelta desde Laravel es coherente, pero:

- un cliente SQL o una sesión con otra zona ve los valores desplazados;
- `NOW()`/`CURRENT_TIMESTAMP` en SQL devuelve hora de México, no UTC:
  cualquier comparación o default de base de datos queda 6 h corrido
  (`useCurrent()` existe en `invoice_pac_events.occurred_at/created_at` y
  `failed_jobs.failed_at`; `InvoicePacAuditService` fija ambas fechas
  desde PHP, por lo que hoy no se usa el default);
- fijar ahora `'timezone' => '+00:00'` desplazaría 6 h la lectura de todas
  las fechas ya guardadas.

Corregirlo exige una fase propia con migración de datos coordinada.
