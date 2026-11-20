<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use App\DlqDoctor\Agent\DlqDoctorAgentInterface;
use App\DlqDoctor\Agent\DlqDoctorAgentRequest;
use App\DlqDoctor\Dto\AutomaticPatchResult;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use TypeError;
use UnexpectedValueException;

final readonly class AutomaticPatchApplier implements AutomaticPatchApplierInterface
{
    public function __construct(
        private DlqDoctorAgentInterface $agent,
        private ContractInspector $contractInspector,
        private NormalizerInterface $normalizer,
        private DenormalizerInterface $denormalizer,
        private ValidatorInterface $validator,
    ) {
    }

    public function apply(object $message, string $errorClass): ?AutomaticPatchResult
    {
        $originalPayload = $this->normalizeToArray($message);
        $contract = $this->contractInspector->inspect($message::class);
        $result = $this->agent->analyze(new DlqDoctorAgentRequest(
            $message::class,
            $errorClass,
            $originalPayload,
            $contract->jsonSchema,
        ));
        if (null === $result->proposedPayload) {
            return null;
        }

        try {
            $encodedPayload = json_encode($result->proposedPayload, JSON_THROW_ON_ERROR);
            $proposedPayload = json_decode($encodedPayload, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The automatic patch is not a valid JSON payload.', previous: $exception);
        }
        if (!is_array($proposedPayload)) {
            throw new InvalidArgumentException('The automatic patch must be a JSON object or array.');
        }

        try {
            $patchedMessage = $this->denormalizer->denormalize(
                $proposedPayload,
                $message::class,
                'json',
                [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false],
            );
        } catch (ExceptionInterface|TypeError $exception) {
            throw new PatchDeserializationException($exception);
        }
        if (!is_object($patchedMessage) || $message::class !== $patchedMessage::class) {
            throw new PatchDeserializationException();
        }

        if (count($this->validator->validate($patchedMessage)) > 0) {
            throw new InvalidArgumentException('The automatic patch does not satisfy message validation.');
        }

        $patchedPayload = $this->normalizeToArray($patchedMessage);
        $changedProperties = [];
        foreach (array_unique([...array_keys($originalPayload), ...array_keys($patchedPayload)]) as $property) {
            if (($originalPayload[$property] ?? null) !== ($patchedPayload[$property] ?? null)
                || array_key_exists($property, $originalPayload) !== array_key_exists($property, $patchedPayload)
            ) {
                $changedProperties[] = (string) $property;
            }
        }
        sort($changedProperties);

        return [] === $changedProperties ? null : new AutomaticPatchResult($patchedMessage, $changedProperties);
    }

    /**
     * @return array<string|int, mixed>
     */
    private function normalizeToArray(object $message): array
    {
        $payload = $this->normalizer->normalize($message);
        if (!is_array($payload)) {
            throw new UnexpectedValueException('The failed message did not normalize to an object payload.');
        }

        return $payload;
    }
}
