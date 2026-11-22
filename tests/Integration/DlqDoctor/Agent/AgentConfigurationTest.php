<?php

declare(strict_types=1);

namespace App\Tests\Integration\DlqDoctor\Agent;

use App\DlqDoctor\Agent\ConfiguredDlqDoctorAgent;
use App\DlqDoctor\Agent\DlqDoctorAgentRequest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AgentConfigurationTest extends KernelTestCase
{
    public function testDefaultAgentRunsDeterministicallyWithoutAnExternalApiKey(): void
    {
        self::bootKernel();
        $agent = static::getContainer()->get(ConfiguredDlqDoctorAgent::class);
        $request = new DlqDoctorAgentRequest(
            'App\\Message\\OrderCreated',
            'RuntimeException',
            ['orderId' => 'order-17'],
            ['type' => 'object', 'properties' => ['orderId' => ['type' => 'string']]],
        );

        self::assertSame(
            'A RuntimeException failure was detected for App\\Message\\OrderCreated. No deterministic diagnosis or safe patch rule applies.',
            $agent->analyze($request)->diagnosis,
        );
    }
}
