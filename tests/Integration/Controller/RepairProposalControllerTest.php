<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\DlqDoctor\Entity\RepairProposal;
use App\DlqDoctor\Enum\RepairProposalStatus;
use App\DlqDoctor\Messenger\TenantStamp;
use App\DlqDoctor\Service\FailedMessageTransport;
use App\DlqDoctor\Service\IdempotencyStore;
use App\Tests\Fixtures\DlqDoctor\GlobalTestUser;
use App\Tests\Fixtures\DlqDoctor\TenantTestUser;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

final class RepairProposalControllerTest extends WebTestCase
{
    private string $tenantId;
    private int $proposalId;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->tenantId = bin2hex(random_bytes(16));
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $proposal = $this->newProposal($this->tenantId, $this->sendFailure($this->tenantId));
        $entityManager->persist($proposal);
        $entityManager->flush();
        $this->proposalId = $proposal->getId() ?? throw new \LogicException('The test proposal was not persisted.');
    }

    protected function tearDown(): void
    {
        if (isset($this->tenantId)) {
            $entityManager = static::getContainer()->get(EntityManagerInterface::class);
            $failedTransport = static::getContainer()->get(FailedMessageTransport::class);
            foreach ($entityManager->getRepository(RepairProposal::class)->findBy(['tenantId' => $this->tenantId]) as $proposal) {
                if (null !== $envelope = $failedTransport->find($proposal->failedMessageId())) {
                    $failedTransport->acknowledge($envelope);
                }
                $entityManager->remove($proposal);
            }
            $entityManager->flush();

            $connection = static::getContainer()->get(Connection::class);
            $connection->delete('dlq_mercure_outbox', ['tenant_id' => $this->tenantId]);
            $connection->delete('dlq_audit_log', ['tenant_id' => $this->tenantId]);
            $connection->delete('dlq_idempotency_record', ['tenant_id' => $this->tenantId]);
        }

        parent::tearDown();
    }

    public function testAuthenticatedOperatorCanApproveAndReplayOnlyOnce(): void
    {
        $this->client->loginUser($this->operator($this->tenantId));
        $form = $this->reviewForm($this->client, 'approve');

        $this->client->request('POST', '/proposals/'.$this->proposalId.'/approve', [
            '_token' => $form['csrf'],
            'approval_token' => $form['approval'],
        ]);
        self::assertResponseRedirects('/proposals/'.$this->proposalId);
        $this->client->followRedirect();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $proposal = $entityManager->find(RepairProposal::class, $this->proposalId);
        self::assertSame(RepairProposalStatus::REPLAYED, $proposal?->status());
        self::assertNull(static::getContainer()->get(FailedMessageTransport::class)->find($proposal->failedMessageId()));
        self::assertSame(
            ['approved', 'replayed'],
            $this->outboxStatuses(),
        );

        $this->client->request('POST', '/proposals/'.$this->proposalId.'/approve', [
            '_token' => $form['csrf'],
            'approval_token' => $form['approval'],
        ]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Only a pending proposal can be approved.');
    }

    public function testOperatorCanRejectWithoutReplaying(): void
    {
        $this->client->loginUser($this->operator($this->tenantId));
        $form = $this->reviewForm($this->client, 'reject');

        $this->client->request('POST', '/proposals/'.$this->proposalId.'/reject', [
            '_token' => $form['csrf'],
            'approval_token' => $form['approval'],
        ]);
        $this->client->followRedirect();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $proposal = $entityManager->find(RepairProposal::class, $this->proposalId);
        self::assertSame(RepairProposalStatus::REJECTED, $proposal?->status());
        self::assertNotNull(static::getContainer()->get(FailedMessageTransport::class)->find($proposal?->failedMessageId() ?? ''));
        self::assertSame(['rejected'], $this->outboxStatuses());
    }

    public function testInvalidApprovalTokenDoesNotClaimReplay(): void
    {
        $this->client->loginUser($this->operator($this->tenantId));
        $form = $this->reviewForm($this->client, 'approve');

        $this->client->request('POST', '/proposals/'.$this->proposalId.'/approve', [
            '_token' => $form['csrf'],
            'approval_token' => 'not-issued',
        ]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'The approval token is invalid or has already been used.');

        $form = $this->reviewForm($this->client, 'approve');
        $this->client->request('POST', '/proposals/'.$this->proposalId.'/approve', [
            '_token' => $form['csrf'],
            'approval_token' => $form['approval'],
        ]);

        self::assertResponseRedirects('/proposals/'.$this->proposalId);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertSame(RepairProposalStatus::REPLAYED, $entityManager->find(RepairProposal::class, $this->proposalId)?->status());
    }

    public function testExistingReplayClaimBlocksConcurrentApproval(): void
    {
        $this->client->loginUser($this->operator($this->tenantId));
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $proposal = $entityManager->find(RepairProposal::class, $this->proposalId);
        self::assertNotNull($proposal);

        $approvalKey = hash('sha256', "approve_replay\0".$proposal->idempotencyKey());
        self::assertTrue(static::getContainer()->get(IdempotencyStore::class)->claim($approvalKey, 'approve_replay'));

        $form = $this->reviewForm($this->client, 'approve');
        $this->client->request('POST', '/proposals/'.$this->proposalId.'/approve', [
            '_token' => $form['csrf'],
            'approval_token' => $form['approval'],
        ]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'This proposal replay is already in progress or has already completed.');

        $entityManager->clear();
        self::assertSame(RepairProposalStatus::PENDING_APPROVAL, $entityManager->find(RepairProposal::class, $this->proposalId)?->status());
        self::assertNotNull(static::getContainer()->get(FailedMessageTransport::class)->find($proposal->failedMessageId()));
    }

    public function testExpiredProposalCannotBeApproved(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $expired = $this->newProposal(
            $this->tenantId,
            $this->sendFailure($this->tenantId),
            new \DateTimeImmutable('-1 hour'),
            hash('sha256', 'expired-token'),
        );
        $entityManager->persist($expired);
        $entityManager->flush();
        $expiredId = $expired->getId() ?? throw new \LogicException('The expired proposal was not persisted.');

        $this->client->loginUser($this->operator($this->tenantId));
        $status = static::getContainer()->get(\App\DlqDoctor\Service\RepairProposalWorkflow::class)
            ->approve($expiredId, 'expired-token');

        $entityManager->clear();
        self::assertSame(RepairProposalStatus::EXPIRED, $entityManager->find(RepairProposal::class, $expiredId)?->status());
        self::assertSame(RepairProposalStatus::EXPIRED, $status);
    }

    public function testAnotherTenantCannotViewOrActOnAProposal(): void
    {
        $this->client->loginUser($this->operator($this->tenantId));
        $form = $this->reviewForm($this->client, 'approve');
        $this->client->loginUser($this->operator(bin2hex(random_bytes(16))));

        $this->client->request('GET', '/proposals/'.$this->proposalId);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/proposals/'.$this->proposalId.'/approve', [
            '_token' => $form['csrf'],
            'approval_token' => $form['approval'],
        ]);
        self::assertResponseStatusCodeSame(404);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertSame(RepairProposalStatus::PENDING_APPROVAL, $entityManager->find(RepairProposal::class, $this->proposalId)?->status());
    }

    public function testDetailRequiresAuthentication(): void
    {
        $this->client->request('GET', '/proposals/'.$this->proposalId);
        self::assertResponseRedirects('/login');

        $this->client->loginUser(new TenantTestUser('viewer-'.bin2hex(random_bytes(8)), $this->tenantId, ['ROLE_DLQ_VIEWER']));
        $this->client->request('GET', '/proposals/'.$this->proposalId);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTenantProposalPageSubscribesOnlyToItsTenantTopic(): void
    {
        $this->client->loginUser($this->operator($this->tenantId));

        $crawler = $this->client->request('GET', '/proposals');
        self::assertResponseIsSuccessful();
        $url = (string) $crawler->filter('main[data-controller="proposal-updates"]')->attr('data-proposal-updates-url-value');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame('http://localhost/dlq-doctor/proposals/'.$this->tenantId, $query['match'] ?? null);
    }

    public function testGlobalProposalPageSubscribesOnlyToTheGlobalTopic(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $globalProposal = $this->newProposal(null, 'global-message-'.bin2hex(random_bytes(8)));
        $entityManager->persist($globalProposal);
        $entityManager->flush();

        try {
            $this->client->loginUser(new GlobalTestUser('global-viewer', ['ROLE_DLQ_VIEWER']));
            $crawler = $this->client->request('GET', '/proposals');
            self::assertResponseIsSuccessful();

            $rows = $crawler->filter('tbody tr');
            self::assertCount(1, $rows);
            self::assertStringContainsString($globalProposal->failedMessageId(), $rows->text());

            $url = (string) $crawler->filter('main[data-controller="proposal-updates"]')->attr('data-proposal-updates-url-value');
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            self::assertSame('http://localhost/dlq-doctor/proposals', $query['match'] ?? null);
        } finally {
            $entityManager->remove($globalProposal);
            $entityManager->flush();
        }
    }

    public function testApprovalRequiresCsrf(): void
    {
        $this->client->loginUser($this->operator($this->tenantId));
        $this->client->request('POST', '/proposals/'.$this->proposalId.'/approve', [
            'approval_token' => 'not-issued',
        ]);
        self::assertResponseStatusCodeSame(403);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertSame(RepairProposalStatus::PENDING_APPROVAL, $entityManager->find(RepairProposal::class, $this->proposalId)?->status());
    }

    /**
     * @return array{csrf: string, approval: string}
     */
    private function reviewForm(\Symfony\Component\BrowserKit\AbstractBrowser $client, string $action): array
    {
        $crawler = $client->request('GET', '/proposals/'.$this->proposalId);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter(sprintf('form[action="/proposals/%d/%s"]', $this->proposalId, $action));

        return [
            'csrf' => (string) $form->filter('input[name="_token"]')->attr('value'),
            'approval' => (string) $form->filter('input[name="approval_token"]')->attr('value'),
        ];
    }

    private function operator(string $tenant): TenantTestUser
    {
        return new TenantTestUser('operator-'.bin2hex(random_bytes(8)), $tenant, ['ROLE_DLQ_OPERATOR']);
    }

    private function newProposal(
        ?string $tenant,
        string $failedMessageId,
        ?\DateTimeImmutable $expiresAt = null,
        ?string $tokenHash = null,
    ): RepairProposal
    {
        return new RepairProposal(
            $tenant,
            $failedMessageId,
            ApprovalFixtureMessage::class,
            ['reference' => 'reviewed-payload'],
            null,
            null,
            $tokenHash,
            bin2hex(random_bytes(32)),
            $expiresAt ?? new \DateTimeImmutable('+15 minutes'),
        );
    }

    private function sendFailure(string $tenant): string
    {
        $sender = static::getContainer()->get('messenger.transport.failed');
        self::assertInstanceOf(SenderInterface::class, $sender);
        $sent = $sender->send(new Envelope(new ApprovalFixtureMessage('reviewed-payload'), [
            new TenantStamp($tenant),
            new ErrorDetailsStamp(\RuntimeException::class, 0, 'test failure'),
        ]));
        $messageId = $sent->last(TransportMessageIdStamp::class)?->getId();
        self::assertNotNull($messageId);

        return (string) $messageId;
    }

    /**
     * @return list<string>
     */
    private function outboxStatuses(): array
    {
        $events = static::getContainer()->get(Connection::class)->fetchAllAssociative(
            'SELECT data FROM dlq_mercure_outbox WHERE tenant_id = ? ORDER BY id',
            [$this->tenantId],
        );

        return array_map(
            static fn (array $event): string => json_decode((string) $event['data'], true, flags: JSON_THROW_ON_ERROR)['status'],
            $events,
        );
    }
}

final readonly class ApprovalFixtureMessage
{
    public function __construct(public string $reference)
    {
    }
}
