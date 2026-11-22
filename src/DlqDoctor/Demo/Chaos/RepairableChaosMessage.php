<?php

declare(strict_types=1);

namespace App\DlqDoctor\Demo\Chaos;

use Symfony\Component\Validator\Constraints as Assert;

abstract class RepairableChaosMessage extends ChaosMessage
{
    #[Assert\NotBlank]
    public string $status = 'ready';

    public function __construct(string $reference)
    {
        parent::__construct($reference);
        $this->status = 'invalid';
    }
}
