<?php

declare(strict_types=1);

namespace App\DlqDoctor\Dto;

use App\DlqDoctor\Enum\SensitivityLevel;

final readonly class FailedMessageSummary
{
    public function __construct(
        public string $id,
        public string $messageClass,
        public ?string $errorClass,
        public SensitivityLevel $sensitivity,
    ) {
    }
}
