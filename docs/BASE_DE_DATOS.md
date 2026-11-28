# Base de datos de FacturaPro

Este documento dice para qué sirve cada tabla y cada columna. Está basado en las migraciones de `database/migrations` (hasta `2026_11_20_010000_create_payments_tables`), en los enums de `app/Enums` y en los seeders.

## Ideas generales

- **Multiempresa.** Casi todas las tablas de negocio tienen `company_id`. Cada empresa sólo ve sus propios datos, porque los modelos aplican `CompanyScope`. Por eso los valores únicos (RFC, folio, correo...) son únicos **por empresa** y no en toda la base.
- **Snapshot (copia congelada).** Cuando se emite una factura o un pago, se copian a sus propias columnas los datos fiscales del cliente, del producto y de los impuestos. Si después alguien cambia el RFC del cliente o el precio del producto, la factura ya emitida no cambia. Los `product_id`, `tax_rate_id` y `client_id` que conservan esas tablas sólo sirven para rastrear el origen.
- **Borrado suave (`deleted_at`).** Si tiene valor, el registro está "borrado": no aparece en la aplicación, pero sigue guardado.
- **`created_at` / `updated_at`.** Fecha de creación y de última modificación. Todas las fechas se guardan en UTC.
- **Campos `pac_*`.** El PAC es el proveedor que timbra los CFDI ante el SAT; en este proyecto es **Facturapi**. Los campos con este prefijo registran lo que pasa con él.
- **Montos.** Los totales usan 2 decimales. Los importes fiscales exactos por concepto usan 6, como pide el Anexo 20 del SAT.

## Flujo principal

```
companies ─┬─ users
           ├─ clients ─┐
           ├─ products ┤
           │           ▼
           │  quotes (cotización) ──convierte──▶ sales (venta) ──factura──▶ invoices (CFDI I)
           │     └ quote_items                     └ sale_items                └ invoice_items
           │        └ quote_item_taxes                └ sale_item_taxes           └ invoice_item_taxes
           │                                                                        ▲
           │  payments (pago / REP, CFDI P) ─▶ payment_allocations ─────────────────┘
           │                                     └ payment_allocation_taxes
           └─ facturapi_organizations (cuenta en el PAC)
```

---

## 1. Empresa, usuarios y seguridad

### `companies` — empresas (los clientes del sistema)

Cada empresa que usa FacturaPro. Es el "inquilino": todo lo demás cuelga de ella.

| Campo | Para qué sirve |
|---|---|
| `id` | Identificador interno. |
| `uuid` | Identificador público único (no expone el `id` numérico). |
| `legal_name` | Razón social. |
| `trade_name` | Nombre comercial. |
| `rfc` | RFC de la empresa (único). |
| `plan` | Plan contratado. |
| `fiscal_regime` | Régimen fiscal SAT (catálogo c_RegimenFiscal, 3 dígitos; ej. `601`). |
| `email` | Correo de contacto (único). En modo Live debe coincidir con el correo de soporte de la organización en Facturapi. |
| `phone`, `website` | Teléfono y sitio web. |
| `logo` | Ruta privada de la copia del logotipo subido. |
| `logo_updated_at` | Fecha en que se subió el logotipo. |
| `status` | `active`, `inactive` o `suspended`. |
| `country`, `state`, `city`, `municipality`, `postal_code`, `colony`, `street`, `external_number`, `internal_number` | Domicilio. |
| `language` | Idioma: `es`, `en` o `pt`. |
| `currency` | Moneda por defecto: `MXN`, `USD` o `EUR`. |
| `timezone` | Zona horaria (por defecto `America/Mexico_City`). |
| `date_format`, `time_format` | Formato de fecha y hora en pantalla. |
| `decimal_precision` | Decimales que se muestran (por defecto 2). |
| `primary_color`, `secondary_color` | Colores de la marca en la interfaz. |
| `theme` | Tema visual: `modern`, `classic` o `minimal`. |
| `mode` | Modo de color: `light`, `dark` o `system`. |
| `default_cfdi_series` | Serie por defecto de los CFDI. |
| `default_cfdi_usage` | Uso CFDI por defecto (c_UsoCFDI; ej. `G03`). |
| `default_payment_form` | Forma de pago por defecto (c_FormaPago; ej. `03` = transferencia). Se usa si la venta no trae una. |
| `default_payment_method` | Método de pago por defecto: `PUE` (una sola exhibición) o `PPD` (parcialidades o diferido). |
| `auto_email_invoices` | Si está activo, envía el CFDI por correo al cliente en cuanto se timbra. Apagado por defecto. |
| `expedition_place` | Lugar de expedición (código postal). |
| `fiscal_provider` | Proveedor fiscal: `sat`, `pac` o `custom`. |
| `sandbox` | Bandera de modo de pruebas (heredada; el ambiente real ahora vive en `facturapi_organizations.pac_environment`). |
| `integration_status` | Estado de la integración: `pending`, `connected`, `disconnected` o `error`. |
| `created_at`, `updated_at`, `deleted_at` | Fechas estándar y borrado suave. |

