<?php

declare(strict_types=1);

namespace App\Tests\Integration\Mcp;

use App\Mcp\DlqDoctorTools;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class DlqDoctorToolsTest extends WebTestCase
{
    public function testDlqToolsHaveExplicitSchemasAndNoTenantSelector(): void
    {
        $methods = (new \ReflectionClass(DlqDoctorTools::class))->getMethods(\ReflectionMethod::IS_PUBLIC);
        $tools = [];

        foreach ($methods as $method) {
            $toolAttributes = $method->getAttributes(McpTool::class);
            if ([] === $toolAttributes) {
                continue;
            }

            $tool = $toolAttributes[0]->newInstance();
            $tools[] = $tool->name;
            self::assertNotEmpty($tool->outputSchema);
            self::assertNotEmpty($method->getAttributes(Schema::class));

            foreach ($method->getParameters() as $parameter) {
                self::assertNotSame('tenantId', $parameter->getName());
            }
        }

        self::assertSame([
            'dlq_list_failures',
            'dlq_inspect_contract',
            'dlq_dry_run_patch',
            'dlq_replay_message',
            'dlq_discard_message',
        ], $tools);
    }

    public function testUnauthenticatedCallerIsDeniedBeforeListingFailures(): void
    {
        self::bootKernel();
        $tools = static::getContainer()->get(DlqDoctorTools::class);

        $this->expectException(AccessDeniedException::class);
        $tools->listFailures();
    }

    public function testMcpHttpEndpointRequiresViewerRole(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/mcp',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}',
        );

        self::assertResponseStatusCodeSame(401);
    }
}
