<?php

declare(strict_types=1);

namespace App\DlqDoctor\Dto;

use App\DlqDoctor\Enum\RepairProposalStatus;

final readonly class RepairProposalReview
{
    /**
     * @param array<string|int, mixed>      $originalPayload
     * @param array<string|int, mixed>|null $patchedPayload
     * @param array<string, mixed>|null     $diff
     */
    public function __construct(
        public RepairProposalSummary $summary,
        public array $originalPayload,
        public ?array $patchedPayload,
        public ?array $diff,
        public ?string $approvalToken,
    ) {
    }

    public function status(): RepairProposalStatus
    {
        return $this->summary->status;
    }
}
