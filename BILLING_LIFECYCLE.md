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
`actions.can_issue`, `actions.can_cancel`, `actions.can_reconcile` y
`actions.cancellation_mode`; no expone llaves, payloads, respuestas crudas ni
claves de idempotencia.

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
| `cfdi_canceled_erp_not_converged` | recoverable | operations_cancel |
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

Opciones: `--company=`, `--invoice=`, `--status=*` (por omisión los no
válidos), `--format=table|json`, `--limit=50` y `--fail-on=invalid`
(`transient|recoverable|invalid|none`). Sale con código 1 cuando algún estado
alcanza `--fail-on`, apto para cron o alertas. La salida sólo contiene IDs,
folio, estados y códigos: nunca payloads PAC, errores crudos, rutas, llaves de
idempotencia, `pac_external_id` ni `cfdi_uuid`.
