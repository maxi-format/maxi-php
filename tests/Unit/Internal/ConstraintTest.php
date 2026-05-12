<?php

declare(strict_types=1);

namespace Maxi\Tests\Unit\Internal;

use Maxi\Core\MaxiException;
use Maxi\Core\MaxiFieldDef;
use Maxi\Core\MaxiParseResult;
use Maxi\Core\MaxiTypeDef;
use Maxi\Core\ParsedConstraint;
use Maxi\Internal\ConstraintValidator;
use PHPUnit\Framework\TestCase;

class ConstraintTest extends TestCase
{
    private function makeField(string $name, ?string $typeExpr = null, array $constraints = []): MaxiFieldDef
    {
        return new MaxiFieldDef(
            name: $name,
            typeExpr: $typeExpr,
            annotation: null,
            constraints: $constraints ?: null,
            elementConstraints: null,
            defaultValue: MaxiFieldDef::missing(),
        );
    }

    private function makeParsedResult(): MaxiParseResult
    {
        return new MaxiParseResult();
    }

    private function makeTypeDef(MaxiFieldDef ...$fields): MaxiTypeDef
    {
        $td = new MaxiTypeDef('T', null, []);
        foreach ($fields as $f) {
            $td->addField($f);
        }
        return $td;
    }

    public function testComparisonGtePass(): void
    {
        $field = $this->makeField('age', 'int', [
            new ParsedConstraint('comparison', ['operator' => '>=', 'value' => 18]),
        ]);
        $td = $this->makeTypeDef($field);
        $result = $this->makeParsedResult();

        ConstraintValidator::validateRecordConstraints([18], $td, true, $result, 1);
        $this->assertEmpty($result->warnings);
    }

    public function testComparisonGteFail(): void
    {
        $this->expectException(MaxiException::class);
        $field = $this->makeField('age', 'int', [
            new ParsedConstraint('comparison', ['operator' => '>=', 'value' => 18]),
        ]);
        $td = $this->makeTypeDef($field);
        ConstraintValidator::validateRecordConstraints([17], $td, true, $this->makeParsedResult(), 1);
    }

    public function testComparisonLtePass(): void
    {
        $field = $this->makeField('score', 'int', [
            new ParsedConstraint('comparison', ['operator' => '<=', 'value' => 100]),
        ]);
        $td = $this->makeTypeDef($field);
        $result = $this->makeParsedResult();
        ConstraintValidator::validateRecordConstraints([100], $td, true, $result, 1);
        $this->assertEmpty($result->warnings);
    }

    public function testComparisonLteFail(): void
    {
        $this->expectException(MaxiException::class);
        $field = $this->makeField('score', 'int', [
            new ParsedConstraint('comparison', ['operator' => '<=', 'value' => 100]),
        ]);
        $td = $this->makeTypeDef($field);
        ConstraintValidator::validateRecordConstraints([101], $td, true, $this->makeParsedResult(), 1);
    }

    public function testComparisonStringLength(): void
    {
        $this->expectException(MaxiException::class);
        $field = $this->makeField('name', 'str', [
            new ParsedConstraint('comparison', ['operator' => '>=', 'value' => 3]),
        ]);
        $td = $this->makeTypeDef($field);
        ConstraintValidator::validateRecordConstraints(['ab'], $td, true, $this->makeParsedResult(), 1);
    }

    public function testPatternPass(): void
    {
        $field = $this->makeField('slug', 'str', [
            new ParsedConstraint('pattern', '^[a-z]+$'),
        ]);
        $td = $this->makeTypeDef($field);
        $result = $this->makeParsedResult();
        ConstraintValidator::validateRecordConstraints(['abc'], $td, true, $result, 1);
        $this->assertEmpty($result->warnings);
    }

    public function testPatternFail(): void
    {
        $this->expectException(MaxiException::class);
        $field = $this->makeField('slug', 'str', [
            new ParsedConstraint('pattern', '^[a-z]+$'),
        ]);
        $td = $this->makeTypeDef($field);
        ConstraintValidator::validateRecordConstraints(['ABC123'], $td, true, $this->makeParsedResult(), 1);
    }

    public function testExactLengthArrayPass(): void
    {
        $field = $this->makeField('tags', 'str[]', [
            new ParsedConstraint('exact-length', 3),
        ]);
        $td = $this->makeTypeDef($field);
        $result = $this->makeParsedResult();
        ConstraintValidator::validateRecordConstraints([['a', 'b', 'c']], $td, true, $result, 1);
        $this->assertEmpty($result->warnings);
    }

    public function testExactLengthArrayFail(): void
    {
        $this->expectException(MaxiException::class);
        $field = $this->makeField('tags', 'str[]', [
            new ParsedConstraint('exact-length', 3),
        ]);
        $td = $this->makeTypeDef($field);
        ConstraintValidator::validateRecordConstraints([['a', 'b']], $td, true, $this->makeParsedResult(), 1);
    }

