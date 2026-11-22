<?php

declare(strict_types=1);

namespace App\Tests\Integration\DlqDoctor\Agent;

use App\DlqDoctor\Agent\ConfiguredDlqDoctorAgent;
use App\DlqDoctor\Agent\DlqDoctorAgentRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProviderConfigurationTest extends KernelTestCase
{
    #[DataProvider('transientStatuses')]
    #[RunInSeparateProcess]
    public function testTransientFailuresAreRetriedBeforeReturningDiagnosis(int $status): void
    {
        $this->setGeminiEnvironment();
        self::bootKernel();
        $client = new MockHttpClient([
            new MockResponse('{"error":{"message":"temporarily unavailable"}}', ['http_code' => $status, 'response_headers' => ['retry-after: 0']]),
            new MockResponse('{"candidates":[{"content":{"parts":[{"text":"{\"diagnosis\":\"Recovered\",\"proposedPayloadJson\":null}"}]},"finishReason":"STOP"}]}', ['response_headers' => ['content-type: application/json']]),
        ]);
        static::getContainer()->set('http_client.transport', $client);
        $result = static::getContainer()->get(ConfiguredDlqDoctorAgent::class)->analyze(
            new DlqDoctorAgentRequest('App\\Message\\Example', 'RuntimeException', [], ['type' => 'object']),
        );
        self::assertSame('Recovered', $result->diagnosis);
        self::assertSame(2, $client->getRequestsCount());
    }

    public static function transientStatuses(): iterable
    {
        foreach ([429, 502, 503, 504] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('terminalFailures')]
    #[RunInSeparateProcess]
    public function testExhaustedOrPermanentFailureIsSurfaced(int $status, int $expectedRequests, string $exceptionClass): void
    {
        $this->setGeminiEnvironment();
        self::bootKernel();
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '{"error":{"message":"provider failure"}}',
            ['http_code' => $status, 'response_headers' => ['retry-after: 0']],
        ));
        static::getContainer()->set('http_client.transport', $client);
        try {
            static::getContainer()->get(ConfiguredDlqDoctorAgent::class)->analyze(
                new DlqDoctorAgentRequest('App\\Message\\Example', 'RuntimeException', [], ['type' => 'object']),
            );
            self::fail('Provider failure should be surfaced.');
        } catch (\Symfony\AI\Platform\Exception\ExceptionInterface $exception) {
            self::assertInstanceOf($exceptionClass, $exception);
            self::assertSame($expectedRequests, $client->getRequestsCount());
        }
    }

    public static function terminalFailures(): iterable
    {
        yield 'exhausted' => [503, 3, \Symfony\AI\Platform\Exception\ServerException::class];
        yield 'bad request' => [400, 1, \Symfony\AI\Platform\Exception\BadRequestException::class];
    }

    #[RunInSeparateProcess]
    public function testUnknownModelReportsProviderAndConfigurationWithoutHttp(): void
    {
        $this->setGeminiEnvironment('unknown-test-model');
        self::bootKernel();
        $client = new MockHttpClient();
        static::getContainer()->set('http_client.transport', $client);
        try {
            static::getContainer()->get(ConfiguredDlqDoctorAgent::class)->analyze(
                new DlqDoctorAgentRequest('App\\Message\\Example', 'RuntimeException', [], ['type' => 'object']),
            );
            self::fail('Unknown model should fail.');
        } catch (\Symfony\AI\Platform\Exception\ModelNotFoundException $exception) {
            self::assertStringContainsString('DLQ_AI_MODEL', $exception->getMessage());
            self::assertStringContainsString('gemini', $exception->getMessage());
            self::assertSame(0, $client->getRequestsCount());
        }
    }

    private function setGeminiEnvironment(string $model = 'gemini-2.5-flash'): void
    {
        foreach ([
            'APP_DEMO_DETERMINISTIC' => 'false',
            'DLQ_AI_PROVIDER' => 'gemini',
            'DLQ_AI_MODEL' => $model,
            'GEMINI_API_KEY' => 'gemini-test',
        ] as $key => $value) {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }
    }

    #[DataProvider('invalidConfigurations')]
    #[RunInSeparateProcess]
    public function testInvalidProviderConfigurationFailsWithoutAnHttpRequest(string $provider, string $message): void
    {
        foreach ([
            'APP_DEMO_DETERMINISTIC' => 'false',
            'DLQ_AI_PROVIDER' => $provider,
            'DLQ_AI_MODEL' => 'gpt-4o-mini',
            'OPENAI_API_KEY' => '',
            'ANTHROPIC_API_KEY' => '',
            'GEMINI_API_KEY' => '',
        ] as $key => $value) {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }

        self::bootKernel();
        $client = new MockHttpClient();
        static::getContainer()->set('http_client.transport', $client);

        try {
            static::getContainer()->get(ConfiguredDlqDoctorAgent::class)->analyze(
                new DlqDoctorAgentRequest('App\\Message\\Example', 'RuntimeException', [], ['type' => 'object']),
            );
            self::fail('Invalid provider configuration should fail.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
            self::assertSame(0, $client->getRequestsCount());
        }
    }

    public static function invalidConfigurations(): iterable
    {
        yield ['openai', 'OPENAI_API_KEY'];
        yield ['anthropic', 'ANTHROPIC_API_KEY'];
        yield ['gemini', 'GEMINI_API_KEY'];
        yield ['unknown', 'DLQ_AI_PROVIDER'];
    }

    #[DataProvider('providers')]
    #[RunInSeparateProcess]
    public function testConfiguredProviderProducesStructuredDiagnosis(string $provider, string $model, string $host, array $response): void
    {
        foreach ([
            'APP_DEMO_DETERMINISTIC' => 'false',
            'DLQ_AI_PROVIDER' => $provider,
            'DLQ_AI_MODEL' => $model,
            'OPENAI_API_KEY' => 'openai' === $provider ? 'sk-test' : '',
            'ANTHROPIC_API_KEY' => 'anthropic' === $provider ? 'anthropic-test' : '',
            'GEMINI_API_KEY' => 'gemini' === $provider ? 'gemini-test' : '',
        ] as $key => $value) {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }

        self::bootKernel();
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($provider, $host, $response): MockResponse {
            self::assertSame('POST', $method);
            self::assertStringContainsString($host, $url);
            $body = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR);
            match ($provider) {
                'openai' => self::assertSame('json_schema', $body['text']['format']['type']),
                'anthropic' => self::assertSame('json_schema', $body['output_config']['format']['type']),
                'gemini' => self::assertSame('application/json', $body['generationConfig']['responseMimeType']),
            };

            return new MockResponse(json_encode($response, JSON_THROW_ON_ERROR), ['response_headers' => ['Content-Type: application/json']]);
        });
        static::getContainer()->set('http_client.transport', $client);

        $result = static::getContainer()->get(ConfiguredDlqDoctorAgent::class)->analyze(
            new DlqDoctorAgentRequest('App\\Message\\Example', 'RuntimeException', ['id' => '1'], ['type' => 'object']),
        );

        self::assertSame('A test diagnosis.', $result->diagnosis);
        self::assertSame(['id' => '2'], $result->proposedPayload);
        self::assertSame(1, $client->getRequestsCount());
    }

    public static function providers(): iterable
    {
        $text = '{"diagnosis":"A test diagnosis.","proposedPayloadJson":"{\"id\":\"2\"}"}';

        yield 'openai' => ['openai', 'gpt-4o-mini', 'api.openai.com', [
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]]],
        ]];
        yield 'anthropic' => ['anthropic', 'claude-sonnet-4-6', 'api.anthropic.com', [
            'content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn',
        ]];
        yield 'gemini' => ['gemini', 'gemini-2.5-flash', 'generativelanguage.googleapis.com', [
            'candidates' => [['content' => ['parts' => [['text' => $text]]], 'finishReason' => 'STOP']],
        ]];
    }
}