### `users` — usuarios

Personas que entran al sistema. Cada una pertenece a una empresa.

| Campo | Para qué sirve |
|---|---|
| `id` | Identificador. |
| `company_id` | Empresa a la que pertenece. Si se borra la empresa, se borran sus usuarios. |
| `name` | Nombre. |
| `email` | Correo para iniciar sesión (único en todo el sistema). |
| `avatar` | Foto de perfil. |
| `role` | Rol: `admin`, `accountant` (contador), `sales` (ventas) o `employee`. Decide qué puede hacer. |
| `phone`, `position`, `branch` | Teléfono, puesto y sucursal. |
| `status` | `active`, `inactive` o `suspended`. Un usuario inactivo no puede entrar. |
| `email_verified_at` | Cuándo verificó su correo. |
| `password` | Contraseña cifrada (hash). |
| `api_token` | Token de acceso a la API (heredado). |
| `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` | Verificación en dos pasos: secreto, códigos de respaldo y fecha de activación. |
| `must_change_password` | Si está activo, obliga a cambiar la contraseña al entrar (por ejemplo, con una contraseña temporal). |
| `password_changed_at` | Último cambio de contraseña. |
| `last_login_at`, `last_login_ip` | Último inicio de sesión y desde qué IP. |
| `remember_token` | Para la opción "recordarme". |
| `created_at`, `updated_at`, `deleted_at` | Fechas estándar y borrado suave. |

### `password_reset_tokens` — recuperación de contraseña

| Campo | Para qué sirve |
|---|---|
| `email` | Correo que pidió restablecer la contraseña. |
| `token` | Código del enlace de recuperación (cifrado). |
| `created_at` | Fecha de solicitud; sirve para que el enlace caduque. |

### `sessions` — sesiones activas

| Campo | Para qué sirve |
|---|---|
| `id` | Identificador de la sesión. |
| `user_id` | Usuario dueño de la sesión. |
| `ip_address`, `user_agent` | IP y navegador. |
| `payload` | Datos de la sesión. |
| `last_activity` | Última actividad (para saber cuándo expira). |

### `audit_logs` — bitácora de seguridad

Registra acciones sensibles sobre usuarios y pagos. Nunca se modifica, sólo se agregan filas.

| Campo | Para qué sirve |
|---|---|
| `company_id` | Empresa. |
| `user_id` | Usuario afectado. |
| `actor_id` | Usuario que hizo la acción (puede ser otro, por ejemplo un admin). |
| `action` | Qué pasó: `password_changed`, `password_reset`, `sessions_revoked`, `activated`, `deactivated`, `profile_updated`, `sale_totals_recalculated`, `payment_issued` o `payment_cancellation_requested`. |
| `ip_address`, `user_agent` | Desde dónde se hizo. |
| `metadata` | Detalles extra en JSON. |
| `created_at` | Cuándo. |

---

## 2. Catálogos de la empresa

### `clients` — clientes (receptores de las facturas)

| Campo | Para qué sirve |
|---|---|
| `company_id` | Empresa dueña del cliente. |
| `name` | Razón social o nombre, tal como está en la Constancia de Situación Fiscal. |
| `email` | Correo principal (único por empresa). Destino del CFDI por correo. |
| `email_optional_1`, `email_optional_2` | Dos correos extra (por ejemplo, el del contador). También reciben el CFDI. |
| `rfc` | RFC (único por empresa). |
| `codigo_postal` | C.P. fiscal: el SAT lo valida contra el RFC. |
| `regimen_fiscal` | Régimen fiscal del cliente (c_RegimenFiscal). |
| `uso_cfdi` | Uso que le dará al CFDI (c_UsoCFDI; ej. `G03` = gastos en general). |
| `calle`, `no_exterior`, `no_interior`, `colonia`, `localidad`, `municipio`, `estado`, `pais` | Domicilio. |
| `created_at`, `updated_at`, `deleted_at` | Fechas estándar y borrado suave. |

