# Datos modificables y datos duplicados

Complemento de [BASE_DE_DATOS.md](BASE_DE_DATOS.md). Responde dos preguntas:

1. **¿Qué datos se pueden modificar?** Desde la aplicación (API), desde la consola o de ninguna forma.
2. **¿Qué datos están repetidos en varias tablas y cuáles se pueden juntar?**

Basado en las reglas de validación (`app/Http/Requests`), las rutas (`routes/api.php`) y los métodos `isEditable()` de los modelos.

---

## Parte 1. Qué se puede modificar

Hay tres niveles:

- ✏️ **Editable:** el usuario lo cambia desde la aplicación.
- ⚙️ **Lo cambia el sistema:** se actualiza solo, al confirmar, timbrar, cancelar, etc. Nadie lo edita a mano.
- 🔒 **Fijo:** una vez guardado no cambia (copias congeladas, bitácoras) o no tiene pantalla ni endpoint.

### Catálogos (se editan siempre)

| Tabla | ✏️ Editable | ⚙️ / 🔒 |
|---|---|---|
| `clients` | Todo: `name`, los 3 correos, `rfc`, `codigo_postal`, `regimen_fiscal`, `uso_cfdi` y el domicilio. | `company_id` 🔒 |
| `products` | `name`, `type`, `no_identificacion`, `descripcion`, `precio_unitario`, `clave_producto`, `clave_unidad`, `objeto_imp`, `tax_rate_id`, `cuenta_predial`, `no_pedimento` y los impuestos (`product_taxes`). | `iva`, `iva_retenido`, `ieps`, `isr`, `impuesto_local`: la API todavía los acepta, pero ya no se usan para calcular (ver duplicado D3). |
| `product_taxes` | Se reemplazan completos al guardar el producto (con `taxes`, o con `tax_rate_id` desde la pantalla actual). | — |
| `employes` | Todos sus campos. | `company_id` 🔒 |

> Editar un cliente o un producto **no** cambia las facturas ya hechas: cada factura guardó su propia copia de esos datos.

### Usuarios

| Qué | Cómo |
|---|---|
| `name`, `email`, `role`, `status`, `phone`, `position`, `branch`, `avatar` | ✏️ Un admin, desde Usuarios. |
| `password` | ✏️ Con endpoints aparte: el usuario la cambia en su perfil, o un admin la cambia o la restablece. |
| `must_change_password` | ✏️ Con su propio endpoint (sólo admin). |
| `password_changed_at`, `last_login_at`, `last_login_ip`, `api_token`, `remember_token` | ⚙️ Las llena el sistema. |
| `two_factor_*`, `email_verified_at` | 🔒 Existen, pero no hay pantalla que los use. |

### Empresa

| Qué | Cómo |
|---|---|
| `legal_name`, `trade_name`, `rfc`, `fiscal_regime`, `postal_code`, `street`, `external_number`, `internal_number`, `colony`, `city`, `municipality`, `state`, `email` | ✏️ Pantalla **Datos fiscales**. Primero se envían a Facturapi y **sólo si Facturapi acepta** se guardan aquí. El RFC tiene que coincidir con el del CSD. |
| `auto_email_invoices` | ✏️ Pantalla **Correo**. |
| `logo`, `logo_updated_at` | ✏️ Al subir el logotipo. |
| CSD (certificado) | ✏️ Se sube a Facturapi; aquí sólo queda la copia en `facturapi_organizations`. |
| `language`, `currency`, `timezone`, `date_format`, `time_format`, `decimal_precision`, `primary_color`, `secondary_color`, `theme`, `mode`, `default_cfdi_series`, `default_cfdi_usage`, `default_payment_form`, `default_payment_method`, `expedition_place`, `plan`, `status`, `phone`, `website`, `country` | 🔒 **No hay endpoint para editarlos.** Sólo se cambian directo en la base de datos. |
| `fiscal_provider`, `sandbox`, `integration_status` | 🔒 Sin uso real (ver D6). |

### Facturapi (`facturapi_organizations`)

| Qué | Cómo |
|---|---|
| `pac_environment` (test/live) | ⚙️ Sólo desde la consola: `billing:pac-environment`. |
| `live_secret_key` | ⚙️ Sólo desde la consola: `billing:facturapi-live-key`. |
| `test_secret_key` | ⚙️ La captura el operador al vincular la organización. |
| `legal_*`, `has_certificate`, `certificate_*`, `has_logo`, `is_production_ready`, `pending_steps`, `snapshot_at` | ⚙️ Copia de lo que responde Facturapi. Se refresca al guardar Datos fiscales, subir el CSD o el logotipo, o cambiar de ambiente. |

### Documentos: se pueden editar sólo mientras están abiertos

