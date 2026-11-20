<?php

declare(strict_types=1);

namespace App\Controller;

use App\DlqDoctor\Enum\RepairProposalStatus;
use App\DlqDoctor\Context\TenantContextInterface;
use App\DlqDoctor\Messenger\TenantScope;
use App\DlqDoctor\Service\FailedMessageNotFoundException;
use App\DlqDoctor\Service\ProposalTopic;
use App\DlqDoctor\Service\RepairProposalManager;
use App\DlqDoctor\Service\RepairProposalWorkflow;
use DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/proposals', name: 'dlq_proposals_')]
final class RepairProposalController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    #[IsGranted('ROLE_DLQ_VIEWER')]
    public function index(
        RepairProposalManager $proposalManager,
        TenantContextInterface $tenantContext,
        ProposalTopic $topic,
    ): Response {
        return $this->render('proposal/index.html.twig', [
            'proposals' => $proposalManager->listForCurrentTenant(),
            'topic' => $topic->forTenant(TenantScope::requireTenantId($tenantContext)),
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_DLQ_OPERATOR')]
    public function show(int $id, RepairProposalWorkflow $workflow, TenantContextInterface $tenantContext, ProposalTopic $topic): Response
    {
        $proposal = $workflow->review($id);
        if (null === $proposal) {
            throw $this->createNotFoundException();
        }

        return $this->render('proposal/show.html.twig', [
            'proposal' => $proposal,
            'topic' => $topic->forTenant(TenantScope::requireTenantId($tenantContext)),
        ]);
    }

    #[Route('/{id}/approve', name: 'approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_DLQ_OPERATOR')]
    public function approve(int $id, Request $request, RepairProposalWorkflow $workflow): RedirectResponse
    {
        $csrfToken = $request->request->get('_token');
        if (!is_string($csrfToken) || !$this->isCsrfTokenValid('approve_proposal_'.$id, $csrfToken)) {
            throw $this->createAccessDeniedException('The proposal approval form has an invalid CSRF token.');
        }

        try {
            $status = $workflow->approve($id, $this->approvalToken($request));
            $this->addFlash(
                RepairProposalStatus::EXPIRED === $status ? 'warning' : 'success',
                RepairProposalStatus::EXPIRED === $status ? 'This proposal expired before it could be approved.' : 'The proposal was approved and replayed.',
            );
        } catch (DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (FailedMessageNotFoundException $exception) {
            throw $this->createNotFoundException(previous: $exception);
        }

        return $this->redirectToRoute('dlq_proposals_show', ['id' => $id]);
    }

    #[Route('/{id}/reject', name: 'reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_DLQ_OPERATOR')]
    public function reject(int $id, Request $request, RepairProposalWorkflow $workflow): RedirectResponse
    {
        $csrfToken = $request->request->get('_token');
        if (!is_string($csrfToken) || !$this->isCsrfTokenValid('reject_proposal_'.$id, $csrfToken)) {
            throw $this->createAccessDeniedException('The proposal rejection form has an invalid CSRF token.');
        }

        try {
            $status = $workflow->reject($id, $this->approvalToken($request));
            $this->addFlash(
                RepairProposalStatus::EXPIRED === $status ? 'warning' : 'success',
                RepairProposalStatus::EXPIRED === $status ? 'This proposal expired before it could be rejected.' : 'The proposal was rejected.',
            );
        } catch (DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (FailedMessageNotFoundException $exception) {
            throw $this->createNotFoundException(previous: $exception);
        }

        return $this->redirectToRoute('dlq_proposals_show', ['id' => $id]);
    }

    private function approvalToken(Request $request): string
    {
        $token = $request->request->get('approval_token');
        if (!is_string($token)) {
            throw $this->createAccessDeniedException('An approval token is required.');
        }

        return $token;
    }
}
