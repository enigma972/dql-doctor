<?php

declare(strict_types=1);

namespace App\DlqDoctor\Agent;

use InvalidArgumentException;

final readonly class DlqDoctorAgentResult
{
    private const MAX_DIAGNOSIS_BYTES = 2_048;
    private const MAX_PATCH_BYTES = 65_536;

    /**
     * @param array<string|int, mixed>|null $proposedPayload
     */
    public function __construct(
        public string $diagnosis,
        public ?array $proposedPayload,
    ) {
        if ('' === trim($diagnosis) || strlen($diagnosis) > self::MAX_DIAGNOSIS_BYTES) {
            throw new InvalidArgumentException('An agent diagnosis must contain between 1 and 2048 bytes.');
        }

        if (null === $proposedPayload) {
            return;
        }

        JsonObjectPayload::encode($proposedPayload, 'A proposed payload', self::MAX_PATCH_BYTES);
    }
}
