<?php

namespace App\Enums;

enum CompanyIntegrationStatus: string
{
    case Pending = 'pending';
    case Connected = 'connected';
    case Disconnected = 'disconnected';
    case Error = 'error';
}
