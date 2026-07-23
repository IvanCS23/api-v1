<?php

namespace App\Enums;

/**
 * Fuente única de verdad de los valores permitidos de `products.type`.
 *
 * La columna es un VARCHAR simple (no ENUM nativo de MySQL) a propósito:
 * un ENUM de MySQL requiere una migración de esquema para agregar un
 * valor nuevo (ej. un futuro "bundle" o "kit"); con un enum de PHP
 * respaldado por string, agregar un caso es un cambio de código sin
 * tocar la base de datos.
 */
enum ProductType: string
{
    case Product = 'product';
    case Service = 'service';
}
