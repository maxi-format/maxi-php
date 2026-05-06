<?php

declare(strict_types=1);

namespace Maxi\Tests\Unit\Api;

use Maxi\Attribute\MaxiField;
use Maxi\Attribute\MaxiType;
use PHPUnit\Framework\TestCase;

use function Maxi\Api\parseMaxiAs;
use function Maxi\Api\parseMaxiAutoAs;
use function Maxi\Api\dumpMaxiAuto;

#[MaxiType(alias: 'U', name: 'User')]
class HydrateUser
{
    #[MaxiField(typeExpr: 'int', id: true, required: true)]
    public int $id;

    #[MaxiField(required: true)]
    public string $name;

    #[MaxiField(annotation: 'email')]
    public ?string $email = null;
}

class HydrateTest extends TestCase
{
    private string $basicInput = "U:User(id:int(id,!)|name(!)|email)\n###\nU(1|Alice|alice@example.com)\nU(2|Bob|~)";

    public function testParseMaxiAsHydratesInstances(): void
    {
        $result = parseMaxiAs($this->basicInput, ['U' => HydrateUser::class]);

        $users = $result->data['U'] ?? [];
        $this->assertCount(2, $users);
        $this->assertInstanceOf(HydrateUser::class, $users[0]);
        $this->assertSame(1, $users[0]->id);
        $this->assertSame('Alice', $users[0]->name);
        $this->assertSame('alice@example.com', $users[0]->email);
    }

    public function testParseMaxiAsNullField(): void
    {
        $result = parseMaxiAs($this->basicInput, ['U' => HydrateUser::class]);
        $users = $result->data['U'] ?? [];
        $this->assertNull($users[1]->email);
    }

    public function testParseMaxiAutoAs(): void
    {
        $result = parseMaxiAutoAs($this->basicInput, [HydrateUser::class]);
        $users = $result->data['U'] ?? [];
        $this->assertCount(2, $users);
        $this->assertSame('Alice', $users[0]->name);
    }

    public function testHydrateResultSchemaAndWarnings(): void
    {
        $result = parseMaxiAs($this->basicInput, ['U' => HydrateUser::class]);
        $this->assertNotNull($result->schema);
        $this->assertIsArray($result->warnings);
    }

    public function testDumpMaxiAutoFromInstances(): void
    {
        $user = new HydrateUser();
        $user->id = 1;
        $user->name = 'Alice';
        $user->email = null;

        $dumped = dumpMaxiAuto([$user]);
        $this->assertStringContainsString('U(', $dumped);
        $this->assertStringContainsString('Alice', $dumped);
    }

    public function testParseMaxiAutoAsRequiresMaxiSchema(): void
    {
        $this->expectException(\RuntimeException::class);
        parseMaxiAutoAs("U(1|test)", [\stdClass::class]);
    }

    public function testParseMaxiAsRequiresAssocMap(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        parseMaxiAs("U(1|test)", [HydrateUser::class]); // list, not map
    }
}
