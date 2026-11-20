<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use App\DlqDoctor\Attribute\Sensitivity;
use App\DlqDoctor\Enum\SensitivityLevel;
use ReflectionClass;

final class SensitivityResolver
{
    /**
     * @param class-string $messageClass
     */
    public function resolve(string $messageClass): SensitivityLevel
    {
        $reflection = new ReflectionClass($messageClass);
        $attributes = $reflection->getAttributes(Sensitivity::class);

        if ($attributes === []) {
            return SensitivityLevel::HIGH;
        }

        return $attributes[0]->newInstance()->level;
    }
}