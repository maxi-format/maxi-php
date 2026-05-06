<?php

declare(strict_types=1);

namespace Maxi\Tests\Attribute;

use Maxi\Attribute\MaxiField;
use Maxi\Attribute\MaxiType;
use Maxi\Registry\MaxiSchemaRegistry;
use PHPUnit\Framework\TestCase;

#[MaxiType(alias: 'AT', name: 'AttrTest', parents: ['Base'])]
class AttrTestEntity
{
    #[MaxiField(typeExpr: 'int', id: true, required: true)]
    public int $id;

    #[MaxiField(typeExpr: 'str', required: true, constraints: '>=2,<=100')]
    public string $username;

    #[MaxiField(annotation: 'email')]
    public ?string $email = null;

    #[MaxiField(typeExpr: 'enum[admin,user,guest]', defaultValue: 'guest')]
    public string $role = 'guest';

    #[MaxiField(name: 'custom_name')]
    public string $internalProp = '';
}

class AttributeTest extends TestCase
{
    protected function setUp(): void
    {
        MaxiSchemaRegistry::reset();
    }

    public function testMaxiTypeAttributeOnClass(): void
    {
        $ref = new \ReflectionClass(AttrTestEntity::class);
        $attrs = $ref->getAttributes(MaxiType::class);
        $this->assertCount(1, $attrs);

        /** @var MaxiType $attr */
        $attr = $attrs[0]->newInstance();
        $this->assertSame('AT', $attr->alias);
        $this->assertSame('AttrTest', $attr->name);
        $this->assertSame(['Base'], $attr->parents);
    }

    public function testMaxiFieldAttributeOnProperty(): void
    {
        $ref = new \ReflectionClass(AttrTestEntity::class);
        $prop = $ref->getProperty('id');
        $attrs = $prop->getAttributes(MaxiField::class);
        $this->assertCount(1, $attrs);

        /** @var MaxiField $attr */
        $attr = $attrs[0]->newInstance();
        $this->assertSame('int', $attr->typeExpr);
        $this->assertTrue($attr->id);
        $this->assertTrue($attr->required);
    }

    public function testMaxiFieldAnnotation(): void
    {
        $ref = new \ReflectionClass(AttrTestEntity::class);
        $prop = $ref->getProperty('email');
        $attrs = $prop->getAttributes(MaxiField::class);
        $attr = $attrs[0]->newInstance();
        $this->assertSame('email', $attr->annotation);
    }

    public function testMaxiFieldDefaultValue(): void
    {
        $ref = new \ReflectionClass(AttrTestEntity::class);
        $prop = $ref->getProperty('role');
        $attrs = $prop->getAttributes(MaxiField::class);
        $attr = $attrs[0]->newInstance();
        $this->assertSame('guest', $attr->defaultValue);
    }

    public function testMaxiFieldNameOverride(): void
    {
        $ref = new \ReflectionClass(AttrTestEntity::class);
        $prop = $ref->getProperty('internalProp');
        $attrs = $prop->getAttributes(MaxiField::class);
        $attr = $attrs[0]->newInstance();
        $this->assertSame('custom_name', $attr->name);
    }

    public function testMaxiFieldConstraintsString(): void
    {
        $ref = new \ReflectionClass(AttrTestEntity::class);
        $prop = $ref->getProperty('username');
        $attrs = $prop->getAttributes(MaxiField::class);
        $attr = $attrs[0]->newInstance();
        $this->assertSame('>=2,<=100', $attr->constraints);
    }

    public function testRegistryBuildsDescriptorFromAttributes(): void
    {
        $schema = MaxiSchemaRegistry::get(AttrTestEntity::class);
        $this->assertNotNull($schema);
        $this->assertSame('AT', $schema['alias']);
        $this->assertSame('AttrTest', $schema['name']);
        $this->assertSame(['Base'], $schema['parents']);

        $fields = $schema['fields'];
        $this->assertCount(5, $fields);
    }

    public function testRegistryConstraintsFromString(): void
    {
        $schema = MaxiSchemaRegistry::get(AttrTestEntity::class);
        $usernameField = array_values(array_filter($schema['fields'], fn($f) => $f['name'] === 'username'))[0];

        $constraintTypes = array_column($usernameField['constraints'], 'type');
        // required + comparison (>=2) + comparison (<=100)
        $this->assertContains('required', $constraintTypes);
        $this->assertContains('comparison', $constraintTypes);
    }

    public function testRegistryNameOverrideUsedAsFieldName(): void
    {
        $schema = MaxiSchemaRegistry::get(AttrTestEntity::class);
        $names = array_column($schema['fields'], 'name');
        $this->assertContains('custom_name', $names);
        $this->assertNotContains('internalProp', $names);
    }

    public function testMaxiTypeAttributeTargetIsClass(): void
    {
        $ref = new \ReflectionClass(MaxiType::class);
        $attrs = $ref->getAttributes(\Attribute::class);
        $this->assertCount(1, $attrs);
        $attrMeta = $attrs[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_CLASS, $attrMeta->flags);
    }

    public function testMaxiFieldAttributeTargetIsProperty(): void
    {
        $ref = new \ReflectionClass(MaxiField::class);
        $attrs = $ref->getAttributes(\Attribute::class);
        $this->assertCount(1, $attrs);
        $attrMeta = $attrs[0]->newInstance();
        $this->assertSame(\Attribute::TARGET_PROPERTY, $attrMeta->flags);
    }
}
