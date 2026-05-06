<?php

declare(strict_types=1);

namespace Maxi\Tests\Registry;

use Maxi\Attribute\MaxiField;
use Maxi\Attribute\MaxiType;
use Maxi\Registry\MaxiSchemaRegistry;
use PHPUnit\Framework\TestCase;

#[MaxiType(alias: 'RU', name: 'RegistryUser')]
class RegistryTestUser
{
    #[MaxiField(typeExpr: 'int', id: true, required: true)]
    public int $id;

    #[MaxiField(required: true)]
    public string $name;

    #[MaxiField(annotation: 'email', defaultValue: 'none@example.com')]
    public string $email = 'none@example.com';
}

class NoSchemaClass
{
}

class SchemaRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        MaxiSchemaRegistry::reset();
    }

    // ── PHP Attributes ───────────────────────────────────────────────────────

    public function testGetFromAttribute(): void
    {
        $schema = MaxiSchemaRegistry::get(RegistryTestUser::class);
        $this->assertNotNull($schema);
        $this->assertSame('RU', $schema['alias']);
        $this->assertSame('RegistryUser', $schema['name']);
    }

    public function testGetFromAttributeViaInstance(): void
    {
        $user = new RegistryTestUser();
        $schema = MaxiSchemaRegistry::get($user);
        $this->assertNotNull($schema);
        $this->assertSame('RU', $schema['alias']);
    }

    public function testFieldsResolvedFromAttributes(): void
    {
        $schema = MaxiSchemaRegistry::get(RegistryTestUser::class);
        $fields = $schema['fields'];

        $this->assertCount(3, $fields);

        $idField = $fields[0];
        $this->assertSame('id', $idField['name']);
        $this->assertSame('int', $idField['typeExpr']);

        // id + required constraints
        $types = array_column($idField['constraints'], 'type');
        $this->assertContains('required', $types);
        $this->assertContains('id', $types);
    }

    public function testFieldAnnotationPreserved(): void
    {
        $schema = MaxiSchemaRegistry::get(RegistryTestUser::class);
        $emailField = array_filter($schema['fields'], fn($f) => $f['name'] === 'email');
        $emailField = array_values($emailField)[0];
        $this->assertSame('email', $emailField['annotation']);
    }

    public function testDefaultValueFromAttribute(): void
    {
        $schema = MaxiSchemaRegistry::get(RegistryTestUser::class);
        $emailField = array_values(array_filter($schema['fields'], fn($f) => $f['name'] === 'email'))[0];
        $this->assertSame('none@example.com', $emailField['defaultValue']);
    }

    // ── Manual define ────────────────────────────────────────────────────────

    public function testManualDefine(): void
    {
        MaxiSchemaRegistry::define(NoSchemaClass::class, [
            'alias' => 'NS',
            'name' => null,
            'fields' => [['name' => 'value']],
        ]);

        $schema = MaxiSchemaRegistry::get(NoSchemaClass::class);
        $this->assertNotNull($schema);
        $this->assertSame('NS', $schema['alias']);
    }

    public function testManualDefineRequiresAlias(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MaxiSchemaRegistry::define(NoSchemaClass::class, ['fields' => []]);
    }

    public function testUndefine(): void
    {
        MaxiSchemaRegistry::define(NoSchemaClass::class, ['alias' => 'NS', 'fields' => []]);
        MaxiSchemaRegistry::undefine(NoSchemaClass::class);
        $this->assertNull(MaxiSchemaRegistry::get(NoSchemaClass::class));
    }

    // ── No schema ────────────────────────────────────────────────────────────

    public function testClassWithNoSchemaReturnsNull(): void
    {
        $this->assertNull(MaxiSchemaRegistry::get(NoSchemaClass::class));
    }

    // ── Static $maxiSchema property ──────────────────────────────────────────

    public function testStaticMaxiSchemaProperty(): void
    {
        $class = new class {
            /** @var array<string,mixed> */
            public static array $maxiSchema = [
                'alias' => 'SP',
                'name' => 'StaticProp',
                'fields' => [['name' => 'id', 'typeExpr' => 'int']],
            ];
        };

        $schema = MaxiSchemaRegistry::get($class);
        $this->assertNotNull($schema);
        $this->assertSame('SP', $schema['alias']);
    }

    // ── Caching ──────────────────────────────────────────────────────────────

    public function testReflectionResultIsCached(): void
    {
        $schema1 = MaxiSchemaRegistry::get(RegistryTestUser::class);
        $schema2 = MaxiSchemaRegistry::get(RegistryTestUser::class);
        // Same array reference (cached)
        $this->assertSame($schema1, $schema2);
    }
}
