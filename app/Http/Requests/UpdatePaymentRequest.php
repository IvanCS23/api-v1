<?php

namespace App\Http\Requests;

/**
 * Fase 6.35: la edición de un pago en borrador reemplaza el pago completo
 * (datos y reparto), con las mismas reglas que el alta.
 */
class UpdatePaymentRequest extends StorePaymentRequest {}
