<?php

declare(strict_types=1);

namespace App\DlqDoctor\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class GlobalTenantStamp implements StampInterface
{
}