### `products` — productos y servicios

| Campo | Para qué sirve |
|---|---|
| `company_id` | Empresa. |
| `name` | Nombre comercial. Es el texto que aparece en la línea de la venta. |
| `type` | `product` o `service`. |
| `no_identificacion` | SKU o código interno (único por empresa). |
| `descripcion` | Descripción detallada. |
| `precio_unitario` | Precio sin impuestos. |
| `clave_producto` | Clave SAT c_ClaveProdServ (8 dígitos). Varios productos pueden compartirla. |
| `clave_unidad` | Clave SAT c_ClaveUnidad (ej. `H87` pieza, `E48` servicio). |
| `objeto_imp` | Objeto de impuesto SAT: `01` no objeto, `02` sí objeto, `03` sí objeto pero no obligado al desglose. |
| `tax_rate_id` | Tasa de IVA del producto (16 %, 0 % o exento). Fuente de verdad del IVA desde la fase 6.26. |
| `cuenta_predial` | Número de cuenta predial (sólo arrendamiento de inmuebles). |
| `no_pedimento` | Número de pedimento aduanal (mercancía importada). |
| `iva`, `iva_retenido`, `ieps`, `isr`, `impuesto_local` | **Columnas viejas, congeladas.** Ya no se usan para calcular; los impuestos viven en `product_taxes`. |
| `created_at`, `updated_at`, `deleted_at` | Fechas estándar y borrado suave. |

### `product_taxes` — impuestos de cada producto

Los impuestos que se copian a la línea cuando agregas el producto a una cotización o venta. Un producto puede tener varios, por ejemplo IVA trasladado 16 % e ISR retenido 10 %.

| Campo | Para qué sirve |
|---|---|
| `company_id` | Empresa. |
| `product_id` | Producto. |
| `tax` | Impuesto (c_Impuesto): `001` ISR, `002` IVA o `003` IEPS. |
| `kind` | `traslado` (se cobra al cliente) o `retencion` (el cliente lo retiene). |
| `factor` | `tasa` (porcentaje), `cuota` (monto fijo por unidad) o `exento`. |
| `rate` | Valor con 6 decimales (ej. `0.160000`). Vacío si es exento. |
| `ieps_mode` | Sólo para IEPS: cómo se combina con el resto de los impuestos. |
| `position` | Orden en que se muestran y aplican. |

### `employes` — empleados (nómina)

Catálogo de empleados para nómina. Ningún otro módulo lo usa todavía.

| Campo | Para qué sirve |
|---|---|
| `company_id` | Empresa. |
| `email`, `nombre`, `apellido_paterno`, `apellido_materno` | Datos personales. |
| `curp`, `rfc`, `seguro_social` | CURP, RFC y NSS (únicos por empresa). |
| `calle`, `colonia`, `no_exterior`, `no_interior`, `codigo_postal`, `localidad`, `municipio`, `estado` | Domicilio. |
| `no_empleado` | Número de empleado (único por empresa). |
| `grupo`, `sucursal`, `departamento`, `puesto` | Ubicación dentro de la empresa. |
| `salario_diario` | Salario diario. |
| `contrato` | Tipo de contrato (c_TipoContrato). |
| `regimen_contratacion` | Régimen de contratación (c_TipoRegimen). |
| `tipo_jornada` | Tipo de jornada (c_TipoJornada). |
| `periodidad_pago` | Cada cuánto se le paga (c_PeriodicidadPago). |
| `banco`, `clave_bancaria` | Banco y CLABE de 18 dígitos. |
| `subcontratacion` | Datos de subcontratación, si aplica. |
| `created_at`, `updated_at`, `deleted_at` | Fechas estándar y borrado suave. |

---

## 3. Catálogos globales (iguales para todas las empresas)

### `tax_rates` — tasas de impuestos

Catálogo base. Lo llena `TaxRateSeeder` con: IVA 16 %, IVA 0 %, IVA Exento e ISR Retenido 10 %.

| Campo | Para qué sirve |
|---|---|
| `code` | Impuesto SAT: `001` ISR, `002` IVA, `003` IEPS. |
| `name` | Nombre visible (ej. "IVA 16%"). |
| `rate` | Tasa con 6 decimales. |
| `tax_type` | `traslado` o `retencion`. |
| `factor_type` | `tasa`, `cuota` o `exento`. |
| `active` | Si se puede seleccionar. |

