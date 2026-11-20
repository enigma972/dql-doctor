<?php

declare(strict_types=1);

namespace App\Tests\Integration\DlqDoctor\Service;

use App\DlqDoctor\Service\MercurePublisherTokenProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MercurePublisherTokenProviderTest extends KernelTestCase
{
    public function testConfiguredPublisherTokenHasTenantAndGlobalSelectorsInDemoMode(): void
    {
        $token = static::getContainer()->get(MercurePublisherTokenProvider::class)->getJwt();
        $parts = explode('.', $token);
        self::assertCount(3, $parts);

        $payload = json_decode(
            base64_decode(strtr($parts[1], '-_', '+/'), true),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($payload);
        self::assertSame('http://localhost', $payload['iss']);
        self::assertContains('http://localhost/.well-known/mercure', $payload['aud']);

        $publishGrants = array_values(array_filter(
            $payload['authorization_details'],
            static fn (array $grant): bool => in_array('publish', $grant['actions'], true),
        ));
        self::assertCount(2, $publishGrants);
        self::assertSame(
            ['match' => 'http://localhost/dlq-doctor/proposals/{tenantId}', 'match_type' => 'urlpattern'],
            $publishGrants[0]['topics'][0],
        );
        self::assertSame(
            ['match' => 'http://localhost/dlq-doctor/proposals'],
            $publishGrants[1]['topics'][0],
        );
    }
}
