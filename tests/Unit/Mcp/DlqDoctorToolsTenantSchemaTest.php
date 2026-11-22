<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\Mcp\DlqDoctorTools;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DlqDoctorToolsTenantSchemaTest extends TestCase
{
    public function testMcpToolArgumentsAndSchemasDoNotExposeTenantSelection(): void
    {
        $reflection = new ReflectionClass(DlqDoctorTools::class);
        foreach ($reflection->getMethods() as $method) {
            if ([] === $method->getAttributes(McpTool::class)) {
                continue;
            }

            foreach ($method->getParameters() as $parameter) {
                self::assertNotSame('tenant_id', strtolower($parameter->getName()));
                self::assertNotSame('tenantid', strtolower($parameter->getName()));
            }

            foreach ($method->getAttributes(Schema::class) as $attribute) {
                $definition = $attribute->newInstance()->definition;
                if (null !== $definition) {
                    self::assertFalse($this->containsTenantSelection($definition));
                }
            }
        }
    }

    /**
     * @param array<mixed> $schema
     */
    private function containsTenantSelection(array $schema): bool
    {
        foreach ($schema as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), ['tenant_id', 'tenantid'], true)) {
                return true;
            }

            if (is_array($value) && $this->containsTenantSelection($value)) {
                return true;
            }
        }

        return false;
    }
}
