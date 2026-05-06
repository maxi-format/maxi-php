<?php

declare(strict_types=1);

namespace Maxi\Tests\Unit\Api;

use Maxi\Attribute\MaxiField;
use Maxi\Attribute\MaxiType;
use PHPUnit\Framework\TestCase;

use function Maxi\Api\dumpMaxiAuto;

#[MaxiType(alias: 'P', name: 'Product')]
class AutoDumpProduct
{
    #[MaxiField(typeExpr: 'int', id: true, required: true)]
    public int $id;

    #[MaxiField(required: true)]
    public string $name;

    #[MaxiField(typeExpr: 'decimal')]
    public string $price;
}

class AutoDumpTest extends TestCase
{
    private function makeProduct(int $id, string $name, string $price): AutoDumpProduct
    {
        $p = new AutoDumpProduct();
        $p->id = $id;
        $p->name = $name;
        $p->price = $price;
        return $p;
    }

    public function testDumpAutoFromList(): void
    {
        $products = [
            $this->makeProduct(1, 'Widget', '9.99'),
            $this->makeProduct(2, 'Gadget', '19.99'),
        ];

        $dumped = dumpMaxiAuto($products);

        $this->assertStringContainsString('P(', $dumped);
        $this->assertStringContainsString('Widget', $dumped);
        $this->assertStringContainsString('Gadget', $dumped);
    }

    public function testDumpAutoFromAliasMap(): void
    {
        $dataMap = [
            'P' => [$this->makeProduct(1, 'Widget', '9.99')],
        ];

        $dumped = dumpMaxiAuto($dataMap);
        $this->assertStringContainsString('P(1|Widget|9.99)', $dumped);
    }

    public function testDumpAutoIncludesTypeDefinition(): void
    {
        $products = [$this->makeProduct(1, 'Widget', '9.99')];
        $dumped = dumpMaxiAuto($products);

        // Should include schema section with type definition
        $this->assertStringContainsString('###', $dumped);
    }

    public function testDumpAutoEmptyListThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        dumpMaxiAuto([]); // empty list, no alias determinable
    }

    public function testDumpAutoWithDefaultAlias(): void
    {
        // stdClass has no schema — use defaultAlias option
        $obj = new \stdClass();
        $obj->id = 1;
        $obj->name = 'Foo';

        $dumped = dumpMaxiAuto([$obj], ['defaultAlias' => 'X']);
        $this->assertStringContainsString('X(', $dumped);
    }
}
