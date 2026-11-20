<?php

declare(strict_types=1);

namespace App\Tests\Integration\Mcp;

use App\DlqDoctor\Messenger\GlobalTenantStamp;
use App\DlqDoctor\Messenger\TenantStamp;
use App\DlqDoctor\Service\FailedMessageTransport;
use App\Tests\Fixtures\DlqDoctor\GlobalTestUser;
use App\Tests\Fixtures\DlqDoctor\TenantTestUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Mime\Email;

final class TenantModesTest extends WebTestCase
{
    public function testHttpToolListsOnlyFailuresInTheAuthenticatedScope(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $container = static::getContainer();
        $sender = $container->get('messenger.transport.failed');
        $receiver = $container->get(FailedMessageTransport::class);
        $tenantId = bin2hex(random_bytes(16));
        $messageIds = [];

        try {
            foreach ([new GlobalTenantStamp(), new TenantStamp($tenantId)] as $stamp) {
                $messageIds[] = (string) $sender->send(new Envelope(new SendEmailMessage(new Email()), [$stamp]))
                    ->last(TransportMessageIdStamp::class)->getId();
            }

            foreach ([
                new GlobalTestUser('global-viewer', ['ROLE_DLQ_VIEWER']),
                new TenantTestUser('tenant-viewer', $tenantId, ['ROLE_DLQ_VIEWER']),
            ] as $scope => $user) {
                $client->loginUser($user);
                $headers = [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_ACCEPT' => 'application/json, text/event-stream',
                ];
                $client->request('POST', '/mcp', server: $headers, content: json_encode([
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'initialize',
                    'params' => [
                        'protocolVersion' => '2025-11-25',
                        'capabilities' => (object) [],
                        'clientInfo' => ['name' => 'scope-test', 'version' => '1.0'],
                    ],
                ], JSON_THROW_ON_ERROR));
                self::assertResponseIsSuccessful();
                $session = $client->getResponse()->headers->get('Mcp-Session-Id');
                self::assertNotEmpty($session);
                $headers['HTTP_MCP_SESSION_ID'] = $session;
                $client->request('POST', '/mcp', server: $headers, content: '{"jsonrpc":"2.0","method":"notifications/initialized"}');
                self::assertResponseStatusCodeSame(202);

                $client->request('POST', '/mcp', server: $headers, content: json_encode([
                    'jsonrpc' => '2.0',
                    'id' => 2,
                    'method' => 'tools/call',
                    'params' => ['name' => 'dlq_list_failures', 'arguments' => ['limit' => 500]],
                ], JSON_THROW_ON_ERROR));
                self::assertResponseIsSuccessful();
                $response = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
                $ids = array_column($response['result']['structuredContent']['failures'], 'id');
                self::assertContains($messageIds[$scope], $ids);
                self::assertNotContains($messageIds[1 - $scope], $ids);
            }
        } finally {
            foreach ($messageIds as $id) {
                if (null !== $envelope = $receiver->find($id)) {
                    $receiver->acknowledge($envelope);
                }
            }
        }
    }
}
