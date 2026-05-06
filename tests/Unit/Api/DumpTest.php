<?php

declare(strict_types=1);

namespace Maxi\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;

use function Maxi\Api\parseMaxi;
use function Maxi\Api\dumpMaxi;

class DumpTest extends TestCase
{
    public function testRoundTripFromParseResult(): void
    {
        $input = "U:User(id:int|name)\n###\nU(1|Alice)\nU(2|Bob)";
        $result = parseMaxi($input);
        $dumped = dumpMaxi($result);
        $this->assertStringContainsString('U(1|Alice)', $dumped);
        $this->assertStringContainsString('U(2|Bob)', $dumped);
        $this->assertStringContainsString('U:User(id:int|name)', $dumped);
    }

    public function testDumpFromPlainArray(): void
    {
        $rows = [['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']];
        $dumped = dumpMaxi($rows, ['defaultAlias' => 'U']);
        $this->assertStringContainsString('U(', $dumped);
    }

    public function testDumpFromAliasMap(): void
    {
        $data = ['U' => [['id' => 1, 'name' => 'Alice']]];
        $dumped = dumpMaxi($data);
        $this->assertStringContainsString('U(1|Alice)', $dumped);
    }

    public function testDumpNullAsExplicitTilde(): void
    {
        $data = ['U' => [['id' => 1, 'name' => null]]];
        $dumped = dumpMaxi($data);
        $this->assertStringContainsString('~', $dumped);
    }

    public function testDumpBoolAs1And0(): void
    {
        $data = ['U' => [['id' => 1, 'active' => true], ['id' => 2, 'active' => false]]];
        $dumped = dumpMaxi($data);
        $this->assertStringContainsString('1', $dumped);
        $this->assertStringContainsString('0', $dumped);
    }

    public function testDumpStringWithSpecialCharsQuoted(): void
    {
        $data = ['U' => [['id' => 1, 'desc' => 'hello world']]];
        $dumped = dumpMaxi($data);
        $this->assertStringContainsString('hello world', $dumped);
        $this->assertStringNotContainsString('"hello world"', $dumped);
    }

    public function testDumpArrayValue(): void
    {
        $data = ['U' => [['id' => 1, 'tags' => ['a', 'b', 'c']]]];
        $dumped = dumpMaxi($data);
        $this->assertStringContainsString('[a,b,c]', $dumped);
    }

    public function testDumpMapValue(): void
    {
        $data = ['U' => [['id' => 1, 'meta' => ['k' => 'v']]]];
        $dumped = dumpMaxi($data);
        $this->assertStringContainsString('{k:v}', $dumped);
    }

    public function testDumpMultiline(): void
    {
        $data = ['U' => [['id' => 1, 'name' => 'Alice']]];
        $dumped = dumpMaxi($data, ['multiline' => true]);
        $this->assertStringContainsString("\n", $dumped);
    }

    public function testRoundTripNoModeDirective(): void
    {
        // @mode directive no longer exists; parsing it warns, dumping never emits it
        $input = "U:User(id:int|name)\n###\nU(1|Alice)";
        $result = parseMaxi($input);
        $dumped = dumpMaxi($result);
        $this->assertStringNotContainsString('@mode', $dumped);
    }

    public function testDumpRequiresDefaultAliasForFlatList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        dumpMaxi([['id' => 1]]);
    }

    public function testDumpInlineObjectFromParseResult(): void
    {
        $input = "A:Address(street|city)\nU:User(id:int|addr:A)\n###\nU(1|(Main St|Anytown))";
        $result = parseMaxi($input);
        $dumped = dumpMaxi($result);
        $this->assertStringContainsString('U(', $dumped);
        $this->assertStringContainsString('Main St', $dumped);
        $this->assertStringContainsString('Anytown', $dumped);
    }
}
