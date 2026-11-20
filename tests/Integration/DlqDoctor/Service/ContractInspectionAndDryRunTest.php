<?php

declare(strict_types=1);

namespace App\Tests\Integration\DlqDoctor\Service;

use App\DlqDoctor\Messenger\TenantStamp;
use App\DlqDoctor\Service\ContractInspector;
use App\DlqDoctor\Service\FailedMessageReader;
use App\DlqDoctor\Service\FailedMessageTransport;
use App\DlqDoctor\Service\PatchDryRunService;
use App\DlqDoctor\Service\PatchDeserializationException;
use App\Tests\Fixtures\DlqDoctor\FixedTenantContext;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\PropertyInfo\PropertyInfoExtractorInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class ContractInspectionAndDryRunTest extends KernelTestCase
{
    public function testContractInspectionIncludesSupportedTypesAndConstraints(): void
    {
        $contract = $this->contractInspector()->inspect(InspectableMessage::class);

        self::assertSame(InspectableMessage::class, $contract->className);
        self::assertSame(['type' => 'string', 'minLength' => 2, 'maxLength' => 20, 'enum' => ['open', 'closed']], $contract->jsonSchema['properties']['status']);
        self::assertContains(
            sprintf('%s: class-level %s constraint', InspectableMessage::class, Assert\Callback::class),
            $contract->unsupportedConstraints,
        );
        self::assertContains(sprintf('%s::$tags: PHP array shape and item types are not represented', InspectableMessage::class), $contract->unsupportedConstraints);
        self::assertNotContains(
            sprintf('%s::$status: validation group "InspectableMessage"', InspectableMessage::class),
            $contract->unsupportedConstraints,
        );
        self::assertSame(['description', 'status'], $contract->jsonSchema['required']);
        self::assertFalse($contract->jsonSchema['additionalProperties']);
        self::assertEqualsCanonicalizing(['array', 'object'], $contract->jsonSchema['properties']['tags']['type']);
        self::assertEquals(new \stdClass(), $contract->jsonSchema['properties']['tags']['items']);
        self::assertEqualsCanonicalizing(
            ['string', 'null'],
            $contract->jsonSchema['properties']['mode']['type'],
        );
        self::assertEqualsCanonicalizing(
            [null, 'open', 'closed'],
            $contract->jsonSchema['properties']['mode']['enum'],
        );
    }

    public function testDryRunReturnsDiffWithoutReplayingAcknowledgingOrMutatingTheMessage(): void
    {
        $message = new InspectableMessage('open', 'old');
        $transport = $this->transportFor($message, 'message-1');
        $service = $this->dryRunService($transport);

        $result = $service->dryRun('message-1', ['status' => 'closed', 'description' => 'new']);

        self::assertTrue($result->valid);
        self::assertSame(InspectableMessage::class, $result->messageClass);
        self::assertCount(2, $result->diff);
        self::assertSame('description', $result->diff[0]->path);
        self::assertSame('old', $result->diff[0]->originalValue);
        self::assertSame('new', $result->diff[0]->proposedValue);
        self::assertSame('open', $message->status);
        self::assertSame('old', $message->description);
    }

    public function testDryRunDistinguishesValidationErrorsFromDeserializationErrors(): void
    {
        $transport = $this->transportFor(new InspectableMessage('open', 'valid'), 'message-2');
        $service = $this->dryRunService($transport);

        $invalid = $service->dryRun('message-2', ['status' => 'unknown', 'description' => '']);
        self::assertFalse($invalid->valid);
        self::assertNotEmpty($invalid->violations);
        self::assertEqualsCanonicalizing(
            ['description', 'status'],
            array_map(static fn ($violation): string => $violation->propertyPath, $invalid->violations),
        );

        $this->expectException(PatchDeserializationException::class);
        $service->dryRun('message-2', ['status' => 'open', 'description' => 'valid', 'class' => 'stdClass']);
    }

    public function testDryRunRejectsAValueThatCannotBeDeserializedToTheOriginalPropertyType(): void
    {
        $transport = $this->transportFor(new InspectableMessage('open', 'valid'), 'message-typed');

        $this->expectException(PatchDeserializationException::class);
        $this->dryRunService($transport)->dryRun('message-typed', ['status' => ['class' => 'stdClass'], 'description' => 'valid']);
    }

    public function testDryRunRejectsObjectsInTheProposedJsonPayload(): void
    {
        $transport = $this->transportFor(new InspectableMessage('open', 'valid'), 'message-object');

        $this->expectException(\InvalidArgumentException::class);
        $this->dryRunService($transport)->dryRun('message-object', [
            'status' => 'open',
            'description' => new \stdClass(),
        ]);
    }

    public function testDryRunRejectsCircularPayloads(): void
    {
        $transport = $this->transportFor(new InspectableMessage('open', 'valid'), 'message-circular');
        $recursive = [];
        $recursive['self'] = &$recursive;

        $this->expectException(\InvalidArgumentException::class);
        $this->dryRunService($transport)->dryRun('message-circular', [
            'status' => 'open',
            'description' => $recursive,
        ]);
    }

    public function testDryRunRejectsOversizedPatches(): void
    {
        $transport = $this->transportFor(new InspectableMessage('open', 'valid'), 'message-3');

        $this->expectException(\InvalidArgumentException::class);
        $this->dryRunService($transport)->dryRun('message-3', [
            'status' => 'open',
            'description' => str_repeat('x', 65_536),
        ]);
    }

    public function testDryRunDoesNotExposeFailuresOwnedByAnotherTenant(): void
    {
        $transport = $this->transportFor(new InspectableMessage('open', 'valid'), 'message-4', 'other-tenant');

        $this->expectException(\App\DlqDoctor\Service\FailedMessageNotFoundException::class);
        $this->dryRunService($transport)->dryRun('message-4', ['status' => 'closed', 'description' => 'new']);
    }

    public function testDryRunRequiresViewerAuthorizationBeforeReadingTheTransport(): void
    {
        $transport = $this->createMock(FailedMessageTransport::class);
        $transport->expects(self::never())->method('find');
        $security = $this->createMock(Security::class);
        $security->expects(self::once())->method('isGranted')->with('ROLE_DLQ_VIEWER')->willReturn(false);
        $container = static::getContainer();
        $service = new PatchDryRunService(
            $transport,
            $this->contractInspector(),
            new FixedTenantContext('tenant-a'),
            $security,
            $container->get(NormalizerInterface::class),
            $container->get(DenormalizerInterface::class),
            $container->get(\Symfony\Component\Validator\Validator\ValidatorInterface::class),
        );

        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        $service->dryRun('message-denied', ['status' => 'closed', 'description' => 'new']);
    }

    #[DataProvider('invalidContractNames')]
    public function testContractInspectionRejectsUnknownOrNonInstantiableTypes(string $className): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->contractInspector()->inspect($className);
    }

    public static function invalidContractNames(): iterable
    {
        yield 'unknown type' => ['App\\Tests\\Fixtures\\DlqDoctor\\MissingMessage'];
        yield 'non-instantiable type' => [\Throwable::class];
    }

    private function dryRunService(FailedMessageReader $transport): PatchDryRunService
    {
        $security = $this->createMock(Security::class);
        $security->expects(self::atLeastOnce())->method('isGranted')->with('ROLE_DLQ_VIEWER')->willReturn(true);
        $container = static::getContainer();

        return new PatchDryRunService(
            $transport,
            $this->contractInspector(),
            new FixedTenantContext('tenant-a'),
            $security,
            $container->get(NormalizerInterface::class),
            $container->get(DenormalizerInterface::class),
            $container->get(\Symfony\Component\Validator\Validator\ValidatorInterface::class),
        );
    }

    private function contractInspector(): ContractInspector
    {
        $container = static::getContainer();

        return new ContractInspector(
            $container->get(PropertyInfoExtractorInterface::class),
            $container->get(\Symfony\Component\Validator\Validator\ValidatorInterface::class),
        );
    }

    private function transportFor(object $message, string $messageId, string $tenantId = 'tenant-a'): FailedMessageReader
    {
        $transport = $this->createMock(FailedMessageReader::class);
        $transport->expects(self::atLeastOnce())
            ->method('find')
            ->with($messageId)
            ->willReturn(new Envelope($message, [
                new TenantStamp($tenantId),
                new TransportMessageIdStamp($messageId),
            ]));
        return $transport;
    }
}

#[Assert\Callback('validateMessage')]
final readonly class InspectableMessage
{
    public function __construct(
        #[Assert\Length(min: 2, max: 20)]
        #[Assert\Choice(choices: ['open', 'closed'])]
        public string $status,
        #[Assert\NotBlank]
        public string $description,
        public array $tags = [],
        public ?InspectableMode $mode = null,
    ) {
    }

    public function validateMessage(ExecutionContextInterface $context): void
    {
    }
}

enum InspectableMode: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
}
