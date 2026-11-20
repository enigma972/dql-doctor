<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use RuntimeException;
use Throwable;

final class PatchDeserializationException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The proposed payload cannot be deserialized as the original message class.', previous: $previous);
    }
}
