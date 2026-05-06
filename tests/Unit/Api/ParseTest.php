<?php

declare(strict_types=1);

namespace Maxi\Tests\Unit\Api;

use Maxi\Core\MaxiException;
use Maxi\Core\MaxiErrorCode;
use PHPUnit\Framework\TestCase;

use function Maxi\Api\parseMaxi;

class ParseTest extends TestCase
{
    public function testSchemaOnlyNoRecords(): void
    {
        $result = parseMaxi('U:User(id:int|name|email)');
        $this->assertCount(1, $result->schema->types);
        $this->assertCount(0, $result->records);
    }

    public function testParseWithSeparator(): void
    {
        $input = "U:User(id:int|name)\n###\nU(1|Alice)";
        $result = parseMaxi($input);
        $this->assertCount(1, $result->records);
        $this->assertSame('U', $result->records[0]->alias);
        $this->assertSame(1, $result->records[0]->values[0]);
        $this->assertSame('Alice', $result->records[0]->values[1]);
    }

    public function testModeDirectiveIgnored(): void
    {
        $result = parseMaxi("@mode:strict\nU:User(id:int)\n###\nU(1)");
        $this->assertNotEmpty($result->warnings);
    }

    public function testDirectiveVersion(): void
    {
        $result = parseMaxi("@version:1.0.0\nU:User(id:int)");
        $this->assertSame('1.0.0', $result->schema->version);
    }

    public function testUnsupportedVersionThrows(): void
    {
        $this->expectException(MaxiException::class);
        $this->expectExceptionMessage('Unsupported version');
        parseMaxi("@version:2.0.0\nU:User(id:int)");
    }

    public function testDuplicateAliasThrows(): void
    {
        $this->expectException(MaxiException::class);
        $this->expectExceptionCode(0);
        parseMaxi("U:User(id:int)\nU:User(name)");
    }

    public function testFieldConstraintsParsed(): void
    {
        $input = "F:File(\n  name(!)|key:str(id)|age:int(>=0,<=120)|username(pattern:^[a-z0-9_]+\$)\n)\n###";
        $result = parseMaxi($input);
        $t = $result->schema->getType('F');
        $this->assertNotNull($t);

        $this->assertTrue($t->fields[0]->isRequired());
        $this->assertTrue($t->fields[1]->isId());

        $ageCons = $t->fields[2]->constraints;
        $this->assertCount(2, $ageCons);
        $this->assertSame('comparison', $ageCons[0]->type);
        $this->assertSame('comparison', $ageCons[1]->type);

        $this->assertSame('pattern', $t->fields[3]->constraints[0]->type);
    }

    public function testDefaultValues(): void
    {
        $input = "U:User(id:int|role=guest)\n###\nU(1)";
        $result = parseMaxi($input);
        $this->assertSame('guest', $result->records[0]->values[1]);
    }

    public function testExplicitNull(): void
    {
        $input = "U:User(id:int|name)\n###\nU(1|~)";
        $result = parseMaxi($input);
        $this->assertNull($result->records[0]->values[1]);
    }

    public function testArrayValues(): void
    {
        $input = "U:User(id:int|tags:str[])\n###\nU(1|[a,b,c])";
        $result = parseMaxi($input);
        $this->assertSame(['a', 'b', 'c'], $result->records[0]->values[1]);
    }

    public function testMapValues(): void
    {
        $input = "U:User(id:int|meta:map)\n###\nU(1|{k:v})";
        $result = parseMaxi($input);
        $this->assertSame(['k' => 'v'], $result->records[0]->values[1]);
    }

    public function testBoolCoercion(): void
    {
        $input = "U:User(id:int|active:bool)\n###\nU(1|true)\nU(2|false)\nU(3|1)\nU(4|0)";
        $result = parseMaxi($input);
        $this->assertTrue($result->records[0]->values[1]);
        $this->assertFalse($result->records[1]->values[1]);
        $this->assertTrue($result->records[2]->values[1]);
        $this->assertFalse($result->records[3]->values[1]);
    }

    public function testStrictUnknownAlias(): void
    {
        $this->expectException(MaxiException::class);
        parseMaxi("###\nX(1|2)", ['allowUnknownTypes' => 'error']);
    }

    public function testLaxUnknownAliasWarns(): void
    {
        $result = parseMaxi("###\nX(1|2)");
        $this->assertNotEmpty($result->warnings);
        $this->assertCount(1, $result->records);
    }

    public function testInheritance(): void
    {
        $input = "Base(id:int)\nChild<Base>(name)\n###\nChild(1|Bob)";
        $result = parseMaxi($input);
        $child = $result->schema->getType('Child');
        $this->assertCount(2, $child->fields);
        $this->assertSame('id', $child->fields[0]->name);
        $this->assertSame('name', $child->fields[1]->name);
    }

    public function testCircularInheritanceThrows(): void
    {
        $this->expectException(MaxiException::class);
        parseMaxi("A<B>(x)\nB<A>(y)");
    }

    public function testQuotedString(): void
    {
        $input = "U:User(id:int|name:str)\n###\nU(1|\"hello world\")";
        $result = parseMaxi($input);
        $this->assertSame('hello world', $result->records[0]->values[1]);
    }

    public function testMultipleRecords(): void
    {
        $input = "U:User(id:int|name)\n###\nU(1|Alice)\nU(2|Bob)\nU(3|Charlie)";
        $result = parseMaxi($input);
        $this->assertCount(3, $result->records);
    }

    public function testNoSeparatorRecordsOnly(): void
    {
        // No schema, no ###, just records — lax mode should accept all
        $result = parseMaxi("U(1|Alice)\nU(2|Bob)");
        $this->assertCount(2, $result->records);
    }
}