### `payment_channels` — canales de cobro internos

Cómo se cobró, para uso interno. **No** es la forma de pago fiscal del SAT. Lo llena `PaymentChannelSeeder` con `CASH`, `BANK_TRANSFER`, `CREDIT_CARD`, `DEBIT_CARD` y `CHECK`.

| Campo | Para qué sirve |
|---|---|
| `code` | Código interno (único). |
| `name` | Nombre visible. |
| `requires_bank` | Si hay que indicar un banco. |
| `active` | Si se puede seleccionar. |

### `sat_product_keys` — catálogo SAT c_ClaveProdServ

Unas 52 mil claves de producto o servicio. Se carga con `php artisan sat:import-catalogs`. Sirve para validar `products.clave_producto` y para el buscador, sin depender del PAC.

| Campo | Para qué sirve |
|---|---|
| `code` | Clave de 8 dígitos (llave primaria). |
| `description` | Descripción oficial. |
| `similar_words` | Palabras similares (ayudan a buscar). |
| `valid_from`, `valid_to` | Vigencia de la clave. |

### `sat_unit_keys` — catálogo SAT c_ClaveUnidad

Unas 2 400 claves de unidad, cargadas con el mismo comando.

| Campo | Para qué sirve |
|---|---|
| `code` | Clave (ej. `H87`, `E48`, `KGM`). |
| `name` | Nombre de la unidad. |
| `symbol` | Símbolo (ej. `kg`). |
| `valid_from`, `valid_to` | Vigencia. |

---

## 4. Cotizaciones y ventas

### `quotes` — cotizaciones

| Campo | Para qué sirve |
|---|---|
| `company_id`, `client_id` | Empresa y cliente. |
| `created_by` | Usuario que la creó. |
| `converted_sale_id` | Venta que se creó al convertir la cotización. |
| `folio` | Folio interno `COT-00000001` (único por empresa). No es un folio fiscal. |
| `status` | `draft`, `sent`, `approved`, `rejected`, `expired` o `converted`. |
| `approved_at` | Cuándo se aprobó. |
| `converted_at` | Cuándo se convirtió en venta. |
| `subtotal` | Suma de las líneas antes de impuestos. |
| `discount_total` | Descuento total. |
| `tax_total` | Total de impuestos **trasladados** (igual a `taxes_transferred`). |
| `taxes_transferred` | Impuestos trasladados (se suman al total). |
| `taxes_withheld` | Impuestos retenidos (se restan del total). |
| `total` | Total a pagar. |
| `currency` | Moneda (por defecto MXN). |
| `notes` | Notas libres. |
| `created_at`, `updated_at`, `deleted_at` | Fechas estándar y borrado suave. |

### `quote_items` — líneas de la cotización

| Campo | Para qué sirve |
|---|---|
| `company_id` | Empresa (también aquí para aislar las consultas directas). |
| `quote_id` | Cotización a la que pertenece. |
| `product_id` | Producto de origen (sólo para rastrear el origen). |
| `tax_rate_id` | Tasa de origen (heredado, una sola tasa). |
| `description` | Texto de la línea. |
| `quantity` | Cantidad (3 decimales). |
| `unit_price` | Precio unitario. |
| `discount` | Descuento de la línea. |
| `subtotal` | Cantidad × precio, con 2 decimales (para mostrar). |
| `subtotal_exact` | Lo mismo con 6 decimales, como el Importe del CFDI. Vacío en líneas viejas. |
| `tax_total`, `taxes_transferred`, `taxes_withheld` | Impuestos de la línea. |
| `total` | Total de la línea. |

### `quote_item_taxes` — impuestos de cada línea de la cotización

Misma estructura que `product_taxes`, más el cálculo:

| Campo | Para qué sirve |
|---|---|
| `quote_item_id` | Línea. |
| `tax`, `kind`, `factor`, `rate`, `ieps_mode`, `position` | Igual que en `product_taxes`. |
| `base` | Base sobre la que se calcula el impuesto (6 decimales). |
| `amount` | Importe exacto del impuesto (6 decimales). Vacío si es exento. |
| `amount_rounded` | Importe redondeado a 2 decimales. Es el que suman los totales. |

### `sales` — ventas

