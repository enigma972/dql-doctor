<?php

declare(strict_types=1);

namespace App\DlqDoctor\Dto;

use App\DlqDoctor\Enum\RepairProposalStatus;
use DateTimeImmutable;

final readonly class RepairProposalSummary
{
    public function __construct(
        public int $id,
        public string $failedMessageId,
        public string $messageClass,
        public RepairProposalStatus $status,
        public DateTimeImmutable $expiresAt,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
