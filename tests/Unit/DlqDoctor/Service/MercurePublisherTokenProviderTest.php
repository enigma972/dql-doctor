<?php

declare(strict_types=1);

namespace App\Tests\Unit\DlqDoctor\Service;

use App\DlqDoctor\Service\MercurePublisherTokenProvider;
use App\DlqDoctor\Service\ProposalTopic;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;

final class MercurePublisherTokenProviderTest extends TestCase
{
    public function testGlobalPublishSelectorIsGrantedOnlyWhenGlobalModeIsAllowed(): void
    {
        $globalProvider = $this->provider(false);
        self::assertSame('signed-token', $globalProvider->getJwt());

        $tenantOnlyProvider = $this->provider(true);
        self::assertSame('signed-token', $tenantOnlyProvider->getJwt());
    }

    private function provider(bool $requireTenant): MercurePublisherTokenProvider
    {
        $factory = $this->createMock(TokenFactoryInterface::class);
        $factory->expects(self::once())
            ->method('create')
            ->with(self::callback(static function (array $grants) use ($requireTenant): bool {
                $expected = [[
                    [Grant::ACTION_PUBLISH],
                    ['urlpattern' => ['http://localhost/dlq-doctor/proposals/{tenantId}']],
                ]];
                $actual = array_map(
                    static fn (Grant $grant): array => [$grant->actions, $grant->topics],
                    $grants,
                );
                if (!$requireTenant) {
                    $expected[] = [
                        [Grant::ACTION_PUBLISH],
                        ['exact' => ['http://localhost/dlq-doctor/proposals']],
                    ];
                }

                return $actual === $expected;
            }), [
                'aud' => [
                    'http://app/.well-known/mercure',
                    'http://localhost/.well-known/mercure',
                ],
            ])
            ->willReturn('signed-token');

        return new MercurePublisherTokenProvider(
            $factory,
            new ProposalTopic(),
            $requireTenant,
            'http://app/.well-known/mercure',
            'http://localhost/.well-known/mercure',
        );
    }
}
