<?php

declare(strict_types=1);

namespace App\DlqDoctor\Dto;

final readonly class PatchDryRunResult
{
    /**
     * @param list<PatchViolation> $violations
     * @param list<PayloadChange>  $diff
     */
    public function __construct(
        public string $messageId,
        public string $messageClass,
        public bool $valid,
        public array $violations,
        public array $diff,
    ) {
    }
}
