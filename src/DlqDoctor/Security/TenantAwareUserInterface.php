<?php

declare(strict_types=1);

namespace App\DlqDoctor\Security;

use Symfony\Component\Security\Core\User\UserInterface;

interface TenantAwareUserInterface extends UserInterface
{
    public function tenantId(): string;
}
