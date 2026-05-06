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

    // ── comparison constraints ───────────────────────────────────────────────

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

    // ── pattern constraint ───────────────────────────────────────────────────

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

    // ── exact-length constraint ──────────────────────────────────────────────

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

    // ── lax mode adds warnings instead of throwing ──────────────────────────

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

    // ── schema constraint validation ─────────────────────────────────────────

    public function testAnnotationTypeMismatchThrows(): void
    {
        $this->expectException(MaxiException::class);
        // @email can only be on str, not int
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
        // >=10 and <=5 conflict
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
}
