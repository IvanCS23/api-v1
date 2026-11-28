# Simulación: tablas después de la limpieza

> **Sólo es una simulación.** No hay migraciones ni cambios en la base de datos. Muestra cómo quedarían las tablas si se aplicaran las limpiezas marcadas con ✅ en [DATOS_MODIFICABLES_Y_DUPLICADOS.md](DATOS_MODIFICABLES_Y_DUPLICADOS.md).

Limpiezas incluidas:

- **D3:** impuestos del producto sólo en `product_taxes`.
- **D4:** sin `tax_rate_id` en las líneas de cotización y venta.
- **D5:** sin `tax_total` (queda `taxes_transferred`).
- **D7:** sin columnas que no se usan.
- **D11:** sin `legacy_invoices`.

Al final, como opción aparte, viene **D8** (tabla `invoice_files`).

Las tablas que no aparecen aquí quedan igual.

## Resumen

| Tabla | Columnas antes | Columnas después | Qué se quita |
|---|---:|---:|---|
| `companies` | 44 | 39 | `default_cfdi_series`, `expedition_place`, `fiscal_provider`, `sandbox`, `integration_status` |
| `users` | 24 | 21 | `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` |
| `products` | 21 | 15 | `iva`, `iva_retenido`, `ieps`, `isr`, `impuesto_local`, `tax_rate_id` |
| `product_taxes` | igual | igual | Nada; pasa a ser la **única** fuente de impuestos del producto. |
| `quotes`, `sales`, `invoices` | — | −1 cada una | `tax_total` |
| `quote_items`, `sale_items` | 18 | 16 | `tax_rate_id`, `tax_total` |
| `invoice_items` | — | −1 | `tax_total`. Las columnas `tax_*` se quedan por ahora (facturas anteriores a la 6.34). |
| `legacy_invoices` | 21 | — | La tabla completa, después de exportarla a un respaldo. |

---

## `companies`

| Campo | Tipo | Nota |
|---|---|---|
| `id` | bigint | |
| `uuid` | uuid, único | |
| `legal_name` | varchar | |
| `trade_name` | varchar | |
| `rfc` | varchar(13), único | |
| `plan` | varchar, nulo | |
| `fiscal_regime` | varchar(3) | |
| `email` | varchar, único, nulo | |
| `phone` | varchar, nulo | |
| `website` | varchar, nulo | |
| `logo` | varchar, nulo | |
| `logo_updated_at` | timestamp, nulo | |
| `status` | varchar | active / inactive / suspended |
| `country`, `state`, `city`, `municipality` | varchar, nulo | |
| `postal_code` | varchar(20), nulo | También es el lugar de expedición (por eso se quita `expedition_place`). |
| `colony`, `street`, `external_number`, `internal_number` | varchar, nulo | |
| `language` | varchar(5) | |
| `currency` | varchar(10) | |
| `timezone` | varchar | |
| `date_format`, `time_format` | varchar(20) | |
| `decimal_precision` | tinyint | |
| `primary_color`, `secondary_color` | varchar(7) | |
| `theme`, `mode` | varchar | |
| `default_cfdi_usage` | varchar(3), nulo | |
| `default_payment_form` | varchar(2), nulo | |
| `default_payment_method` | varchar(3), nulo | |
| `auto_email_invoices` | boolean | |
| `created_at`, `updated_at`, `deleted_at` | timestamp | |

~~`default_cfdi_series`~~ · ~~`expedition_place`~~ · ~~`fiscal_provider`~~ · ~~`sandbox`~~ · ~~`integration_status`~~

> El ambiente test/live sigue en `facturapi_organizations.pac_environment`. El estado de la conexión con el PAC se calcularía con `has_certificate` e `is_production_ready` de esa misma tabla.

## `users`

| Campo | Tipo |
|---|---|
| `id` | bigint |
| `company_id` | FK companies |
| `name` | varchar |
| `email` | varchar, único |
| `avatar` | varchar, nulo |
| `role` | varchar |
| `phone`, `position`, `branch` | varchar, nulo |
| `status` | varchar |
| `must_change_password` | boolean |
| `password_changed_at` | timestamp, nulo |
| `last_login_at` | timestamp, nulo |
| `last_login_ip` | varchar, nulo |
| `email_verified_at` | timestamp, nulo |
| `password` | varchar |
| `api_token` | varchar(80), único, nulo |
| `remember_token` | varchar, nulo |
| `created_at`, `updated_at`, `deleted_at` | timestamp |

