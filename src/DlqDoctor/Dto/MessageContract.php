<?php

declare(strict_types=1);

namespace App\DlqDoctor\Dto;

final readonly class MessageContract
{
    /**
     * @param array<string, mixed> $jsonSchema
     * @param list<string>         $unsupportedConstraints
     */
    public function __construct(
        public string $className,
        public array $jsonSchema,
        public array $unsupportedConstraints,
    ) {
    }
}
