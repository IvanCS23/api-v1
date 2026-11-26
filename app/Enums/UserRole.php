<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Accountant = 'accountant';
    case Sales = 'sales';
    case Employee = 'employee';
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Accountant => 'Contador',
            self::Sales => 'Ventas',
            self::Employee => 'Empleado',
            self::Client => 'Cliente',
        };
    }
}
