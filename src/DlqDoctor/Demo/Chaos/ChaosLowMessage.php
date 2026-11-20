<?php

declare(strict_types=1);

namespace App\DlqDoctor\Demo\Chaos;

use App\DlqDoctor\Attribute\Sensitivity;
use App\DlqDoctor\Enum\SensitivityLevel;
use Symfony\Component\Validator\Constraints as Assert;

#[Sensitivity(SensitivityLevel::LOW)]
final class ChaosLowMessage extends ChaosMessage
{
    #[Assert\NotBlank]
    public string $status = 'ready';

    public function __construct(string $reference)
    {
        parent::__construct($reference);
        $this->status = 'invalid';
    }
}
