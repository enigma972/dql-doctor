<?php

declare(strict_types=1);

namespace App\DlqDoctor\Demo\Chaos;

use App\DlqDoctor\Attribute\Sensitivity;
use App\DlqDoctor\Enum\SensitivityLevel;

#[Sensitivity(SensitivityLevel::MEDIUM)]
final class RepairableChaosMediumMessage extends RepairableChaosMessage
{
}
