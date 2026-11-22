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
        static::getContainer()->set('http_client', $client);

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
        static::getContainer()->set('http_client', $client);

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
