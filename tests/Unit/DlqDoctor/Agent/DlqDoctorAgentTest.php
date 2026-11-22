<?php

declare(strict_types=1);

namespace App\Tests\Unit\DlqDoctor\Agent;

use App\DlqDoctor\Agent\DlqDoctorAgentRequest;
use App\DlqDoctor\Agent\ConfiguredDlqDoctorAgent;
use App\DlqDoctor\Agent\DeterministicMockAgent;
use App\DlqDoctor\Agent\DlqDoctorAgentInterface;
use App\DlqDoctor\Agent\SymfonyAiDlqDoctorAgent;
use App\DlqDoctor\Agent\SymfonyAiAgentResponse;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\Update\Result as ResultUpdate;
use Symfony\AI\Platform\Result\ObjectResult;

final class DlqDoctorAgentTest extends TestCase
{
    public function testDeterministicAgentProducesStablePatchOnlyFromExplicitSchemaDefaults(): void
    {
        $request = new DlqDoctorAgentRequest(
            'App\\Message\\OrderCreated',
            'RuntimeException',
            ['orderId' => 'order-17'],
            [
                'type' => 'object',
                'properties' => [
                    'orderId' => ['type' => 'string'],
                    'currency' => ['type' => 'string', 'default' => 'USD'],
                    'metadata' => ['type' => 'null', 'default' => null],
                ],
                'required' => ['orderId', 'currency', 'metadata'],
            ],
        );
        $agent = new DeterministicMockAgent();

        $first = $agent->analyze($request);
        $second = $agent->analyze($request);

        self::assertSame($first->diagnosis, $second->diagnosis);
        self::assertSame($first->proposedPayload, $second->proposedPayload);
        self::assertSame(
            'A RuntimeException failure was detected for App\\Message\\OrderCreated. Required fields that differ from their explicit schema defaults were proposed.',
            $first->diagnosis,
        );
        self::assertSame(['orderId' => 'order-17', 'currency' => 'USD', 'metadata' => null], $first->proposedPayload);
    }

    public function testRequestAndResultEnforceJsonSizeAndShapeLimits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DlqDoctorAgentRequest(
            'App\\Message\\Large',
            'RuntimeException',
            ['payload' => str_repeat('x', 65_537)],
            ['type' => 'object'],
        );
    }

    public function testRequestEnforcesCombinedSizeLimitAndEncodesEmptyObjectsAsObjects(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The DLQ agent request cannot exceed');

        new DlqDoctorAgentRequest(
            'App\\Message\\Large',
            'RuntimeException',
            ['value' => str_repeat('x', 65_520)],
            ['value' => str_repeat('x', 65_520)],
        );
    }

    public function testResultRejectsOversizedProposedPayload(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new \App\DlqDoctor\Agent\DlqDoctorAgentResult('Diagnosis', ['payload' => str_repeat('x', 65_537)]);
    }

    public function testRequestAcceptsSchemaObjectsButRejectsNonJsonPayloadObjects(): void
    {
        $request = new DlqDoctorAgentRequest(
            'App\\Message\\ListMessage',
            'RuntimeException',
            ['items' => []],
            ['type' => 'object', 'properties' => ['items' => ['items' => new \stdClass()]]],
        );
        self::assertSame('App\\Message\\ListMessage', $request->messageClass);

        $this->expectException(\InvalidArgumentException::class);
        new DlqDoctorAgentRequest(
            'App\\Message\\Unsafe',
            'RuntimeException',
            ['value' => new \DateTimeImmutable()],
            ['type' => 'object'],
        );
    }

    public function testOptionalProviderUsesStructuredOutputAndReturnsValidatedProposal(): void
    {
        $response = new SymfonyAiAgentResponse(
            'The payload is missing a required field.',
            '{"orderId":"order-17","currency":"USD"}',
        );
        $agent = $this->createMock(AgentInterface::class);
        $agent->expects(self::once())
            ->method('call')
            ->with(
                self::callback(static function (string $input): bool {
                    $decoded = json_decode($input, true, flags: JSON_THROW_ON_ERROR);

                    return 'App\\Message\\OrderCreated' === $decoded['messageClass']
                        && !array_key_exists('tenantId', $decoded);
                }),
                ['response_format' => SymfonyAiAgentResponse::class],
            )
            ->willReturn($this->execution($response));

        $result = (new SymfonyAiDlqDoctorAgent($agent, 'configured-key'))->analyze(
            new DlqDoctorAgentRequest(
                'App\\Message\\OrderCreated',
                'RuntimeException',
                ['orderId' => 'order-17'],
                ['type' => 'object', 'required' => ['orderId', 'currency']],
            ),
        );

        self::assertSame('The payload is missing a required field.', $result->diagnosis);
        self::assertSame(['orderId' => 'order-17', 'currency' => 'USD'], $result->proposedPayload);
    }

    public function testOptionalProviderWithoutApiKeyFailsBeforeCallingTheModel(): void
    {
        $agent = $this->createMock(AgentInterface::class);
        $agent->expects(self::never())->method('call');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('OPENAI_API_KEY');
        (new SymfonyAiDlqDoctorAgent($agent, ''))->analyze(
            new DlqDoctorAgentRequest('App\\Message\\Safe', 'RuntimeException', [], ['type' => 'object']),
        );
    }

    public function testOptionalProviderRejectsInvalidJsonPatch(): void
    {
        $agent = $this->createMock(AgentInterface::class);
        $agent->expects(self::once())->method('call')
            ->willReturn($this->execution(new SymfonyAiAgentResponse('Diagnosis', '{not-json')));

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('invalid JSON patch');
        (new SymfonyAiDlqDoctorAgent($agent, 'configured-key'))->analyze(
            new DlqDoctorAgentRequest('App\\Message\\Safe', 'RuntimeException', [], ['type' => 'object']),
        );
    }

    public function testConfiguredAgentDefaultsToDeterministicModeWithoutCallingExternalProvider(): void
    {
        $external = $this->createMock(DlqDoctorAgentInterface::class);
        $external->expects(self::never())->method('analyze');
        $request = new DlqDoctorAgentRequest('App\\Message\\Safe', 'RuntimeException', [], ['type' => 'object']);
        $agent = new ConfiguredDlqDoctorAgent(new DeterministicMockAgent(), $external, true, '');

        self::assertSame(
            (new DeterministicMockAgent())->analyze($request)->diagnosis,
            $agent->analyze($request)->diagnosis,
        );
    }

    public function testConfiguredExternalModeFailsExplicitlyWhenApiKeyIsMissing(): void
    {
        $external = $this->createMock(DlqDoctorAgentInterface::class);
        $external->expects(self::never())->method('analyze');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('OPENAI_API_KEY');
        (new ConfiguredDlqDoctorAgent(new DeterministicMockAgent(), $external, false, ''))->analyze(
            new DlqDoctorAgentRequest('App\\Message\\Safe', 'RuntimeException', [], ['type' => 'object']),
        );
    }

    private function execution(SymfonyAiAgentResponse $result): Execution
    {
        return new Execution(static function () use ($result): \Generator {
            yield new ResultUpdate(new ObjectResult($result));
        });
    }
}
