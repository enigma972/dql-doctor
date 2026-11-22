<?php

declare(strict_types=1);

namespace App\DlqDoctor\Agent;

final class DeterministicMockAgent implements DlqDoctorAgentInterface
{
    public function analyze(DlqDoctorAgentRequest $request): DlqDoctorAgentResult
    {
        $proposedPayload = $request->payload;
        $properties = $request->jsonSchema['properties'] ?? [];
        $required = $request->jsonSchema['required'] ?? [];
        $changed = false;

        if (is_array($properties) && is_array($required)) {
            foreach ($required as $propertyName) {
                if (!is_string($propertyName)
                    || !is_array($properties[$propertyName] ?? null)
                    || !array_key_exists('default', $properties[$propertyName])
                    || (array_key_exists($propertyName, $proposedPayload)
                        && $proposedPayload[$propertyName] === $properties[$propertyName]['default'])
                ) {
                    continue;
                }

                $proposedPayload[$propertyName] = $properties[$propertyName]['default'];
                $changed = true;
            }
        }

        return new DlqDoctorAgentResult(
            $changed
                ? sprintf('A %s failure was detected for %s. Required fields that differ from their explicit schema defaults were proposed.', $request->errorClass, $request->messageClass)
                : sprintf('A %s failure was detected for %s. No deterministic diagnosis or safe patch rule applies.', $request->errorClass, $request->messageClass),
            $changed ? $proposedPayload : null,
        );
    }
}
