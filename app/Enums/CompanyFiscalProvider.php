<?php

namespace App\Enums;

enum CompanyFiscalProvider: string
{
    case SAT = 'sat';
    case PAC = 'pac';
    case Custom = 'custom';
}
