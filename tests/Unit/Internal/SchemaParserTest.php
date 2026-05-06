<?php

declare(strict_types=1);

namespace Maxi\Tests\Unit\Internal;

use Maxi\Core\MaxiException;
use Maxi\Core\MaxiParseResult;
use Maxi\Internal\SchemaParser;
use PHPUnit\Framework\TestCase;

class SchemaParserTest extends TestCase
{
    private function parse(string $schema, array $options = []): MaxiParseResult
    {
        $result = new MaxiParseResult();
        (new SchemaParser($schema, $result, $options))->parse();
        return $result;
    }

    public function testBasicTypeDefinition(): void
    {
        $result = $this->parse('U:User(id:int|name|email)');
        $t = $result->schema->getType('U');
        $this->assertNotNull($t);
        $this->assertSame('User', $t->name);
        $this->assertCount(3, $t->fields);
    }

    public function testAliasOnly(): void
    {
        $result = $this->parse('U(id:int|name)');
        $t = $result->schema->getType('U');
        $this->assertNotNull($t);
        $this->assertNull($t->name);
    }

    public function testVersionDirective(): void
    {
        $result = $this->parse('@version:1.0.0');
        $this->assertSame('1.0.0', $result->schema->version);
    }

    public function testModeDirectiveIgnored(): void
    {
        // @mode directive is no longer supported; treated as unknown directive → warns
        $result = $this->parse('@mode:strict');
        $this->assertNotEmpty($result->warnings);
    }

    public function testUnknownDirectiveWarns(): void
    {
        $result = $this->parse('@unknown:foo');
        $this->assertNotEmpty($result->warnings);
    }

    public function testFieldConstraintRequired(): void
    {
        $result = $this->parse('U(name(!))');
        $t = $result->schema->getType('U');
        $this->assertTrue($t->fields[0]->isRequired());
    }

    public function testFieldConstraintId(): void
    {
        $result = $this->parse('U(id:int(id))');
        $t = $result->schema->getType('U');
        $this->assertTrue($t->fields[0]->isId());
    }

    public function testFieldAnnotation(): void
    {
        $result = $this->parse('U(email:str@email)');
        $t = $result->schema->getType('U');
        $this->assertSame('email', $t->fields[0]->annotation);
    }

    public function testFieldDefault(): void
    {
        $result = $this->parse('U(role=guest)');
        $t = $result->schema->getType('U');
        $this->assertSame('guest', $t->fields[0]->defaultValue);
    }

    public function testMultilineTypeDefinition(): void
    {
        $input = "U:User(\n  id:int|\n  name\n)";
        $result = $this->parse($input);
        $t = $result->schema->getType('U');
        $this->assertCount(2, $t->fields);
    }

    public function testDuplicateAliasThrows(): void
    {
        $this->expectException(MaxiException::class);
        $this->parse("U(id)\nU(name)");
    }

    public function testInheritanceResolved(): void
    {
        $result = $this->parse("Base(id:int)\nChild<Base>(name)");
        $child = $result->schema->getType('Child');
        $this->assertCount(2, $child->fields);
        $this->assertSame('id', $child->fields[0]->name);
    }

    public function testCircularInheritanceThrows(): void
    {
        $this->expectException(MaxiException::class);
        $this->parse("A<B>(x)\nB<A>(y)");
    }

    public function testUndefinedParentThrows(): void
    {
        $this->expectException(MaxiException::class);
        $this->parse("Child<NonExistent>(name)");
    }

    public function testDecimalPrecisionConstraint(): void
    {
        $result = $this->parse('P(price:decimal(5.2))');
        $t = $result->schema->getType('P');
        $this->assertSame('decimal-precision', $t->fields[0]->constraints[0]->type);
    }

    public function testPatternConstraint(): void
    {
        $result = $this->parse('U(slug(pattern:^[a-z]+$))');
        $t = $result->schema->getType('U');
        $this->assertSame('pattern', $t->fields[0]->constraints[0]->type);
    }

    public function testMimeConstraint(): void
    {
        $result = $this->parse('F(data:bytes(mime:[image/png,image/jpg]))');
        $t = $result->schema->getType('F');
        $this->assertSame('mime', $t->fields[0]->constraints[0]->type);
        $this->assertSame(['image/png', 'image/jpg'], $t->fields[0]->constraints[0]->value);
    }

    public function testEnumTypeExpr(): void
    {
        $result = $this->parse('U(role:enum[admin,user,guest])');
        $t = $result->schema->getType('U');
        $this->assertStringStartsWith('enum', $t->fields[0]->typeExpr);
    }
}