~~`two_factor_secret`~~ · ~~`two_factor_recovery_codes`~~ · ~~`two_factor_confirmed_at`~~

---

## `products` y `product_taxes`

### `products` (después)

| Campo | Tipo |
|---|---|
| `id` | bigint |
| `company_id` | FK companies |
| `name` | varchar |
| `type` | varchar(20): product / service |
| `no_identificacion` | varchar, único por empresa, nulo |
| `descripcion` | varchar |
| `precio_unitario` | decimal(10,2) |
| `clave_producto` | varchar(8) |
| `clave_unidad` | varchar, nulo |
| `objeto_imp` | varchar, nulo |
| `cuenta_predial` | varchar, nulo |
| `no_pedimento` | varchar, nulo |
| `created_at`, `updated_at`, `deleted_at` | timestamp |

~~`iva`~~ · ~~`iva_retenido`~~ · ~~`ieps`~~ · ~~`isr`~~ · ~~`impuesto_local`~~ · ~~`tax_rate_id`~~

### `product_taxes` (sin cambios)

| Campo | Tipo |
|---|---|
| `id` | bigint |
| `company_id` | FK companies |
| `product_id` | FK products (se borra en cascada) |
| `tax` | varchar(3): 001 / 002 / 003 |
| `kind` | varchar(20): traslado / retencion |
| `factor` | varchar(20): tasa / cuota / exento |
| `rate` | decimal(12,6), nulo |
| `ieps_mode` | varchar(30), nulo |
| `position` | tinyint |
| `created_at`, `updated_at` | timestamp |

### Ejemplo con datos: "Desarrollo de software", IVA 16 % e ISR retenido 10 %

**Hoy** el mismo impuesto está en tres lugares:

`products`

| id | name | precio_unitario | iva | iva_retenido | ieps | isr | tax_rate_id |
|---|---|---:|---:|---:|---|---|---:|
| 7 | Desarrollo de software | 10000.00 | 16.00 | 10.00 | null | null | 1 |

`product_taxes`

| id | product_id | tax | kind | factor | rate | position |
|---|---:|---|---|---|---:|---:|
| 21 | 7 | 002 | traslado | tasa | 0.160000 | 0 |
| 22 | 7 | 001 | retencion | tasa | 0.100000 | 1 |

**Después**, sólo en un lugar:

`products`

| id | name | precio_unitario | clave_producto | clave_unidad | objeto_imp |
|---|---|---:|---|---|---|
| 7 | Desarrollo de software | 10000.00 | 81111500 | E48 | 02 |

`product_taxes`: las mismas dos filas de arriba.

---

## Cotizaciones, ventas y facturas (totales)

`quotes`, `sales` e `invoices` sólo pierden `tax_total`. Así quedarían las columnas de totales:

| Campo | Tipo | Significado |
|---|---|---|
| `subtotal` | decimal(14,2) | Suma de las líneas antes de impuestos. |
| `discount_total` | decimal(14,2) | Descuentos. |
| `taxes_transferred` | decimal(14,2) | Impuestos que se suman (IVA, IEPS). |
| `taxes_withheld` | decimal(14,2) | Impuestos que se restan (retenciones). |
| `total` | decimal(14,2) | `subtotal − discount_total + taxes_transferred − taxes_withheld` |

~~`tax_total`~~ (siempre era igual a `taxes_transferred`)

## `quote_items` y `sale_items` (después)

| Campo | Tipo |
|---|---|
| `id` | bigint |
| `company_id` | FK companies |
| `quote_id` / `sale_id` | FK al documento |
| `product_id` | FK products, nulo (sólo para rastrear el origen) |
| `description` | varchar |
| `quantity` | decimal(12,3) |
| `unit_price` | decimal(14,2) |
| `discount` | decimal(14,2) |
| `subtotal` | decimal(14,2) |
| `subtotal_exact` | decimal(18,6), nulo |
| `taxes_transferred` | decimal(14,2) |
| `taxes_withheld` | decimal(14,2) |
| `total` | decimal(14,2) |
| `created_at`, `updated_at`, `deleted_at` | timestamp |

~~`tax_rate_id`~~ · ~~`tax_total`~~

Los impuestos de cada línea siguen en `quote_item_taxes` / `sale_item_taxes`, igual que hoy.

### Ejemplo con datos: venta VTA-00000042, una línea del producto 7

`sales`

