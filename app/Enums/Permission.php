<?php

namespace App\Enums;

/**
 * Permisos del sistema, con formato `<módulo>.<acción>`:
 * - `view`: listar y consultar.
 * - `manage`: crear, editar, eliminar y ejecutar acciones del módulo.
 *
 * Qué permisos tiene cada rol se define en RoleService.
 */
enum Permission: string
{
    case ProductsView = 'products.view';
    case ProductsManage = 'products.manage';

    case ClientsView = 'clients.view';
    case ClientsManage = 'clients.manage';

    case EmployeesView = 'employees.view';
    case EmployeesManage = 'employees.manage';

    case QuotesView = 'quotes.view';
    case QuotesManage = 'quotes.manage';

    case SalesView = 'sales.view';
    case SalesManage = 'sales.manage';

    case InvoicesView = 'invoices.view';
    case InvoicesManage = 'invoices.manage';

    case PaymentsView = 'payments.view';
    case PaymentsManage = 'payments.manage';

    case UsersView = 'users.view';
    case UsersManage = 'users.manage';

    public function isView(): bool
    {
        return str_ends_with($this->value, '.view');
    }
}
