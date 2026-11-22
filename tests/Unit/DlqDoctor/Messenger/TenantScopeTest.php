<?php

declare(strict_types=1);

namespace App\Tests\Unit\DlqDoctor\Messenger;

use App\DlqDoctor\Context\MissingTenantContextException;
use App\DlqDoctor\Messenger\GlobalTenantStamp;
use App\DlqDoctor\Messenger\TenantScope;
use App\DlqDoctor\Messenger\TenantStamp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;

final class TenantScopeTest extends TestCase
{
    public function testGlobalScopeMatchesOnlyExplicitGlobalStamp(): void
    {
        self::assertTrue(TenantScope::matches(new Envelope(new \stdClass(), [new GlobalTenantStamp()]), null));
        self::assertFalse(TenantScope::matches(new Envelope(new \stdClass()), null));
        self::assertFalse(TenantScope::matches(new Envelope(new \stdClass(), [new TenantStamp('tenant-a')]), null));
    }

    public function testTenantScopeDoesNotMatchGlobalOrUnstampedMessages(): void
    {
        self::assertTrue(TenantScope::matches(new Envelope(new \stdClass(), [new TenantStamp('tenant-a')]), 'tenant-a'));
        self::assertFalse(TenantScope::matches(new Envelope(new \stdClass(), [new TenantStamp('tenant-b')]), 'tenant-a'));
        self::assertFalse(TenantScope::matches(new Envelope(new \stdClass(), [new GlobalTenantStamp()]), 'tenant-a'));
        self::assertFalse(TenantScope::matches(new Envelope(new \stdClass()), 'tenant-a'));
    }

    public function testTenantScopeRejectsAnEnvelopeWithConflictingDuplicateStamps(): void
    {
        self::assertFalse(TenantScope::matches(
            new Envelope(new \stdClass(), [new TenantStamp('tenant-a'), new TenantStamp('tenant-b')]),
            'tenant-a',
        ));
    }

    public function testTenantRequiredGuardFailsBeforeNullablePersistenceCanProceed(): void
    {
        $this->expectException(MissingTenantContextException::class);
        TenantScope::requireTenantId(new class implements \App\DlqDoctor\Context\TenantContextInterface {
            public function tenantId(): ?string
            {
                return null;
            }

            public function actorId(): ?string
            {
                return null;
            }
        });
    }
}
