<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use App\DlqDoctor\Entity\MercureOutboxMessage;
use App\DlqDoctor\Entity\RepairProposal;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProposalEventRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProposalTopic $topic,
    ) {
    }

    public function record(RepairProposal $proposal): void
    {
        $this->entityManager->persist(new MercureOutboxMessage(
            $proposal->tenantId(),
            $this->topic->forTenant($proposal->tenantId()),
            'proposal.updated',
            ['status' => $proposal->status()->value],
        ));
    }
}
