<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Modo del PAC (Fase 6.19)
    |--------------------------------------------------------------------------
    |
    | La llave de Facturapi debe corresponder a este modo (test → sk_test_,
    | live → sk_live_) o no se envía ninguna petición, ni escrituras ni
    | descargas. En esta fase sólo `test` está soportado: `live` se rechaza
    | hasta una fase explícita de producción.
    |
    */

    'pac_mode' => env('BILLING_PAC_MODE', 'test'),

    /*
    |--------------------------------------------------------------------------
    | Reserva de cancelación (Fase 6.19)
    |--------------------------------------------------------------------------
    |
    | Minutos tras los cuales una solicitud `in_flight` se considera
    | abandonada (proceso caído a mitad del DELETE). Sólo entonces la
    | reconciliación puede liberarla si el PAC no registra la solicitud.
    | Holgado frente a `services.facturapi.timeout` (15 s).
    |
    */

    'cancel_in_flight_minutes' => (int) env('BILLING_CANCEL_IN_FLIGHT_MINUTES', 5),

    /*
    |--------------------------------------------------------------------------
    | Fechas de Facturapi (Fase 6.20)
    |--------------------------------------------------------------------------
    |
    | - stamp_timezone: zona en la que se interpreta `stamp.date` (fecha de
    |   timbrado). Facturapi la publica SIN offset y en hora del centro de
    |   México (FechaTimbrado, Anexo 20), a diferencia de `created_at`, que
    |   llega en UTC con `Z`. El resultado siempre se guarda en UTC. Si el
    |   valor llegara con offset explícito, ese offset manda.
    |
    |   Debe ser un identificador IANA válido (p. ej. America/Mexico_City);
    |   uno inválido bloquea toda petición al PAC con
    |   PAC_CONFIGURATION_INVALID antes de contactarlo.
    |
    */

    'facturapi' => [
        'stamp_timezone' => env('BILLING_FACTURAPI_STAMP_TIMEZONE', 'America/Mexico_City'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Expediente fiscal: XML/PDF (Fase 6.21)
    |--------------------------------------------------------------------------
    |
    | - auto_download: tras un timbrado exitoso (evento InvoiceIssued) se
    |   encola DownloadInvoiceArtifactsJob. Requiere un worker activo
    |   (`composer dev` o `php artisan queue:work` en desarrollo;
    |   supervisor en producción); sin worker los jobs esperan en la
    |   tabla `jobs`. Un fallo de la descarga nunca altera la emisión.
    |   Las pruebas lo apagan (phpunit.xml) y lo activan explícitamente.
    | - pending_minutes: antigüedad tras la cual una descarga `pending`
    |   (proceso caído a mitad del HTTP) puede retomarse. Holgado frente a
    |   dos descargas con `services.facturapi.timeout` (15 s).
    |
    */

    'artifacts' => [
        'auto_download' => (bool) env('BILLING_ARTIFACTS_AUTO_DOWNLOAD', true),
        'pending_minutes' => (int) env('BILLING_ARTIFACTS_PENDING_MINUTES', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Salud del ciclo de facturación (Fase 6.18)
    |--------------------------------------------------------------------------
    |
    | Umbrales del diagnóstico read-only `billing:health`. No condicionan
    | ninguna operación fiscal; sólo separan estados transitorios de los
    | que requieren intervención.
    |
    | - issue_pending_minutes: Facturapi puede responder 202 y reintentar
    |   recuperar el CFDI hasta 5 veces cada 10 minutos.
    | - cancellation_pending_hours (96 h) = 72 h + 24 h:
    |   * 72 h: plazo del receptor para aceptar o rechazar una cancelación
    |     que requiere su aceptación. Mientras corre, Facturapi responde
    |     status=valid + cancellation_status=pending (regla de Facturapi
    |     verificada por el equipo en docs.facturapi.io/api).
    |   * 24 h: margen operativo PROPIO, no normativo. El ERP sólo conoce
    |     el desenlace al reconciliar (no hay webhooks), así que se tolera
    |     un día de desfase antes de escalar a recoverable. Ajustable.
    | - draft_date_*_hours: un CFDI no puede llevar fecha anterior a 72 h.
    |
    */

    'health' => [
        'issue_pending_minutes' => (int) env('BILLING_HEALTH_ISSUE_PENDING_MINUTES', 60),
        'cancellation_pending_hours' => (int) env('BILLING_HEALTH_CANCELLATION_PENDING_HOURS', 96),
        'draft_date_warning_hours' => (int) env('BILLING_HEALTH_DRAFT_DATE_WARNING_HOURS', 48),
        'draft_date_limit_hours' => (int) env('BILLING_HEALTH_DRAFT_DATE_LIMIT_HOURS', 72),
        'chunk_size' => (int) env('BILLING_HEALTH_CHUNK_SIZE', 500),
    ],

];
