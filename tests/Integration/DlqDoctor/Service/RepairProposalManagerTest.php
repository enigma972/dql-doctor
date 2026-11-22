<?php

declare(strict_types=1);

namespace App\Tests\Integration\DlqDoctor\Service;

use App\DlqDoctor\Entity\RepairProposal;
use App\DlqDoctor\Service\RepairProposalManager;
use App\Tests\Fixtures\DlqDoctor\FixedTenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class RepairProposalManagerTest extends KernelTestCase
{
    public function testProposalQueriesAreTenantScopedAndReturnOnlySummaryFields(): void
    {
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $tenantId = bin2hex(random_bytes(16));
        $otherTenantId = bin2hex(random_bytes(16));
        $security = $this->createMock(Security::class);
        $security->expects(self::atLeastOnce())->method('isGranted')->with('ROLE_DLQ_VIEWER')->willReturn(true);
        $proposal = $this->proposal($tenantId, 'private-payload');
        $otherProposal = $this->proposal($otherTenantId, 'other-private-payload');
        $entityManager->persist($proposal);
        $entityManager->persist($otherProposal);
        $entityManager->flush();
        $proposalId = $proposal->getId();
        $otherProposalId = $otherProposal->getId();
        self::assertNotNull($proposalId);
        self::assertNotNull($otherProposalId);

        try {
            $manager = new RepairProposalManager($entityManager, new FixedTenantContext($tenantId), $security);
            $summaries = $manager->listForCurrentTenant();

            self::assertCount(1, $summaries);
            self::assertSame($proposalId, $summaries[0]->id);
            self::assertSame($proposalId, $manager->findSummaryForCurrentTenant($proposalId)?->id);
            self::assertNull($manager->findSummaryForCurrentTenant($otherProposalId));
            self::assertFalse(property_exists($summaries[0], 'originalPayload'));
            self::assertFalse(property_exists($summaries[0], 'approvalTokenHash'));
        } finally {
            $entityManager->remove($proposal);
            $entityManager->remove($otherProposal);
            $entityManager->flush();
        }
    }

    public function testProposalAccessRequiresTheViewerRole(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects(self::once())->method('isGranted')->with('ROLE_DLQ_VIEWER')->willReturn(false);
        $manager = new RepairProposalManager(
            $this->createStub(EntityManagerInterface::class),
            new FixedTenantContext('tenant-a'),
            $security,
        );

        $this->expectException(AccessDeniedException::class);
        $manager->listForCurrentTenant();
    }

    public function testGlobalProposalQueriesIncludeOnlyGlobalRows(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $globalProposal = $this->proposal(null, 'global-payload');
        $tenantProposal = $this->proposal('tenant-a', 'tenant-payload');
        $entityManager->persist($globalProposal);
        $entityManager->persist($tenantProposal);
        $entityManager->flush();
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(true);

        try {
            $manager = new RepairProposalManager($entityManager, new FixedTenantContext(null), $security);
            $summaries = $manager->listForCurrentTenant();

            self::assertCount(1, $summaries);
            self::assertSame($globalProposal->getId(), $summaries[0]->id);
            self::assertSame($globalProposal->getId(), $manager->findSummaryForCurrentTenant($globalProposal->getId())?->id);
            self::assertNull($manager->findSummaryForCurrentTenant($tenantProposal->getId()));
        } finally {
            $entityManager->remove($globalProposal);
            $entityManager->remove($tenantProposal);
            $entityManager->flush();
        }
    }

    private function proposal(?string $tenantId, string $payload): RepairProposal
    {
        return new RepairProposal(
            $tenantId,
            bin2hex(random_bytes(8)),
            'App\\Message\\PrivateMessage',
            ['payload' => $payload],
            null,
            null,
            null,
            bin2hex(random_bytes(16)),
            new \DateTimeImmutable('+15 minutes'),
        );
    }
}
