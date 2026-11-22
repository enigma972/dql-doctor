<?php

declare(strict_types=1);

namespace App\DlqDoctor\Enum;

enum RepairProposalStatus: string
{
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case EXPIRED = 'expired';
    case REPLAYED = 'replayed';
}
