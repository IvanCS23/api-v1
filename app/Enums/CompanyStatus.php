<?php

namespace App\Enums;

enum CompanyStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
}
