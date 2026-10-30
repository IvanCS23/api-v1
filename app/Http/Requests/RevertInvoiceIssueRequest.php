<?php

namespace App\Http\Requests;

/**
 * Fase 6.29: "Regresar a lista" exige `confirm` y, como emitir, prohíbe
 * enviar cualquier dato fiscal o de identidad PAC.
 */
class RevertInvoiceIssueRequest extends IssueInvoiceWithPacRequest {}
