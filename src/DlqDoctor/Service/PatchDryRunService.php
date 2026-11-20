<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use App\DlqDoctor\Dto\PatchDryRunResult;
use App\DlqDoctor\Dto\PatchViolation;
use App\DlqDoctor\Dto\PayloadChange;
use App\DlqDoctor\Context\TenantContextInterface;
use App\DlqDoctor\Messenger\TenantStamp;
use InvalidArgumentException;
use JsonException;
use ReflectionReference;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use TypeError;
use UnexpectedValueException;

final readonly class PatchDryRunService
{
    private const MAX_PAYLOAD_BYTES = 65_536;

    public function __construct(
        private FailedMessageReader $failedTransport,
        private ContractInspector $contractInspector,
        private TenantContextInterface $tenantContext,
        private Security $security,
        private NormalizerInterface $normalizer,
        private DenormalizerInterface $denormalizer,
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * @param array<string|int, mixed> $proposedPayload
     */
    public function dryRun(string $messageId, array $proposedPayload): PatchDryRunResult
    {
        $this->denyUnlessAuthorized();

        if ('' === trim($messageId) || strlen($messageId) > 128) {
            throw new InvalidArgumentException('A message ID must contain between 1 and 128 characters.');
        }

        $envelope = $this->failedTransport->find($messageId);
        $tenantId = $this->tenantContext->tenantId();

        if (null === $envelope || $tenantId !== $envelope->last(TenantStamp::class)?->tenantId) {
            throw new FailedMessageNotFoundException();
        }

        $transportMessageId = $envelope->last(TransportMessageIdStamp::class)?->getId();
        if ((string) $transportMessageId !== $messageId) {
            throw new UnexpectedValueException('The failed transport returned an envelope with a mismatched message ID.');
        }

        $message = $envelope->getMessage();
        $messageClass = $message::class;
        $contract = $this->contractInspector->inspect($messageClass);
        $originalPayload = $this->normalizeToArray($message);
        $this->assertPayloadSize($originalPayload);
        $encodedProposedPayload = $this->encodePayload($proposedPayload);
        $safeProposedPayload = json_decode($encodedProposedPayload, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($safeProposedPayload)) {
            throw new InvalidArgumentException('A proposed payload must be a JSON object or array.');
        }

        try {
            $patchedMessage = $this->denormalizer->denormalize(
                $safeProposedPayload,
                $messageClass,
                'json',
                [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false],
            );
        } catch (ExceptionInterface|TypeError $exception) {
            throw new PatchDeserializationException($exception);
        }

        if (!is_object($patchedMessage) || $messageClass !== $patchedMessage::class) {
            throw new PatchDeserializationException();
        }

        $violations = [];
        foreach ($this->validator->validate($patchedMessage) as $violation) {
            $violations[] = new PatchViolation(
                $violation->getPropertyPath(),
                (string) $violation->getMessage(),
                $violation->getCode(),
            );
        }

        $normalizedPatch = $this->normalizeToArray($patchedMessage);
        $this->assertPayloadSize($normalizedPatch);
        $changes = [];
        $this->collectChanges($originalPayload, $normalizedPatch, '', $changes);
        $this->assertDiffSize($changes);

        return new PatchDryRunResult(
            $messageId,
            $contract->className,
            [] === $violations,
            $violations,
            $changes,
        );
    }

    private function denyUnlessAuthorized(): void
    {
        if (!$this->security->isGranted('ROLE_DLQ_VIEWER')) {
            throw new AccessDeniedException('The current user cannot dry-run message patches.');
        }
    }

    /**
     * @return array<string|int, mixed>
     */
    private function normalizeToArray(object $message): array
    {
        $payload = $this->normalizer->normalize($message);
        if (!is_array($payload)) {
            throw new UnexpectedValueException('The message did not normalize to an object payload.');
        }

        return $payload;
    }

    /**
     * @param array<string|int, mixed> $payload
     */
    private function assertPayloadSize(array $payload): void
    {
        $this->encodePayload($payload);
    }

    /**
     * @param array<string|int, mixed> $payload
     */
    private function encodePayload(array $payload): string
    {
        $this->assertJsonTree($payload);

        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The payload must be JSON serializable.', previous: $exception);
        }

        if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('A dry-run payload cannot exceed 65536 bytes.');
        }

        return $encoded;
    }

    private function assertJsonTree(mixed $value): void
    {
        $references = [];
        $nodes = 0;
        $this->assertJsonValue($value, $references, $nodes);
    }

    /**
     * @param array<string, true> $references
     */
    private function assertJsonValue(mixed $value, array &$references, int &$nodes, int $depth = 0): void
    {
        if (++$nodes > 20_000) {
            throw new InvalidArgumentException('A dry-run payload cannot contain more than 20000 values.');
        }

        if ($depth > 64) {
            throw new InvalidArgumentException('A dry-run payload cannot be nested more than 64 levels.');
        }

        if (!is_array($value)) {
            if (null !== $value && !is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
                throw new InvalidArgumentException('A dry-run payload must contain only JSON scalar values and arrays.');
            }
            if (is_string($value) && strlen($value) > self::MAX_PAYLOAD_BYTES) {
                throw new InvalidArgumentException('A dry-run payload cannot exceed 65536 bytes.');
            }

            return;
        }

        foreach ($value as $key => $item) {
            if (strlen((string) $key) > self::MAX_PAYLOAD_BYTES) {
                throw new InvalidArgumentException('A dry-run payload cannot exceed 65536 bytes.');
            }
            $reference = ReflectionReference::fromArrayElement($value, $key);
            $referenceId = null === $reference ? null : bin2hex($reference->getId());
            if (null !== $referenceId && isset($references[$referenceId])) {
                throw new InvalidArgumentException('A dry-run payload cannot contain circular references.');
            }

            if (null !== $referenceId) {
                $references[$referenceId] = true;
            }
            $this->assertJsonValue($item, $references, $nodes, $depth + 1);
            if (null !== $referenceId) {
                unset($references[$referenceId]);
            }
        }
    }

    /**
     * @param array<string|int, mixed> $original
     * @param array<string|int, mixed> $proposed
     * @param list<PayloadChange>      $changes
     */
    private function collectChanges(array $original, array $proposed, string $prefix, array &$changes): void
    {
        $keys = array_unique([...array_keys($original), ...array_keys($proposed)]);
        sort($keys);

        foreach ($keys as $key) {
            $originalPresent = array_key_exists($key, $original);
            $proposedPresent = array_key_exists($key, $proposed);
            $path = '' === $prefix ? (string) $key : $prefix.(is_int($key) ? sprintf('[%d]', $key) : '.'.$key);
            $originalValue = $originalPresent ? $original[$key] : null;
            $proposedValue = $proposedPresent ? $proposed[$key] : null;

            if ($originalPresent && $proposedPresent && is_array($originalValue) && is_array($proposedValue)) {
                $this->collectChanges($originalValue, $proposedValue, $path, $changes);

                continue;
            }

            if ($originalPresent !== $proposedPresent || $originalValue !== $proposedValue) {
                $changes[] = new PayloadChange($path, $originalPresent, $originalValue, $proposedPresent, $proposedValue);
            }
        }
    }

    /**
     * @param list<PayloadChange> $changes
     */
    private function assertDiffSize(array $changes): void
    {
        try {
            $encoded = json_encode($changes, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('The dry-run diff cannot be represented as JSON.', previous: $exception);
        }

        if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('The dry-run diff exceeds the 65536-byte limit.');
        }
    }
}
