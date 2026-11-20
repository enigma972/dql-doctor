<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use App\DlqDoctor\Dto\RepairProposalSummary;
use App\DlqDoctor\Entity\RepairProposal;
use App\DlqDoctor\Context\TenantContextInterface;
use App\DlqDoctor\Messenger\TenantScope;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class RepairProposalManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContextInterface $tenantContext,
        private Security $security,
    ) {
    }

    /**
     * @return list<RepairProposalSummary>
     */
    public function listForCurrentTenant(int $limit = 100): array
    {
        $this->denyUnlessAuthorized();

        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('The proposal list limit must be between 1 and 500.');
        }

        $proposals = $this->entityManager->getRepository(RepairProposal::class)->findBy(
            ['tenantId' => TenantScope::requireTenantId($this->tenantContext)],
            ['createdAt' => 'DESC'],
            $limit,
        );

        return array_map(
            static fn (RepairProposal $proposal): RepairProposalSummary => $proposal->summary(),
            $proposals,
        );
    }

    public function findSummaryForCurrentTenant(int $id): ?RepairProposalSummary
    {
        $this->denyUnlessAuthorized();

        if ($id < 1) {
            throw new InvalidArgumentException('A positive proposal ID is required.');
        }

        $proposal = $this->entityManager->getRepository(RepairProposal::class)->findOneBy([
            'id' => $id,
            'tenantId' => TenantScope::requireTenantId($this->tenantContext),
        ]);

        return $proposal instanceof RepairProposal ? $proposal->summary() : null;
    }

    private function denyUnlessAuthorized(): void
    {
        if (!$this->security->isGranted('ROLE_DLQ_VIEWER')) {
            throw new AccessDeniedException('The current user cannot view repair proposals.');
        }
    }
}
