<?php

return [

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
