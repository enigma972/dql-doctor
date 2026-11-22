<?php

declare(strict_types=1);

namespace App\Tests\Unit\DlqDoctor\Service;

use App\DlqDoctor\Attribute\Sensitivity;
use App\DlqDoctor\Enum\DlqAction;
use App\DlqDoctor\Messenger\TenantStamp;
use App\DlqDoctor\Service\AuditLoggerInterface;
use App\DlqDoctor\Service\FailedMessageDiscarder;
use App\DlqDoctor\Service\FailedMessageNotFoundException;
use App\DlqDoctor\Service\FailedMessageTransport;
use App\DlqDoctor\Service\IdempotencyStore;
use App\DlqDoctor\Service\PolicyEvaluator;
use App\DlqDoctor\Service\SensitivityResolver;
use App\Tests\Fixtures\DlqDoctor\FixedTenantContext;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class FailedMessageDiscarderTest extends TestCase
{
    public function testLowSensitivityMessageIsAuditedAndDiscardedIdempotently(): void
    {
        $events = [];
        $transport = $this->createMock(FailedMessageTransport::class);
        $transport->expects(self::once())->method('find')->with('message-1')->willReturn($this->envelope('message-1'));
        $transport->expects(self::once())->method('acknowledge')->willReturnCallback(
            static function () use (&$events): void {
                $events[] = 'acknowledged';
            },
        );
        $idempotency = $this->createMock(IdempotencyStore::class);
        $idempotency->expects(self::once())->method('claim')->with(
            hash('sha256', DlqAction::DISCARD->value."\0message-1"),
            DlqAction::DISCARD->value,
        )->willReturn(true);
        $idempotency->expects(self::once())->method('complete')->with(
            hash('sha256', DlqAction::DISCARD->value."\0message-1"),
            ['outcome' => 'discarded'],
        );
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects(self::exactly(2))->method('log')->willReturnCallback(
            static function (string $action, array $context, ?string $messageId) use (&$events): void {
                self::assertContains($action, ['discard_requested', 'discarded']);
                self::assertSame(['sensitivity' => 'low'], $context);
                self::assertSame('message-1', $messageId);
                $events[] = $action;
            },
        );

        $discarder = $this->discarder(
            $transport,
            $idempotency,
            $audit,
            ['low' => 'auto_audit'],
        );

        self::assertSame('discarded', $discarder->discard('message-1')->value);
        self::assertSame(['discard_requested', 'acknowledged', 'discarded'], $events);
    }

    public function testAuditFailurePreventsDiscardingTheMessage(): void
    {
        $transport = $this->createMock(FailedMessageTransport::class);
        $transport->expects(self::once())->method('find')->willReturn($this->envelope('message-audit'));
        $transport->expects(self::never())->method('acknowledge');
        $idempotency = $this->createMock(IdempotencyStore::class);
        $idempotency->expects(self::once())->method('claim')->willReturn(true);
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects(self::once())->method('log')->with(
            'discard_requested',
            ['sensitivity' => 'low'],
            'message-audit',
        )->willThrowException(new \RuntimeException('audit unavailable'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('audit unavailable');
        $this->discarder(
            $transport,
            $idempotency,
            $audit,
            ['low' => 'auto_audit'],
        )->discard('message-audit');
    }

    public function testApprovalRequiredOrForbiddenMessageIsNotDiscarded(): void
    {
        foreach ([
            [new DiscardLowSensitivityFailure(), 'approval'],
            [new DiscardHighSensitivityFailure(), 'forbidden'],
        ] as [$message, $decision]) {
            $transport = $this->createMock(FailedMessageTransport::class);
            $transport->expects(self::once())->method('find')->willReturn($this->envelope('message-2', $message));
            $transport->expects(self::never())->method('acknowledge');
            $idempotency = $this->createMock(IdempotencyStore::class);
            $idempotency->expects(self::never())->method('claim');
            $audit = $this->createMock(AuditLoggerInterface::class);
            $audit->expects(self::never())->method('log');

            try {
                $this->discarder($transport, $idempotency, $audit, [
                    'low' => 'approval',
                    'high' => 'forbidden',
                ])->discard('message-2');
                self::fail(sprintf('The %s decision must reject discarding.', $decision));
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
    }

    public function testMessageOwnedByAnotherTenantIsNotDiscarded(): void
    {
        $transport = $this->createMock(FailedMessageTransport::class);
        $transport->expects(self::once())->method('find')->willReturn(
            $this->envelope('message-3', new DiscardLowSensitivityFailure(), 'tenant-b'),
        );
        $transport->expects(self::never())->method('acknowledge');
        $idempotency = $this->createMock(IdempotencyStore::class);
        $idempotency->expects(self::never())->method('claim');

        $this->expectException(FailedMessageNotFoundException::class);
        $this->discarder(
            $transport,
            $idempotency,
            $this->createStub(AuditLoggerInterface::class),
            ['low' => 'auto_audit'],
        )->discard('message-3');
    }

    public function testCompletedDiscardClaimAcknowledgesStaleFailureWithoutAuditingAgain(): void
    {
        $transport = $this->createMock(FailedMessageTransport::class);
        $transport->expects(self::once())->method('find')->willReturn($this->envelope('message-5'));
        $transport->expects(self::once())->method('acknowledge');
        $idempotency = $this->createMock(IdempotencyStore::class);
        $idempotency->expects(self::once())->method('claim')->willReturn(false);
        $idempotency->expects(self::once())->method('result')->willReturn(['outcome' => 'discarded']);
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects(self::never())->method('log');

        $outcome = $this->discarder(
            $transport,
            $idempotency,
            $audit,
            ['low' => 'auto_audit'],
        )->discard('message-5');

        self::assertSame('already_discarded', $outcome->value);
    }

    public function testInProgressDiscardClaimIsNotAcknowledged(): void
    {
        $transport = $this->createMock(FailedMessageTransport::class);
        $transport->expects(self::once())->method('find')->willReturn($this->envelope('message-6'));
        $transport->expects(self::never())->method('acknowledge');
        $idempotency = $this->createMock(IdempotencyStore::class);
        $idempotency->expects(self::once())->method('claim')->willReturn(false);
        $idempotency->expects(self::once())->method('result')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already in progress');

        $this->discarder(
            $transport,
            $idempotency,
            $this->createStub(AuditLoggerInterface::class),
            ['low' => 'auto_audit'],
        )->discard('message-6');
    }

    public function testOperatorAuthorizationIsCheckedBeforeReadingTheMessage(): void
    {
        $transport = $this->createMock(FailedMessageTransport::class);
        $transport->expects(self::never())->method('find');
        $security = $this->createMock(Security::class);
        $security->expects(self::once())->method('isGranted')->with('ROLE_DLQ_OPERATOR')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->discarder(
            $transport,
            $this->createStub(IdempotencyStore::class),
            $this->createStub(AuditLoggerInterface::class),
            ['low' => 'auto_audit'],
            $security,
        )->discard('message-4');
    }

    /**
     * @param array<string, string> $discardDecisions
     */
    private function discarder(
        FailedMessageTransport $transport,
        IdempotencyStore $idempotency,
        AuditLoggerInterface $audit,
        array $discardDecisions,
        ?Security $security = null,
    ): FailedMessageDiscarder {
        return new FailedMessageDiscarder(
            $transport,
            new PolicyEvaluator($this->policy($discardDecisions)),
            new SensitivityResolver(),
            new FixedTenantContext('tenant-a'),
            $security ?? $this->authorizedSecurity(),
            $idempotency,
            $audit,
        );
    }

    private function authorizedSecurity(): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnMap([
            ['ROLE_DLQ_VIEWER', true],
            ['ROLE_DLQ_OPERATOR', true],
        ]);

        return $security;
    }

    /**
     * @param array<string, string> $discardDecisions
     *
     * @return array<string, array<string, string>>
     */
    private function policy(array $discardDecisions): array
    {
        $policy = [];
        foreach (['low', 'medium', 'high'] as $sensitivity) {
            $policy[$sensitivity] = [
                'replay' => 'auto',
                'patch' => 'approval',
                'discard' => $discardDecisions[$sensitivity] ?? 'approval',
            ];
        }

        return $policy;
    }

    private function envelope(
        string $messageId,
        object $message = new DiscardLowSensitivityFailure(),
        string $tenantId = 'tenant-a',
    ): Envelope {
        return new Envelope($message, [
            new TenantStamp($tenantId),
            new TransportMessageIdStamp($messageId),
        ]);
    }
}

#[Sensitivity(\App\DlqDoctor\Enum\SensitivityLevel::LOW)]
final readonly class DiscardLowSensitivityFailure
{
}

#[Sensitivity(\App\DlqDoctor\Enum\SensitivityLevel::HIGH)]
final readonly class DiscardHighSensitivityFailure
{
}
