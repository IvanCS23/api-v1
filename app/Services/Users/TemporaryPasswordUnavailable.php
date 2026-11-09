<?php

namespace App\Services\Users;

use RuntimeException;

/** Fase 6.32: ninguna contraseña temporal generada cumplió las reglas. */
class TemporaryPasswordUnavailable extends RuntimeException {}
