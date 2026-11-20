<?php

declare(strict_types=1);

namespace App\DlqDoctor\Agent;

use InvalidArgumentException;
use JsonException;

final class JsonObjectPayload
{
    /**
     * @param array<string|int, mixed> $payload
     */
    public static function encode(array $payload, string $label, int $maxBytes): string
    {
        if ([] !== $payload && array_is_list($payload)) {
            throw new InvalidArgumentException(sprintf('%s must be a JSON object.', $label));
        }

        $nodes = 0;
        $jsonValue = [] === $payload ? new \stdClass() : $payload;
        self::assertJsonTree($jsonValue, $label, $nodes);

        try {
            $encoded = json_encode($jsonValue, JSON_THROW_ON_ERROR, 64);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(sprintf('%s must be valid JSON.', $label), previous: $exception);
        }

        if (strlen($encoded) > $maxBytes) {
            throw new InvalidArgumentException(sprintf('%s cannot exceed %d bytes.', $label, $maxBytes));
        }

        return $encoded;
    }

    private static function assertJsonTree(mixed $value, string $label, int &$nodes, int $depth = 0): void
    {
        if (++$nodes > 20_000 || $depth > 64) {
            throw new InvalidArgumentException(sprintf('%s exceeds the JSON complexity limit.', $label));
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertJsonTree($item, $label, $nodes, $depth + 1);
            }

            return;
        }

        if ($value instanceof \stdClass) {
            foreach (get_object_vars($value) as $item) {
                self::assertJsonTree($item, $label, $nodes, $depth + 1);
            }

            return;
        }

        if (null !== $value
            && !is_string($value)
            && !is_int($value)
            && !is_float($value)
            && !is_bool($value)
        ) {
            throw new InvalidArgumentException(sprintf('%s must contain only JSON values.', $label));
        }

        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException(sprintf('%s must contain only finite numbers.', $label));
        }
    }
}
