<?php

declare(strict_types=1);

namespace App\DlqDoctor\Context;

use RuntimeException;

final class MissingTenantContextException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A tenant context is required for this operation.');
    }
}
