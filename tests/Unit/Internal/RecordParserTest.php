<?php

declare(strict_types=1);

namespace Maxi\Tests\Unit\Internal;

use Maxi\Core\MaxiException;
use Maxi\Core\MaxiParseResult;
use Maxi\Internal\RecordParser;
use Maxi\Internal\SchemaParser;
use PHPUnit\Framework\TestCase;

class RecordParserTest extends TestCase
{
    private function parseRecords(string $schema, string $records, array $options = []): MaxiParseResult
    {
        $result = new MaxiParseResult();
        (new SchemaParser($schema, $result, $options))->parse();
        (new RecordParser($records, $result, $options))->parse();
        return $result;
    }

    public function testBasicRecord(): void
    {
        $result = $this->parseRecords('U:User(id:int|name)', 'U(1|Alice)');
        $this->assertCount(1, $result->records);
        $this->assertSame(1, $result->records[0]->values[0]);
        $this->assertSame('Alice', $result->records[0]->values[1]);
    }

    public function testMultipleRecords(): void
    {
        $result = $this->parseRecords('U(id:int|name)', "U(1|Alice)\nU(2|Bob)");
        $this->assertCount(2, $result->records);
    }

    public function testDefaultValueApplied(): void
    {
        $result = $this->parseRecords('U(id:int|role=guest)', 'U(1)');
        $this->assertSame('guest', $result->records[0]->values[1]);
    }

    public function testExplicitNullOverridesDefault(): void
    {
        $result = $this->parseRecords('U(id:int|role=guest)', 'U(1|~)');
        $this->assertNull($result->records[0]->values[1]);
    }

    public function testArrayParsed(): void
    {
        $result = $this->parseRecords('U(id:int|tags:str[])', 'U(1|[a,b,c])');
        $this->assertSame(['a', 'b', 'c'], $result->records[0]->values[1]);
    }

    public function testMapParsed(): void
    {
        $result = $this->parseRecords('U(id:int|meta:map)', 'U(1|{foo:bar})');
        $this->assertSame(['foo' => 'bar'], $result->records[0]->values[1]);
    }

    public function testInlineObjectParsed(): void
    {
        $input = $this->parseRecords(
            "A:Address(street|city)\nU:User(id:int|addr:A)",
            'U(1|(Main St|Anytown))'
        );
        $addr = $input->records[0]->values[1];
        $this->assertIsArray($addr);
        $this->assertSame('Main St', $addr['street']);
    }

    public function testQuotedStringParsed(): void
    {
        $result = $this->parseRecords('U(id:int|note)', 'U(1|"hello world")');
        $this->assertSame('hello world', $result->records[0]->values[1]);
    }

    public function testBoolTrueFalse(): void
    {
        $result = $this->parseRecords('U(id:int|flag:bool)', "U(1|true)\nU(2|false)");
        $this->assertTrue($result->records[0]->values[1]);
        $this->assertFalse($result->records[1]->values[1]);
    }

    public function testBoolOneZero(): void
    {
        $result = $this->parseRecords('U(id:int|flag:bool)', "U(1|1)\nU(2|0)");
        $this->assertTrue($result->records[0]->values[1]);
        $this->assertFalse($result->records[1]->values[1]);
    }

    public function testIntCoercion(): void
    {
        $result = $this->parseRecords('U(id:int|count:int)', 'U(1|42)');
        $this->assertSame(42, $result->records[0]->values[1]);
    }

    public function testDecimalPreservedAsString(): void
    {
        $result = $this->parseRecords('U(id:int|price:decimal)', 'U(1|9.99)');
        // Decimal should be stored as string to preserve precision
        $this->assertSame('9.99', $result->records[0]->values[1]);
    }

    public function testUnknownAliasLaxWarns(): void
    {
        $result = $this->parseRecords('', 'X(1|2)');
        $this->assertNotEmpty($result->warnings);
        $this->assertCount(1, $result->records);
    }

    public function testUnknownAliasStrictThrows(): void
    {
        $this->expectException(MaxiException::class);
        $this->parseRecords('', 'X(1|2)', ['allowUnknownTypes' => 'error']);
    }

    public function testDuplicateIdThrowsWithConstraintError(): void
    {
        $this->expectException(MaxiException::class);
        $this->parseRecords(
            'U(id:int(id,!))',
            "U(1)\nU(1)",
            ['allowConstraintViolations' => 'error'],
        );
    }

    public function testDuplicateIdWarnsWithDefaultSettings(): void
    {
        $result = $this->parseRecords(
            'U(id:int(id,!))',
            "U(1)\nU(1)",
        );
        $this->assertNotEmpty($result->warnings);
        $this->assertCount(2, $result->records);
    }

    public function testRequiredFieldMissingStrictThrows(): void
    {
        $this->expectException(MaxiException::class);
        $this->parseRecords('U(id:int(!)|name(!))', 'U(1)', ['allowMissingFields' => 'error']);
    }

    public function testUnclosedParenThrows(): void
    {
        $this->expectException(MaxiException::class);
        $this->parseRecords('', 'U(1|2');
    }

    public function testLineNumber(): void
    {
        $result = $this->parseRecords('U(id:int)', "U(1)\nU(2)\nU(3)");
        $this->assertSame(1, $result->records[0]->lineNumber);
        $this->assertSame(2, $result->records[1]->lineNumber);
        $this->assertSame(3, $result->records[2]->lineNumber);
    }
}