| id | folio | status | subtotal | discount_total | taxes_transferred | taxes_withheld | total |
|---|---|---|---:|---:|---:|---:|---:|
| 42 | VTA-00000042 | confirmed | 10000.00 | 0.00 | 1600.00 | 1000.00 | 10600.00 |

`sale_items`

| id | sale_id | product_id | description | quantity | unit_price | subtotal | subtotal_exact | taxes_transferred | taxes_withheld | total |
|---|---:|---:|---|---:|---:|---:|---:|---:|---:|---:|
| 88 | 42 | 7 | Desarrollo de software | 1.000 | 10000.00 | 10000.00 | 10000.000000 | 1600.00 | 1000.00 | 10600.00 |

`sale_item_taxes`

| id | sale_item_id | tax | kind | factor | rate | base | amount | amount_rounded |
|---|---:|---|---|---|---:|---:|---:|---:|
| 140 | 88 | 002 | traslado | tasa | 0.160000 | 10000.000000 | 1600.000000 | 1600.00 |
| 141 | 88 | 001 | retencion | tasa | 0.100000 | 10000.000000 | 1000.000000 | 1000.00 |

Hoy esa misma venta tendría además `tax_total = 1600.00` en `sales` y en `sale_items`, y `tax_rate_id = 1` en la línea. Ambos datos repiten lo que ya está en las filas de arriba.

## `invoice_items`

Sólo pierde `tax_total`. **Se quedan por ahora** `tax_rate_id`, `tax_code`, `tax_name`, `tax_rate_value`, `tax_type` y `tax_factor_type`, porque las facturas anteriores a la 6.34 sin filas en `invoice_item_taxes` todavía se envían a Facturapi con ellas. Se podrán quitar cuando todas las facturas viejas tengan sus filas.

## `legacy_invoices`

Se elimina después de exportarla:

```
mysqldump facturapro legacy_invoices > backups/legacy_invoices.sql
```

También se borran `LegacyInvoice`, `LegacyInvoiceController` y la ruta `/api/legacy-invoices`.

---

## Opcional (D8): archivos de la factura en su propia tabla

Hoy `invoices` tiene 18 columnas para los archivos. Con esta opción pasarían a una tabla aparte.

### `invoice_files` (nueva)

| Campo | Tipo | Nota |
|---|---|---|
| `id` | bigint | |
| `company_id` | FK companies | |
| `invoice_id` | FK invoices | |
| `type` | varchar(40) | cfdi_xml / cfdi_pdf / cancellation_receipt_xml / cancellation_receipt_pdf |
| `path` | varchar | Ruta en `storage/app/private/...` |
| `sha256` | char(64) | |
| `size` | bigint | Bytes. |
| `downloaded_at` | timestamp | |
| `created_at`, `updated_at` | timestamp | |
| | único (`invoice_id`, `type`) | Un archivo de cada tipo por factura. |

En `invoices` se quedarían sólo las columnas de **control de descarga**, porque manejan reintentos:

| Se queda en `invoices` | Se va a `invoice_files` |
|---|---|
| `cfdi_artifacts_status`, `cfdi_artifacts_started_at`, `cfdi_artifacts_last_error` | `cfdi_xml_path`, `cfdi_pdf_path`, `cfdi_xml_sha256`, `cfdi_pdf_sha256`, `cfdi_xml_size`, `cfdi_pdf_size`, `cfdi_artifacts_downloaded_at` |
| `cancellation_receipt_status`, `cancellation_receipt_started_at`, `cancellation_receipt_last_error` | `cancellation_receipt_xml_path`, `cancellation_receipt_pdf_path`, `cancellation_receipt_xml_sha256`, `cancellation_receipt_pdf_sha256`, `cancellation_receipt_xml_size`, `cancellation_receipt_pdf_size`, `cancellation_receipt_downloaded_at` |

### Ejemplo con datos: factura 19 timbrada y luego cancelada

| id | invoice_id | type | path | size |
|---|---:|---|---|---:|
| 1 | 19 | cfdi_xml | cfdi/1/19/cfdi.xml | 4821 |
| 2 | 19 | cfdi_pdf | cfdi/1/19/cfdi.pdf | 61234 |
| 3 | 19 | cancellation_receipt_xml | cfdi/1/19/acuse.xml | 1290 |
| 4 | 19 | cancellation_receipt_pdf | cfdi/1/19/acuse.pdf | 38110 |

`invoices` pasaría de 18 columnas de archivos a 6. La misma tabla serviría más adelante para los XML y PDF de los pagos (REP), con `payment_id` en lugar de `invoice_id` o con una tabla `payment_files` igual a esta.
