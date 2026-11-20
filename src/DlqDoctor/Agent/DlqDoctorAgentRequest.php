<?php

declare(strict_types=1);

namespace App\DlqDoctor\Agent;

use InvalidArgumentException;

final readonly class DlqDoctorAgentRequest
{
    private const MAX_JSON_BYTES = 65_536;
    private const MAX_REQUEST_BYTES = 131_072;

    /**
     * @param array<string|int, mixed> $payload
     * @param array<string, mixed>     $jsonSchema
     */
    public function __construct(
        public string $messageClass,
        public string $errorClass,
        public array $payload,
        public array $jsonSchema,
    ) {
        if ('' === trim($messageClass) || strlen($messageClass) > 255) {
            throw new InvalidArgumentException('A message class must contain between 1 and 255 characters.');
        }

        if ('' === trim($errorClass) || strlen($errorClass) > 255) {
            throw new InvalidArgumentException('An error class must contain between 1 and 255 characters.');
        }

        if ([] !== $jsonSchema && array_is_list($jsonSchema)) {
            throw new InvalidArgumentException('The message schema must be a JSON object.');
        }

        JsonObjectPayload::encode($payload, 'The message payload', self::MAX_JSON_BYTES);
        JsonObjectPayload::encode($jsonSchema, 'The message schema', self::MAX_JSON_BYTES);
        JsonObjectPayload::encode($this->toArray(), 'The DLQ agent request', self::MAX_REQUEST_BYTES);
    }

    /**
     * @return array{
     *     messageClass: string,
     *     errorClass: string,
     *     payload: array<string|int, mixed>|\stdClass,
     *     jsonSchema: array<string, mixed>|\stdClass
     * }
     */
    public function toArray(): array
    {
        return [
            'messageClass' => $this->messageClass,
            'errorClass' => $this->errorClass,
            'payload' => [] === $this->payload ? new \stdClass() : $this->payload,
            'jsonSchema' => [] === $this->jsonSchema ? new \stdClass() : $this->jsonSchema,
        ];
    }
}
