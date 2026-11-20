<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use RuntimeException;

final class FailedMessageNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The failed message was not found for the current tenant.');
    }
}
