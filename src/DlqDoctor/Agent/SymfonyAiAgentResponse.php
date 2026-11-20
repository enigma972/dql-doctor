<?php

declare(strict_types=1);

namespace App\DlqDoctor\Agent;

final readonly class SymfonyAiAgentResponse
{
    public function __construct(
        public string $diagnosis,
        public ?string $proposedPayloadJson,
    ) {
    }
}
