<?php

declare(strict_types=1);

namespace App\DlqDoctor\Enum;

enum DlqDiscardOutcome: string
{
    case DISCARDED = 'discarded';
    case ALREADY_DISCARDED = 'already_discarded';
}
