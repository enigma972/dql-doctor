<?php

declare(strict_types=1);

namespace App\Tests\Unit\DlqDoctor\Service;

use App\DlqDoctor\Service\ProposalTopic;
use PHPUnit\Framework\TestCase;

final class ProposalTopicTest extends TestCase
{
    public function testGlobalTopicUsesTheDedicatedProposalPath(): void
    {
        self::assertSame('http://localhost/dlq-doctor/proposals', (new ProposalTopic())->forTenant(null));
    }

    public function testTenantTopicAppendsAnEncodedTenantIdentifier(): void
    {
        self::assertSame(
            'http://localhost/dlq-doctor/proposals/tenant%2Fa',
            (new ProposalTopic())->forTenant('tenant/a'),
        );
    }

    public function testItRejectsInvalidTenantIdentifiers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ProposalTopic())->forTenant('  ');
    }
}