| Tabla | Editable mientras... | ✏️ Qué se edita | Después |
|---|---|---|---|
| `quotes` | esté en `draft` o `sent` | `client_id`, `notes` y sus líneas. `currency` sólo al crearla. | 🔒 Aprobada, rechazada, vencida o convertida ya no se edita. |
| `quote_items` | la cotización sea editable | `product_id`, `description`, `quantity`, `unit_price`, `discount` y los impuestos de la línea. | ⚙️ `subtotal`, `subtotal_exact`, `tax_total`, `taxes_*` y `total` se calculan solos. |
| `sales` | esté en `draft` o `pending` | `client_id`, `notes`, `payment_form`, `payment_method` y sus líneas. `currency` sólo al crearla. | 🔒 Confirmada o cancelada ya no se edita. |
| `sale_items` | la venta sea editable | Igual que `quote_items`. | ⚙️ Totales calculados. |
| `invoices` | esté en `draft` o `ready` | **Sólo `notes`.** Todo lo demás se copió de la venta. | 🔒 Emitida o cancelada no se toca. Los campos `pac_*`, `cfdi_*` y `cancellation_*` ⚙️ los llena el sistema. |
| `invoice_items`, `invoice_item_taxes` | — | Nada. | 🔒 Copia congelada desde que nace la factura. |
| `payments` | esté en `draft` | Todo: `client_id`, `payment_date`, `payment_form`, `amount`, `operation_number`, `notes` y el reparto entre facturas (se reemplaza completo). | 🔒 Timbrado ya no se edita, sólo se cancela. |
| `payment_allocations`, `payment_allocation_taxes` | el pago sea borrador | Se regeneran al editar el pago. | 🔒 Al timbrar quedan congeladas. |

En todos los documentos, `status`, `folio` y las fechas de flujo (`confirmed_at`, `approved_at`, `issued_at`, `cancelled_at`...) ⚙️ sólo cambian con las acciones (confirmar, aprobar, emitir, cancelar), nunca editando directamente.

### Nunca se modifican (sólo se agregan filas)

`audit_logs`, `invoice_pac_events`, `facturapi_organization_audits`. Son bitácoras: si se pudieran editar, dejarían de servir como prueba de lo que pasó.

### Catálogos globales

`tax_rates`, `payment_channels`, `sat_product_keys`, `sat_unit_keys`: ⚙️ se cargan con seeders o con `php artisan sat:import-catalogs`. No hay pantalla para editarlos.

---

## Parte 2. Datos duplicados: ¿se pueden juntar?

No todo lo repetido es un error. Hay dos tipos:

- **Duplicado a propósito (copia congelada):** el dato se copia para que **no cambie** aunque cambie el original. Es obligatorio por razones fiscales: **no se debe juntar.**
- **Duplicado real (redundancia):** el mismo dato vive en dos lugares y hay que mantener ambos iguales. **Este sí se puede limpiar.**

### Resumen

| # | Dato repetido | Tipo | ¿Juntar? |
|---|---|---|---|
| D1 | Datos del cliente en `invoices` y `payments` (`client_*`) | Copia congelada | ❌ No |
| D2 | Datos del producto y de la factura (`invoice_items.product_*`, `payment_allocations.invoice_*`) | Copia congelada | ❌ No |
| D3 | IVA del producto en 3 lugares: `products.iva...`, `products.tax_rate_id` y `product_taxes` | Redundancia | ✅ Sí, con cuidado |
| D4 | Impuesto único en las líneas (`tax_rate_id`, `invoice_items.tax_*`) frente a las tablas `*_item_taxes` | Redundancia heredada | ⚠️ Parcial |
| D5 | `tax_total` y `taxes_transferred` | Redundancia | ✅ Sí |
| D6 | Datos de la empresa en `companies` y `facturapi_organizations.legal_*` | Copia del PAC | ❌ No |
| D7 | Columnas sin uso en `companies` y `users` | Basura | ✅ Sí |
| D8 | 18 columnas de archivos en `invoices` (XML/PDF del CFDI y del acuse) | Estructura repetida | ⚠️ Opcional |
| D9 | `quotes` / `sales` / `invoices` y sus líneas tienen la misma forma | Estructura repetida | ❌ No conviene |
| D10 | Cuatro tablas de impuestos por línea con la misma forma | Estructura repetida | ❌ No conviene |
| D11 | `legacy_invoices` | Tabla vieja | ✅ Se puede archivar |
| D12 | `pac_reconciliation_required` y `pac_issue_status = reconciliation_required` | Redundancia | ⚠️ Opcional |

---

### D1. Datos del cliente copiados en `invoices` y `payments` — ❌ no juntar

`invoices.client_name`, `client_rfc`, `client_regimen_fiscal`, `client_uso_cfdi`, `client_codigo_postal` y el domicilio repiten lo que hay en `clients`. Lo mismo `payments.client_*`.