La venta es el paso previo a la factura: sólo una venta **confirmada** se puede facturar.

| Campo | Para qué sirve |
|---|---|
| `company_id`, `client_id`, `created_by` | Empresa, cliente y quién la creó. |
| `folio` | Folio interno `VTA-00000001` (único por empresa). |
| `status` | `draft`, `pending`, `confirmed` o `cancelled`. |
| `confirmed_at`, `cancelled_at` | Cuándo se confirmó o se canceló. |
| `subtotal`, `discount_total`, `tax_total`, `taxes_transferred`, `taxes_withheld`, `total` | Totales (igual que en `quotes`). |
| `currency` | Moneda. |
| `payment_form` | Forma de pago SAT de esta venta (ej. `01` efectivo, `03` transferencia, `99` por definir). Si va vacía, se usa la de la empresa. |
| `payment_method` | `PUE` o `PPD`. Si va vacío, se usa el de la empresa. |
| `notes` | Notas. |
| `created_at`, `updated_at`, `deleted_at` | Fechas estándar y borrado suave. |

### `sale_items` y `sale_item_taxes`

Líneas de la venta y sus impuestos. Tienen los mismos campos que `quote_items` y `quote_item_taxes`, cambiando `quote_id` por `sale_id` y `quote_item_id` por `sale_item_id`.

---

## 5. Facturas (CFDI de ingreso)

### `invoices` — facturas

Cada factura nace de **una** venta confirmada. Es la tabla más grande porque registra el documento fiscal y todo su ciclo de vida con el PAC: borrador, timbrado, archivos, cancelación y correo.

**Datos del documento**

| Campo | Para qué sirve |
|---|---|
| `company_id`, `sale_id`, `client_id`, `created_by` | Empresa, venta de origen, cliente y quién la creó. |
| `active_sale_id` | Columna calculada: vale `sale_id` mientras la factura no esté borrada. Garantiza que una venta tenga como máximo **una** factura vigente y permite refacturarla si se borra la anterior. |
| `folio` | Folio interno `FAC-00000019` (único por empresa). |
| `status` | Estado interno: `draft`, `ready`, `issued` (emitida) o `cancelled`. |
| `issued_at`, `cancelled_at` | Cuándo se emitió o se canceló. |
| `subtotal`, `discount_total`, `tax_total`, `taxes_transferred`, `taxes_withheld`, `total` | Totales. |
| `currency` | Moneda. |
| `notes` | Notas. |
| `payment_form` | Forma de pago SAT (copiada de la venta o de la empresa). |
| `payment_method` | `PUE` o `PPD`. Sólo las facturas PPD reciben complementos de pago. |

**Copia congelada del cliente** (cómo estaba el cliente al facturar)

| Campo | Para qué sirve |
|---|---|
| `client_name`, `client_rfc`, `client_regimen_fiscal`, `client_uso_cfdi`, `client_codigo_postal` | Datos fiscales del receptor. |
| `client_calle`, `client_no_exterior`, `client_no_interior`, `client_colonia`, `client_localidad`, `client_municipio`, `client_estado`, `client_pais` | Domicilio del receptor. |

**Timbrado con el PAC (resultado)**

| Campo | Para qué sirve |
|---|---|
| `pac_provider` | Proveedor (`facturapi`). |
| `pac_environment` | `test` o `live`. Se fija la primera vez que la factura toca el PAC y no cambia. |
| `pac_external_id` | ID de la factura en Facturapi. |
| `pac_organization_id` | Organización de Facturapi que la timbró. Se verifica antes de cancelar o descargar. |
| `pac_organization_archived_at`, `pac_organization_archive_reason` | Cierre manual de una factura cuya organización ya no es accesible. |
| `cfdi_uuid` | **Folio fiscal (UUID)** que asigna el SAT. Es el identificador oficial del CFDI. |
| `cfdi_series`, `cfdi_folio_number` | Serie y folio con que quedó timbrado (ej. `FAC` / `19`). |
| `pac_status` | Estado según Facturapi (ej. `valid`, `canceled`). |
| `cancellation_status` | Estado de la cancelación según Facturapi (ej. `pending`, `accepted`, `rejected`). |
| `stamped_at` | Cuándo se timbró. |
| `last_pac_sync_at` | Última consulta al PAC. |
| `pac_response` | Última respuesta completa del PAC (cifrada, oculta). |
| `pac_last_error` | Último error, sin datos sensibles. |

