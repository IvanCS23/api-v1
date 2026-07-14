<?php

namespace App\Enums;

enum AuditAction: string
{
    case PasswordChanged = 'password_changed';
    case PasswordReset = 'password_reset';
    case SessionsRevoked = 'sessions_revoked';
    case Activated = 'activated';
    case Deactivated = 'deactivated';
}
