<?php

declare(strict_types=1);

namespace App\Tests\Integration\DlqDoctor\Context;

use App\DlqDoctor\Context\NullTenantResolver;
use App\DlqDoctor\Context\TenantContext;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TenantContextConfigurationTest extends KernelTestCase
{
    public function testDemoDefaultsToNullableTenantResolution(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        self::assertFalse($container->getParameter('dlq_doctor.require_tenant'));
        self::assertInstanceOf(NullTenantResolver::class, $container->get(NullTenantResolver::class));
        self::assertNull($container->get(TenantContext::class)->resolve());
    }
}
