<?php

declare(strict_types=1);

namespace App\DlqDoctor\Enum;

enum DlqAction: string
{
    case REPLAY = 'replay';
    case PATCH = 'patch';
    case DISCARD = 'discard';
}