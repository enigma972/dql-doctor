<?php

declare(strict_types=1);

namespace App\DlqDoctor\Enum;

enum PolicyDecision: string
{
    case AUTO = 'auto';
    case AUTO_AUDIT = 'auto_audit';
    case APPROVAL = 'approval';
    case FORBIDDEN = 'forbidden';
}