**Control del intento de emisión** (evita timbrar dos veces)

| Campo | Para qué sirve |
|---|---|
| `pac_idempotency_key` | Llave fija por factura (`erp-invoice:{empresa}:{factura}:v1`). Si se reintenta, Facturapi reconoce que es la misma y no la duplica. |
| `pac_issue_status` | Vacío, `pending`, `failed`, `succeeded` o `reconciliation_required` (no se sabe si se timbró y hay que revisar). |
| `pac_issue_started_at` | Cuándo empezó el intento. |
| `pac_issue_attempts` | Número de intentos. |
| `pac_reconciliation_required` | Indica que hay que comparar con el PAC antes de seguir. |

**Borrador en Facturapi**

| Campo | Para qué sirve |
|---|---|
| `pac_draft_external_id` | ID del borrador en Facturapi (un borrador no tiene UUID ni valor fiscal). |
| `pac_draft_status` | Estado del borrador. |
| `pac_draft_ready_to_stamp` | Si el borrador ya está listo para timbrar. |
| `pac_draft_idempotency_key` | Llave para no duplicar el borrador. |
| `pac_draft_created_at`, `pac_draft_last_sync_at` | Creación y última sincronización. |
| `pac_draft_response` | Respuesta del PAC (cifrada, oculta). |

**Control de la cancelación**

| Campo | Para qué sirve |
|---|---|
| `pac_cancel_request_status` | Estado de la **solicitud** local: vacío, `in_flight`, `completed`, `failed` o `reconciliation_required`. |
| `pac_cancel_started_at` | Cuándo se envió. |
| `pac_cancel_attempts` | Intentos. |

**Archivos del CFDI (XML y PDF)**

Los archivos no se guardan en la base: viven en `storage/app/private/cfdi/{company_id}/{invoice_id}/`. La tabla guarda la ruta y datos para comprobar que el archivo no cambió.

| Campo | Para qué sirve |
|---|---|
| `cfdi_xml_path`, `cfdi_pdf_path` | Ruta relativa de cada archivo. |
| `cfdi_xml_sha256`, `cfdi_pdf_sha256` | Huella del archivo, para detectar si se alteró. |
| `cfdi_xml_size`, `cfdi_pdf_size` | Tamaño en bytes. |
| `cfdi_artifacts_status` | Vacío, `pending`, `stored`, `failed` o `reconciliation_required`. |
| `cfdi_artifacts_started_at` | Cuándo empezó la descarga (para retomarla si se quedó colgada). |
| `cfdi_artifacts_downloaded_at` | Cuándo terminó. |
| `cfdi_artifacts_last_error` | Último error. |

**Acuse de cancelación (XML y PDF)**

| Campo | Para qué sirve |
|---|---|
| `cancellation_receipt_xml_path`, `cancellation_receipt_pdf_path` | Rutas del acuse que entrega el SAT al cancelar. |
| `cancellation_receipt_xml_sha256`, `cancellation_receipt_pdf_sha256` | Huellas. |
| `cancellation_receipt_xml_size`, `cancellation_receipt_pdf_size` | Tamaños. |
| `cancellation_receipt_status` | Estado de la descarga (mismos valores que arriba). |
| `cancellation_receipt_started_at`, `cancellation_receipt_downloaded_at` | Inicio y fin de la descarga. |
| `cancellation_receipt_last_error` | Último error. |

`created_at`, `updated_at` y `deleted_at` son las fechas estándar y el borrado suave.

### `invoice_items` — conceptos de la factura

Tienen los mismos campos que `sale_items` (`description`, `quantity`, `unit_price`, `discount`, `subtotal`, `subtotal_exact`, totales de impuestos, `total`). Además guardan una copia congelada del producto y de la tasa:

| Campo | Para qué sirve |
|---|---|
| `invoice_id` | Factura. |
| `product_clave_producto` | Clave SAT del producto al facturar. |
| `product_clave_unidad` | Clave de unidad SAT. |
| `product_type` | `product` o `service`. |
| `product_no_identificacion` | SKU. |
| `product_description` | Descripción del producto (distinta de `description`, que es el nombre comercial de la línea). |
| `product_objeto_imp` | Objeto de impuesto. |
| `tax_code`, `tax_name`, `tax_rate_value`, `tax_type`, `tax_factor_type` | Copia de la tasa única (esquema viejo; los impuestos actuales están en `invoice_item_taxes`). |

