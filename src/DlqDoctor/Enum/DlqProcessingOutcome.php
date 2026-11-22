<?php

declare(strict_types=1);

namespace App\DlqDoctor\Enum;

enum DlqProcessingOutcome: string
{
    case REPLAYED = 'replayed';
    case PROPOSAL_CREATED = 'proposal_created';
    case FORBIDDEN = 'forbidden';
    case ALREADY_PROCESSED = 'already_processed';
    case IN_PROGRESS = 'in_progress';
    case OTHER_TENANT = 'other_tenant';
}
