<?php

declare(strict_types=1);

namespace App\DlqDoctor\Dto;

final readonly class AutomaticPatchResult
{
    /**
     * @param list<string> $changedProperties
     */
    public function __construct(
        public object $message,
        public array $changedProperties,
    ) {
    }
}