### `invoice_item_taxes` — impuestos de cada concepto

Mismos campos que `quote_item_taxes`. Es la copia congelada que se envía al PAC.

### `invoice_pac_events` — bitácora fiscal de cada factura

Historial de todo lo que pasó con la factura frente al PAC. Nunca se modifica, sólo se agregan filas. Sirve para auditoría y para diagnosticar problemas.

| Campo | Para qué sirve |
|---|---|
| `company_id`, `invoice_id` | Empresa y factura. |
| `event_type` | Qué pasó. Por ejemplo: `draft_created`, `stamp_succeeded`, `stamp_failed`, `reconciliation_required`, `artifacts_stored`, `cancellation_requested`, `cancellation_accepted`, `cancellation_rejected`, `cancellation_receipt_stored`, `email_sent`, `email_failed`, `issue_reverted`, `cfdi_folio_mismatch`. |
| `pac_provider`, `pac_external_id`, `cfdi_uuid` | Identificación en el PAC en ese momento. |
| `pac_status`, `cancellation_status`, `pac_issue_status` | Estados en ese momento. |
| `pac_code` | Código de error o respuesta del PAC. |
| `occurred_at` | Cuándo ocurrió. |
| `context` | Detalle extra. |
| `created_at` | Cuándo se registró. |

### `legacy_invoices` — facturas del sistema anterior

Tabla del primer prototipo (antes de multiempresa), renombrada para no perder datos. Guarda cliente, conceptos y pago como JSON. **El sistema actual no la usa**; sólo se conserva como histórico.

Campos: `invoice_number`, `status`, `document_type`, `emitter_name`, `emitter_zip`, `client_id`, `client_snapshot`, `payment`, `options`, `items`, `subtotal`, `taxes`, `discount`, `total`, `currency`, `exchange_rate`, `issued_at`, fechas y `deleted_at`.

---

## 6. Pagos (complemento de pago, REP, CFDI tipo P)

Cuando una factura es **PPD** (se paga después o en parcialidades), cada pago que se recibe se registra aquí y se timbra un complemento de pago. Por ahora sólo se aceptan pagos en MXN, sólo contra facturas PPD timbradas y con un REP por pago. La serie es `PAG`.

### `payments` — pagos recibidos

| Campo | Para qué sirve |
|---|---|
| `company_id`, `client_id`, `created_by` | Empresa, cliente que pagó y quién lo registró. |
| `folio` | Folio interno de la serie PAG (único por empresa). |
| `status` | `draft`, `issued` (REP timbrado) o `cancelled`. Sólo pasa a `cancelled` cuando el PAC confirma la cancelación; mientras tanto, el pago sigue contando para el saldo. |
| `payment_date` | Fecha y hora en que se recibió el dinero. |
| `payment_form` | Forma de pago SAT (no puede ser `99`). |
| `currency`, `exchange_rate` | Moneda y tipo de cambio (MXN, 1). |
| `amount` | Monto recibido (6 decimales). |
| `operation_number` | Número de operación o referencia bancaria. |
| `notes` | Notas. |
| `client_name`, `client_rfc`, `client_regimen_fiscal`, `client_codigo_postal` | Copia congelada del receptor al emitir el REP. |
| `pac_provider`, `pac_environment`, `pac_organization_id`, `pac_external_id` | Identificación en el PAC (igual que en facturas). |
| `pac_idempotency_key`, `pac_issue_status`, `pac_issue_attempts` | Control del intento de timbrado. |
| `pac_status`, `pac_response`, `pac_last_error` | Estado, respuesta y último error del PAC. |
| `cfdi_uuid` | Folio fiscal (UUID) del REP. |
| `stamped_at`, `issued_at` | Timbrado y emisión. |
| `cancellation_status` | Estado de la cancelación en el PAC. |
| `cancellation_motive` | Motivo SAT: `01` con errores y con relación, `02` con errores sin relación, `03` no se llevó a cabo, `04` operación nominativa en factura global. |
| `cancellation_requested_at`, `cancelled_at` | Solicitud y confirmación de la cancelación. |
| `created_at`, `updated_at`, `deleted_at` | Fechas estándar y borrado suave. |

### `payment_allocations` — a qué factura se aplica el pago

Un pago se puede repartir entre varias facturas. Cada fila es una de ellas (en el REP es un "documento relacionado").