**Por qué se queda así:** un CFDI timbrado es un documento legal que no se puede cambiar. Si la factura leyera el RFC desde `clients`, corregir el RFC de un cliente "reescribiría" facturas ya entregadas al SAT. Mostrarías un RFC distinto al del XML y no podrías reconstruir el documento original.

### D2. Copias del producto y de la factura — ❌ no juntar

- `invoice_items.product_clave_producto`, `product_clave_unidad`, `product_type`, `product_no_identificacion`, `product_description` y `product_objeto_imp` repiten `products`.
- `payment_allocations.invoice_uuid`, `invoice_series`, `invoice_folio_number`, `invoice_currency` e `invoice_total` repiten `invoices`.

Misma razón que D1: es lo que quedó timbrado. Si alguien cambia la clave SAT del producto mañana, la factura de ayer debe seguir diciendo la clave con la que se emitió.

### D3. El IVA del producto está en tres lugares — ✅ se puede limpiar

| Lugar | Estado actual |
|---|---|
| `products.iva`, `iva_retenido`, `ieps`, `isr`, `impuesto_local` | Esquema viejo. La API **todavía los acepta**, pero no se usan para calcular. |
| `products.tax_rate_id` | Lo manda la pantalla actual de Productos. Al guardar se convierte en una fila de `product_taxes`. |
| `product_taxes` | **La fuente real.** De aquí salen los impuestos de las líneas. |

**Riesgo actual:** si alguien edita `iva` o `iva_retenido` por la API, el dato se guarda pero no tiene ningún efecto. Eso confunde.

**Propuesta, en dos pasos:**

1. Que la pantalla de Productos mande `taxes` (la lista completa de impuestos) en lugar de `tax_rate_id`. Así también se pueden capturar retenciones desde la pantalla.
2. Dejar de aceptar `iva`, `iva_retenido`, `ieps`, `isr` e `impuesto_local` en `StoreProductRequest` y `UpdateProductRequest`. Después, con un respaldo hecho, borrar esas columnas y `tax_rate_id` de `products`. Ojo: `LineTaxBackfill` todavía lee `iva_retenido`; hay que retirarlo antes.

Resultado: los impuestos del producto vivirían en **un solo lugar**, `product_taxes`.

### D4. Impuesto único en las líneas — ⚠️ limpiar sólo hacia adelante

`quote_items.tax_rate_id`, `sale_items.tax_rate_id`, `invoice_items.tax_rate_id` y el bloque `invoice_items.tax_code`, `tax_name`, `tax_rate_value`, `tax_type`, `tax_factor_type` son del esquema viejo de "un impuesto por línea". Hoy los impuestos están en `*_item_taxes`.

**Ojo:** `FacturapiProvider` todavía usa `invoice_items.tax_*` como respaldo para las facturas **anteriores a la fase 6.34** que no tienen filas en `invoice_item_taxes`. Por eso:

- En `quote_items` y `sale_items` se puede quitar `tax_rate_id` en cuanto la pantalla mande `taxes`.
- En `invoice_items` **no** se pueden borrar todavía. Primero hay que confirmar que todas las facturas viejas tienen sus filas en `invoice_item_taxes` (el comando `BackfillLineTaxes` sirve para eso). Sólo entonces se retira el respaldo y se borran las columnas.

### D5. `tax_total` y `taxes_transferred` — ✅ se puede limpiar

En `quotes`, `sales`, `invoices` y sus líneas, `tax_total` vale **siempre** lo mismo que `taxes_transferred`. Se conservó para no romper pantallas ni reportes que ya leían `tax_total`.

**Propuesta:** que el frontend lea `taxes_transferred` y, cuando ya nadie use `tax_total`, quitar la columna. Es un cambio de bajo riesgo, pero toca muchas pantallas: no da beneficio funcional, sólo orden.

### D6. Datos fiscales de la empresa en dos tablas — ❌ no juntar

`companies` (`legal_name`, `rfc`, `fiscal_regime`, `postal_code`, `email`) y `facturapi_organizations` (`legal_name`, `legal_tax_id`, `legal_tax_system`, `legal_zip`, `legal_support_email`) se parecen, pero significan cosas distintas:

- `companies` es **lo que capturaste** en FacturaPro.
- `facturapi_organizations.legal_*` es **lo que Facturapi tiene registrado**, tal cual lo respondió.

Tener los dos permite detectar si no coinciden. Por ejemplo, en Live la emisión exige que `companies.email` sea igual a `legal_support_email`, y el RFC tiene que ser el del CSD. Si se juntaran, ya no podrías ver esa diferencia.

### D7. Columnas sin uso — ✅ se pueden quitar

En el código del backend sólo aparecen en el modelo; ninguna lógica las lee:

| Columna | Por qué sobra |
|---|---|
| `companies.sandbox` | El ambiente real es `facturapi_organizations.pac_environment`. Además puede contradecirlo. |
| `companies.fiscal_provider` | Siempre es Facturapi. |
| `companies.integration_status` | Sólo se devuelve en `CompanyResource`; nada lo actualiza. El estado real se ve en `facturapi_organizations` (`has_certificate`, `is_production_ready`). |
| `companies.default_cfdi_series`, `expedition_place` | Sin uso. La serie es fija (`FAC`, `PAG`) y el lugar de expedición sale de `postal_code`. |
| `users.two_factor_*` | No hay verificación en dos pasos implementada. |

**Antes de quitarlas:** `integration_status` sí lo muestra el frontend (`app-front/app/components/settings/SettingsStats.tsx`, que siempre verá `pending`). Hay que cambiar esa tarjeta para que lea el estado de `facturapi_organizations` antes de borrar la columna. Las demás no aparecen en el frontend.

### D8. Columnas de archivos en `invoices` — ⚠️ opcional

`invoices` tiene dos bloques casi iguales de 9 columnas: `cfdi_xml_*`/`cfdi_pdf_*` y `cancellation_receipt_xml_*`/`cancellation_receipt_pdf_*` (ruta, sha256, tamaño, estado, fechas, error).

**Alternativa:** una tabla `invoice_files` con una fila por archivo (`invoice_id`, `type`, `path`, `sha256`, `size`). El enum `InvoiceArtifactType` ya define los 4 tipos: `cfdi_xml`, `cfdi_pdf`, `cancellation_receipt_xml` y `cancellation_receipt_pdf`. Además serviría para los archivos del REP de `payments`, que hoy no tiene estas columnas.

**Pero:** el estado de descarga (`*_status`, `*_started_at`) controla reintentos y concurrencia, y lo usan varios servicios. Es un cambio grande para ganar sólo orden. Conviene hacerlo **sólo** cuando se necesite guardar archivos del REP.

### D9. Cotización, venta y factura con la misma forma — ❌ no conviene juntar

`quotes`, `sales` e `invoices` (y sus `*_items`) comparten columnas: cliente, folio, status, totales, moneda y notas. Se podría hacer una sola tabla `documents` con una columna `type`, pero:

- Cada una tiene **estados distintos** (la cotización tiene `approved` y `expired`; la venta, `confirmed`; la factura, `issued`).
- `invoices` tiene más de 80 columnas del PAC que no aplican a las otras.
- Cada etapa es una copia de la anterior a propósito: convertir no modifica la cotización, y facturar no modifica la venta.

Juntarlas mezclaría reglas y llenaría la tabla de columnas vacías. Se quedan separadas.

### D10. Cuatro tablas de impuestos por línea — ❌ no conviene juntar

`product_taxes`, `quote_item_taxes`, `sale_item_taxes` e `invoice_item_taxes` (y `payment_allocation_taxes`) tienen la misma forma. Se podría hacer una sola tabla "polimórfica" (`owner_type` + `owner_id`), pero se perderían las llaves foráneas reales. Hoy, si se borra una línea, sus impuestos se borran solos (`cascadeOnDelete`). Con una tabla única eso dependería del código. El modelo ya comparte la lógica (`IsLineTax`), así que repetir la estructura no cuesta nada.

### D11. `legacy_invoices` — ✅ se puede archivar

Es la tabla del prototipo anterior. Sólo la usa `LegacyInvoiceController` (ruta `/api/legacy-invoices`). Si nadie la consulta, se puede exportar a un respaldo y borrar junto con su controlador y su modelo.

### D12. Dos banderas de "hay que revisar con el PAC" — ⚠️ opcional

`invoices.pac_reconciliation_required` (sí/no) y `invoices.pac_issue_status = 'reconciliation_required'` dicen casi lo mismo. La bandera también se activa por casos de **cancelación**, no sólo de emisión, y la leen más de 10 servicios. Unificarla es posible, pero tiene riesgo alto y poco beneficio. Recomendación: dejarla.

---

## Orden sugerido si decides limpiar

Del más seguro al más delicado. Haz un respaldo de la base antes de cada paso y corre la suite completa.

1. **D7:** quitar columnas sin uso (después de revisar el frontend).
2. **D11:** archivar `legacy_invoices`.
3. **D3:** que la pantalla de Productos mande `taxes`; dejar de aceptar y luego borrar `iva`, `iva_retenido`, `ieps`, `isr`, `impuesto_local` y `tax_rate_id` en `products`.
4. **D4:** quitar `tax_rate_id` de `quote_items` y `sale_items`.
5. **D5:** migrar el frontend a `taxes_transferred` y quitar `tax_total`.
6. **D4 (facturas)** y **D8:** sólo con una razón concreta.

**No tocar nunca:** D1, D2, D6 (copias fiscales) ni las bitácoras.
