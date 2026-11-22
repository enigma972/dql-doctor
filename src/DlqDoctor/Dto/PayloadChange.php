<?php

declare(strict_types=1);

namespace App\DlqDoctor\Dto;

final readonly class PayloadChange
{
    public function __construct(
        public string $path,
        public bool $originalPresent,
        public mixed $originalValue,
        public bool $proposedPresent,
        public mixed $proposedValue,
    ) {
    }
}
