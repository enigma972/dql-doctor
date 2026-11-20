<?php

declare(strict_types=1);

namespace App\DlqDoctor\Dto;

final readonly class PatchViolation
{
    public function __construct(
        public string $propertyPath,
        public string $message,
        public ?string $code,
    ) {
    }
}