    public function testConstraintViolationLaxAddsWarning(): void
    {
        $field = $this->makeField('age', 'int', [
            new ParsedConstraint('comparison', ['operator' => '>=', 'value' => 18]),
        ]);
        $td = $this->makeTypeDef($field);
        $result = $this->makeParsedResult();
        ConstraintValidator::validateRecordConstraints([5], $td, false, $result, 1);
        $this->assertNotEmpty($result->warnings);
    }

    public function testAnnotationTypeMismatchThrows(): void
    {
        $this->expectException(MaxiException::class);
        $field = new MaxiFieldDef('email', 'int', 'email', null, null, MaxiFieldDef::missing());
        $schema = new \Maxi\Core\MaxiSchema();
        $td = new MaxiTypeDef('U', null, []);
        $td->addField($field);
        $schema->addType($td);

        ConstraintValidator::validateSchemaConstraints($schema);
    }

    public function testConflictingConstraintsThrows(): void
    {
        $this->expectException(MaxiException::class);
        $field = $this->makeField('n', 'int', [
            new ParsedConstraint('comparison', ['operator' => '>=', 'value' => 10]),
            new ParsedConstraint('comparison', ['operator' => '<=', 'value' => 5]),
        ]);
        $schema = new \Maxi\Core\MaxiSchema();
        $td = new MaxiTypeDef('T', null, []);
        $td->addField($field);
        $schema->addType($td);

        ConstraintValidator::validateSchemaConstraints($schema);
    }

    public function testEnumAliasExpansionOnParse(): void
    {
        $input = "U:User(id:int|role:enum[a:admin,e:editor,v:viewer])\n###\nU(1|a)";
        $result = \Maxi\Api\parseMaxi($input);
        $this->assertEmpty($result->warnings);
        $record = $result->records[0];
        $this->assertSame('admin', $record->values[1]);
    }

    public function testEnumMixedModeAliasExpansion(): void
    {
        $input = "U:User(id:int|role:enum[a:admin,viewer])\n###\nU(1|a)\nU(2|viewer)";
        $result = \Maxi\Api\parseMaxi($input);
        $this->assertEmpty($result->warnings);
        $this->assertSame('admin', $result->records[0]->values[1]);
        $this->assertSame('viewer', $result->records[1]->values[1]);
    }

    public function testEnumIntAliasParsedAsInt(): void
    {
        $input = "U:User(id:int|state:enum<int>[O:900,I:910,R:1000,E:999])\n###\nU(1|O)";
        $result = \Maxi\Api\parseMaxi($input);
        $this->assertEmpty($result->warnings);
        $this->assertSame(900, $result->records[0]->values[1]);
    }

    public function testEnumNoAliasBackwardCompat(): void
    {
        $input = "U:User(id:int|role:enum[admin,editor,viewer])\n###\nU(1|admin)";
        $result = \Maxi\Api\parseMaxi($input);
        $this->assertEmpty($result->warnings);
        $this->assertSame('admin', $result->records[0]->values[1]);
    }

    public function testEnumDuplicateAliasThrowsE021(): void
    {
        $this->expectException(MaxiException::class);
        $this->expectExceptionCode(0);
        $field = $this->makeField('role', 'enum[a:admin,a:editor]');
        $schema = new \Maxi\Core\MaxiSchema();
        $td = new MaxiTypeDef('T', null, []);
        $td->addField($field);
        $schema->addType($td);
        ConstraintValidator::validateSchemaConstraints($schema);
    }

    public function testEnumDuplicateFullValueThrowsE021(): void
    {
        $this->expectException(MaxiException::class);
        $field = $this->makeField('role', 'enum[a:admin,b:admin]');
        $schema = new \Maxi\Core\MaxiSchema();
        $td = new MaxiTypeDef('T', null, []);
        $td->addField($field);
        $schema->addType($td);
        ConstraintValidator::validateSchemaConstraints($schema);
    }

    public function testEnumAliasEqualsAnotherFullValueThrowsE021(): void
    {
        $this->expectException(MaxiException::class);
        $field = $this->makeField('role', 'enum[a:editor,editor:viewer]');
        $schema = new \Maxi\Core\MaxiSchema();
        $td = new MaxiTypeDef('T', null, []);
        $td->addField($field);
        $schema->addType($td);
        ConstraintValidator::validateSchemaConstraints($schema);
    }

    public function testEnumUnknownWireTokenE008Strict(): void
    {
        $this->expectException(MaxiException::class);
        $input = "U:User(id:int|role:enum[a:admin,e:editor])\n###\nU(1|x)";
        \Maxi\Api\parseMaxi($input, ['allowConstraintViolations' => 'error']);
    }
}
