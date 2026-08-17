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
