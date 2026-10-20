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

FASE 6.17:
- Deprecación de endpoints ERP legacy.
- Headers `Deprecation: true`.
- Successor links.
- Logging estructurado sin secretos.
- Documentación del ciclo de vida.
- Pruebas que prohíben el consumo productivo de endpoints legacy desde el cliente fiscal.

Documentación principal:

`api-v1/BILLING_LIFECYCLE.md`

## 8. Estado de pruebas reportado

Último informe de FASE 6.17:
- Backend: 937 pruebas aprobadas, 4091 assertions.
- Frontend: 81 pruebas aprobadas en 8 archivos.
- TypeScript: aprobado.
- Build Next.js: aprobado, 31 páginas generadas.
- Pint y ESLint de archivos relacionados: aprobados.
- `git diff --check`: limpio.

Estos resultados son históricos. Ejecútalos de nuevo antes de afirmar que el workspace actual está validado.

Deuda conocida:
- Lint global frontend con errores y warnings anteriores.
- Vulnerabilidades de dependencias reportadas, pendientes de nueva auditoría.
- Búsqueda parcial `LIKE`.
- Índices de consulta pendientes de evaluación con datos reales.
- No existe endpoint real de envío por correo.
- Debe revisarse el diff del lockfile.
- Los endpoints legacy siguen disponibles por compatibilidad.

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

La última fase reportada como completada es FASE 6.17.

La siguiente fase propuesta es FASE 6.18: Observabilidad operativa y salud del ciclo de facturación.

Objetivo: detectar, clasificar y reportar facturas saludables, transitorias, recuperables e inconsistentes sin modificar facturas ni ejecutar operaciones PAC.

Posibles entregables:
- Evaluador de salud reutilizando las reglas de FASE 6.16.
- Comando Artisan de diagnóstico, por ejemplo `php artisan billing:health`.
- Resumen de estados y operaciones pendientes.
- API administrativa read-only, únicamente si la auditoría demuestra que es necesaria.
- Autorización, aislamiento multi-tenant, pruebas y documentación.
- Observabilidad del uso de endpoints legacy.

La primera versión debe limitarse a diagnóstico y reporte.

No debe:
- Emitir CFDI.
- Cancelar CFDI.
- Reconciliar automáticamente.
- Descargar artifacts.
- Modificar facturas.
- Crear migraciones sin necesidad demostrada.

## 11. Primera tarea solicitada

No empieces a programar inmediatamente.

Realiza una auditoría de continuidad de FASE 6.17 y prepara FASE 6.18:

1. Confirma el estado real de ambos repositorios.
2. Verifica los servicios y clasificadores de FASE 6.16.
3. Inspecciona `BILLING_LIFECYCLE.md`.
4. Comprueba qué métricas, logs, comandos y mecanismos de observabilidad ya existen.
5. Identifica las combinaciones de estados que pueden detectarse con los datos disponibles.
6. Determina si basta con un comando Artisan o si también se justifica una API administrativa.
7. Propón archivos a crear o modificar, contrato de salida, seguridad, estrategia de pruebas y riesgos.
8. Presenta el plan antes de modificar archivos.

No inventes el estado actual del workspace. Si falta información, inspecciona el código y explica qué no pudo verificarse.

La meta es continuar desde el trabajo real existente, preservar las decisiones anteriores y avanzar de forma incremental y verificable.