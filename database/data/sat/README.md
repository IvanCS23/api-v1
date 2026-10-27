# Catálogos SAT del CFDI 4.0 (Fase 6.26)

Fuente única de los catálogos fiscales que valida FacturaPro al capturar
clientes, productos y ventas. Se generan del archivo oficial del SAT; no se
editan a mano ni se toman de terceros.

## Origen

| Dato | Valor |
|---|---|
| Página oficial (Anexo 20) | http://omawww.sat.gob.mx/tramitesyservicios/Paginas/anexo_20.htm |
| Archivo | http://omawww.sat.gob.mx/tramitesyservicios/Paginas/documentos/catCFDI_V_4_20261015.xls |
| Publicado por el SAT | 15/10/2026 ("Catálogos CFDI Versión 4.0 (xls)") |
| Fecha de descarga | 2026-10-26 |
| Tamaño | 48 774 656 bytes |
| SHA-256 del .xls | `fde78b456d1e0d90366bfc68f8312f9edce5703ad5dc8ccebf09c462db13a079` |

El .xls no se versiona (49 MB); los CSV sí. `manifest.json` repite el
SHA-256 del origen y guarda el de cada CSV generado.

## Versiones de cada catálogo (encabezado de su hoja)

| Hoja | CSV | Filas | Versión | Revisión | Publicación |
|---|---|---|---|---|---|
| c_RegimenFiscal | `regimen_fiscal.csv` | 19 | 1 | 0 | — |
| c_UsoCFDI | `uso_cfdi.csv` | 24 | 2 | 0 | 2023-04-22 |
| c_FormaPago | `forma_pago.csv` | 22 | 1 | 0 | — |
| c_ObjetoImp | `objeto_imp.csv` | 8 | 4 | 0 | 2024-12-13 |
| c_ClaveProdServ | `clave_prod_serv.csv` | 52 513 | 2 | 2 | 2025-12-31 |
| c_ClaveUnidad | `clave_unidad.csv` | 2 418 | 1 | 0 | — |

La matriz régimen ↔ uso ↔ tipo de persona sale de las columnas "Aplica para
tipo persona" (Física/Moral) de c_RegimenFiscal y c_UsoCFDI, y de "Régimen
Fiscal Receptor" de c_UsoCFDI (en el CSV, `regimenes_receptor` separado por
`|`).

## Conversión (repetible)

`tools/convert_catalog.php` lee el .xls con `tools/XlsReader.php` (lector
BIFF8/OLE2 propio, sin dependencias) y escribe los CSV y `manifest.json`.
El mismo .xls produce siempre archivos idénticos byte a byte.

```bash
# desde api-v1
php -d memory_limit=1G database/data/sat/tools/convert_catalog.php /ruta/catCFDI_V_4_AAAAMMDD.xls
php artisan sat:import-catalogs
```

- Las claves que Excel guardó como número se completan con ceros
  (`1` → `01` en formas de pago, `10101500` en claves de producto).
- Las fechas de Excel se escriben como `AAAA-MM-DD`.
- Sólo cuentan las claves vigentes a la fecha (sin fin de vigencia o con fin
  futuro); hoy ninguna fila tiene fin de vigencia.

## Uso en la aplicación

- `App\Support\Sat\SatCatalog` lee los catálogos pequeños de estos CSV y
  consulta `sat_product_keys` / `sat_unit_keys` para los grandes.
- `php artisan sat:import-catalogs` reemplaza el contenido de esas dos tablas
  por el de los CSV (idempotente).
- Las pruebas cargan una muestra de claves reales (`tests/Support/SatCatalogFixture.php`)
  y una prueba importa el CSV completo para detectar un archivo vacío o roto.

## Actualizar

1. Descargar el .xls nuevo **sólo** desde la página del Anexo 20 en sat.gob.mx.
2. Convertirlo con el script y revisar el diff de los CSV.
3. Actualizar las tablas de este README (URL, fechas, SHA-256, versiones y
   filas) y los conteos de `tests/Feature/Sat/SatCatalogTest.php`.
4. Correr `php artisan sat:import-catalogs` en cada ambiente.
