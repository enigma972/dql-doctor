<?php

declare(strict_types=1);

namespace App\DlqDoctor\Attribute;

use App\DlqDoctor\Enum\SensitivityLevel;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Sensitivity
{
    public function __construct(public SensitivityLevel $level)
    {
    }
}