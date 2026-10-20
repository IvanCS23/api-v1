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

## Endpoints compatibles

- `/invoices/{id}/ready`: ERP local, vigente.
- `/invoices/{id}/issue`: ERP local legacy; no PAC.
- `/invoices/{id}/cancel`: ERP local legacy; no PAC.
- `/invoices/{invoice}/pac/issue`: operación PAC de bajo nivel conservada.
- `/invoices/{invoice}/pac/cancel`: operación PAC de bajo nivel conservada.

Los consumidores de UI nuevos deben usar `/operations/issue` y
`/operations/cancel`. `GET /api/invoices/{invoice}/billing` publica
`actions.can_issue`, `actions.can_cancel`, `actions.can_reconcile`,
`actions.can_fetch_artifacts` y `actions.cancellation_mode`; no expone
llaves, payloads, respuestas crudas ni claves de idempotencia.

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

## Seguridad de escrituras PAC

### Modo y llave

`billing.pac_mode` (`BILLING_PAC_MODE`, por omisión `test`) define qué llave
se acepta: `test` exige prefijo `sk_test_`; `live` (`sk_live_`) **no está
soportado** en esta fase. `PacCredentialGuard` se evalúa en
`FacturapiProvider::client()` antes de construir cualquier petición
(escrituras, consultas y descargas) y en los orquestadores antes de cualquier
transición ERP. Una configuración inválida lanza `PacConfigurationException`
(fallo definitivo, `503 PAC_CONFIGURATION_INVALID`): el PAC nunca recibe la
petición y el ERP no cambia.

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
| `cfdi_artifacts_missing` | valid (informativo) | fetch_artifacts |
| `receipt_without_cancellation` (acuse sin ninguna solicitud registrada) | valid (informativo) | manual_review |
| `remote_draft_date_window_risk` | transient (≥48 h) / recoverable (≥72 h) | operations_issue / manual_review |
| `erp_cancelled_before_pac_confirmation` (ERP `cancelled`, CFDI `valid`, cancelación `pending`/`verifying`) | invalid | manual_review |
| `identity_uuid_without_external_id`, `erp_behind_cfdi`, `issue_succeeded_without_valid_cfdi`, `pac_status_without_identity`, `idempotency_key_mismatch` | invalid | manual_review |

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

## Riesgos pendientes

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
