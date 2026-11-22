<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use App\DlqDoctor\Dto\AutomaticPatchResult;

interface AutomaticPatchApplierInterface
{
    public function apply(object $message, string $errorClass): ?AutomaticPatchResult;
}