| Campo | Para qué sirve |
|---|---|
| `company_id`, `payment_id`, `invoice_id` | Empresa, pago y factura pagada. |
| `installment` | Número de parcialidad (1, 2, 3...). |
| `previous_balance` | Saldo de la factura antes de este pago. |
| `amount` | Cuánto de este pago se aplica a la factura. |
| `remaining_balance` | Saldo que queda (saldo insoluto). |
| `invoice_uuid`, `invoice_series`, `invoice_folio_number`, `invoice_currency`, `invoice_total` | Copia congelada de la factura al emitir el REP. |

### `payment_allocation_taxes` — impuestos prorrateados del pago

Los impuestos de la factura, en proporción al monto pagado. El REP 2.0 los exige. Campos iguales a `invoice_item_taxes`, con `payment_allocation_id` como padre.

---

## 7. Conexión con Facturapi (PAC)

### `facturapi_organizations` — cuenta de cada empresa en Facturapi

Una fila por empresa. Guarda la organización de Facturapi y sus llaves cifradas.

| Campo | Para qué sirve |
|---|---|
| `company_id` | Empresa (única). |
| `organization_id` | ID de la organización en Facturapi (único: dos empresas nunca la comparten). |
| `pac_environment` | `test` (pruebas, sin valor fiscal) o `live` (producción, timbrado real). Sólo se cambia desde la consola con `billing:pac-environment`. |
| `test_secret_key` | Llave secreta de pruebas (cifrada, nunca se muestra). |
| `test_key_hint`, `test_key_set_at` | Pista visible (`sk_test_…a1b2`) y fecha de captura. |
| `live_secret_key` | Llave de producción (cifrada; se captura con `billing:facturapi-live-key`). |
| `live_key_hint`, `live_key_set_at` | Pista visible y fecha. |
| `legal_name` | Razón social registrada en Facturapi. |
| `legal_trade_name` | Nombre comercial que imprime el PDF. |
| `legal_tax_id` | RFC. |
| `legal_tax_system` | Régimen fiscal. |
| `legal_zip` | C.P. fiscal. |
| `legal_support_email` | Correo "Responder" de los CFDI enviados por correo. En Live debe coincidir con `companies.email`. |
| `has_certificate` | Si ya se subió el CSD (certificado de sello digital). |
| `certificate_serial_number`, `certificate_expires_at`, `certificate_updated_at` | Número de serie, vencimiento y fecha de carga del CSD. |
| `has_logo` | Si la organización tiene logotipo. |
| `is_production_ready` | Si Facturapi dice que ya puede timbrar en producción. |
| `pending_steps` | Pasos que faltan según Facturapi (JSON). |
| `snapshot_at` | Fecha de la última copia de estos datos desde Facturapi. |

### `facturapi_organization_audits` — bitácora de cambios en la organización

Nunca se modifica, sólo se agregan filas.

| Campo | Para qué sirve |
|---|---|
| `company_id`, `organization_id` | Empresa y organización. |
| `user_id` | Quién hizo el cambio. Vacío si fue desde la consola. |
| `source` | Origen: `api` o `console`. |
| `action` | `organization_created`, `test_key_stored`, `test_key_replaced`, `legal_updated`, `certificate_uploaded`, `logo_uploaded`, `live_key_stored`, `live_key_replaced` o `environment_changed`. |
| `metadata` | Detalle no secreto (campos cambiados, serie del CSD, pista de la llave). Nunca llaves ni contraseñas. |
| `created_at` | Cuándo. |

---

## 8. Tablas internas de Laravel

No guardan datos de negocio; las usa el framework.

| Tabla | Para qué sirve |
|---|---|
| `cache` | Caché de la aplicación (`key`, `value`, `expiration`). |
| `cache_locks` | Candados para evitar que dos procesos hagan lo mismo a la vez (por ejemplo, timbrar la misma factura). |
| `jobs` | Cola de tareas en segundo plano (envío de correos, descargas de XML/PDF...). `queue` es el nombre de la cola, `payload` la tarea, `attempts` los intentos, y `reserved_at` / `available_at` controlan cuándo se ejecuta. |
| `job_batches` | Grupos de tareas: cuántas hay en total, cuántas faltan y cuántas fallaron. |
| `failed_jobs` | Tareas que fallaron definitivamente, con el error (`exception`), para revisarlas o reintentarlas. |
| `migrations` | Lista de migraciones ya aplicadas a la base. |
