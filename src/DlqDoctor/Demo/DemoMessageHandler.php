<?php

declare(strict_types=1);

namespace App\DlqDoctor\Demo;

use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[When('dev')]
#[AsMessageHandler]
final class DemoMessageHandler
{
    public function __invoke(DemoMessage $message): void
    {
        // A replay of this synthetic message has no business side effects.
    }
}
