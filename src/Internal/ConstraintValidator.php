<?php

declare(strict_types=1);

namespace Maxi\Internal;

use Maxi\Core\MaxiErrorCode;
use Maxi\Core\MaxiException;
use Maxi\Core\MaxiFieldDef;
use Maxi\Core\MaxiParseResult;
use Maxi\Core\MaxiSchema;
use Maxi\Core\MaxiTypeDef;

class ConstraintValidator
{
    private const ANNOTATION_TYPE_MAP = [
        'base64' => ['bytes'],
        'hex' => ['bytes'],
        'timestamp' => ['int'],
        'date' => ['str'],
        'datetime' => ['str'],
        'time' => ['str'],
        'email' => ['str'],
        'url' => ['str'],
        'uuid' => ['str'],
    ];

    private const PRIMITIVES = ['int', 'decimal', 'float', 'str', 'bool', 'bytes'];

    /**
     * Validate schema-level constraints: annotation compatibility and constraint conflicts.
     */
    public static function validateSchemaConstraints(MaxiSchema $schema, ?string $filename = null): void
    {
        foreach ($schema->types as $alias => $typeDef) {
            foreach ($typeDef->fields as $field) {
                self::validateAnnotationTypeCompat($field, $alias, $filename);
                self::validateConstraintConflicts($field, $alias, $filename);
                self::validateEnumAliases($field, $alias, $filename);
            }
        }
    }

    private static function validateAnnotationTypeCompat(MaxiFieldDef $field, string $typeAlias, ?string $filename): void
    {
        if ($field->annotation === null) {
            return;
        }

        $allowedTypes = self::ANNOTATION_TYPE_MAP[$field->annotation] ?? null;
        $baseType = self::getBaseTypeName($field->typeExpr);

        if ($allowedTypes === null) {
            if ($baseType === 'bytes') {
                throw new MaxiException(
                    "Unsupported binary format annotation '@{$field->annotation}' on bytes field '{$field->name}' in type '{$typeAlias}'. Supported: @base64, @hex",
                    MaxiErrorCode::UnsupportedBinaryFormatError,
                    null,
                    null,
                    $filename,
                );
            }
            return;
        }

        if ($baseType === null) {
            return;
        }

        if (!in_array($baseType, $allowedTypes, true)) {
            throw new MaxiException(
                "Type annotation '@{$field->annotation}' cannot be applied to '{$baseType}' field '{$field->name}' in type '{$typeAlias}'",
                MaxiErrorCode::InvalidConstraintValueError,
                null,
                null,
                $filename,
            );
        }
    }

    private static function validateConstraintConflicts(MaxiFieldDef $field, string $typeAlias, ?string $filename): void
    {
        $constraints = $field->constraints;
        if ($constraints === null || count($constraints) < 2) {
            return;
        }

        $minGe = $minGt = $maxLe = $maxLt = null;

        foreach ($constraints as $c) {
            if ($c->type !== 'comparison') {
                continue;
            }
            $v = is_array($c->value) ? ($c->value['value'] ?? null) : null;
            if ($v === null || !is_numeric($v)) {
                continue;
            }
            $v = (float)$v;
            $operator = is_array($c->value) ? ($c->value['operator'] ?? '') : '';

            switch ($operator) {
                case '>=':
                    $minGe = $minGe !== null ? max($minGe, $v) : $v;
                    break;
                case '>':
                    $minGt = $minGt !== null ? max($minGt, $v) : $v;
                    break;
                case '<=':
                    $maxLe = $maxLe !== null ? min($maxLe, $v) : $v;
                    break;
                case '<':
                    $maxLt = $maxLt !== null ? min($maxLt, $v) : $v;
                    break;
            }
        }

        $effectiveMin = $minGe ?? ($minGt !== null ? $minGt + 1 : null);
        $effectiveMax = $maxLe ?? ($maxLt !== null ? $maxLt - 1 : null);

        if ($effectiveMin !== null && $effectiveMax !== null && $effectiveMin > $effectiveMax) {
            throw new MaxiException(
                "Conflicting constraints in field '{$field->name}' of type '{$typeAlias}': lower bound exceeds upper bound",
                MaxiErrorCode::InvalidConstraintValueError,
                null,
                null,
                $filename,
            );
        }

        if ($minGe !== null && $maxLt !== null && $minGe >= $maxLt) {
            throw new MaxiException(
                "Conflicting constraints in field '{$field->name}' of type '{$typeAlias}': lower bound exceeds upper bound",
                MaxiErrorCode::InvalidConstraintValueError,
                null,
                null,
                $filename,
            );
        }

        if ($minGt !== null && $maxLe !== null && $minGt >= $maxLe) {
            throw new MaxiException(
                "Conflicting constraints in field '{$field->name}' of type '{$typeAlias}': lower bound exceeds upper bound",
                MaxiErrorCode::InvalidConstraintValueError,
                null,
                null,
                $filename,
            );
        }
    }

    /**
     * Validate runtime field constraints (comparison, pattern, exact-length) for a parsed record.
     *
     * @param mixed[] $values
     */
    public static function validateRecordConstraints(
        array           $values,
        MaxiTypeDef     $typeDef,
        bool            $isStrict,
        MaxiParseResult $result,
        int             $lineNumber,
        ?string         $filename = null,
    ): void {
        foreach ($typeDef->fields as $i => $field) {
            $constraints = $field->constraints;
            if ($constraints === null || count($constraints) === 0) {
                continue;
            }

            $value = $values[$i] ?? null;
            if ($value === null) {
                continue;
            }

            foreach ($constraints as $c) {
                $violation = self::checkConstraint($c, $value, $field);
                if ($violation !== null) {
                    if ($isStrict) {
                        throw new MaxiException(
                            $violation,
                            MaxiErrorCode::ConstraintViolationError,
                            $lineNumber,
                            null,
                            $filename,
                        );
                    }
                    $result->addWarning($violation, MaxiErrorCode::ConstraintViolationError, $lineNumber);
                }
            }
        }
    }

    private static function checkConstraint(
        \Maxi\Core\ParsedConstraint $constraint,
        mixed                       $value,
        MaxiFieldDef                $field,
    ): ?string {
        return match ($constraint->type) {
            'comparison' => self::checkComparison($constraint, $value, $field),
            'pattern' => self::checkPattern($constraint, $value, $field),
            'exact-length' => self::checkExactLength($constraint, $value, $field),
            default => null,
        };
    }

    private static function checkComparison(
        \Maxi\Core\ParsedConstraint $constraint,
        mixed                       $value,
        MaxiFieldDef                $field,
    ): ?string {
        $constraintValue = $constraint->value;
        if (!is_array($constraintValue)) {
            return null;
        }

        $operator = $constraintValue['operator'] ?? null;
        $limit = $constraintValue['value'] ?? null;

        if ($operator === null || $limit === null || !is_numeric($limit)) {
            return null;
        }

        $limit = (float)$limit;
        $baseType = self::getBaseTypeName($field->typeExpr);

        if ($baseType === 'str' || $baseType === 'bytes' || ($baseType === null && is_string($value))) {
            if (!is_string($value)) {
                return null;
            }
            $actual = strlen($value);
        } elseif (is_numeric($value)) {
            $actual = (float)$value;
        } else {
            return null;
        }

        $name = $field->name;
        return match ($operator) {
            '>=' => $actual < $limit ? "Field '{$name}': value {$actual} violates constraint >={$limit}" : null,
            '>' => $actual <= $limit ? "Field '{$name}': value {$actual} violates constraint >{$limit}" : null,
            '<=' => $actual > $limit ? "Field '{$name}': value {$actual} violates constraint <={$limit}" : null,
            '<' => $actual >= $limit ? "Field '{$name}': value {$actual} violates constraint <{$limit}" : null,
            default => null,
        };
    }

    private static function checkPattern(
        \Maxi\Core\ParsedConstraint $constraint,
        mixed                       $value,
        MaxiFieldDef                $field,
    ): ?string {
        if (!is_string($value)) {
            return null;
        }

        $pattern = (string)$constraint->value;
        $regex = '/' . str_replace('/', '\/', $pattern) . '/';

        if (!preg_match($regex, $value)) {
            return "Field '{$field->name}': value '{$value}' does not match pattern '{$pattern}'";
        }

        return null;
    }

    private static function checkExactLength(
        \Maxi\Core\ParsedConstraint $constraint,
        mixed                       $value,
        MaxiFieldDef                $field,
    ): ?string {
        $len = null;

        if (is_array($value)) {
            $len = count($value);
        } elseif (is_object($value)) {
            $len = count((array)$value);
        }

        if ($len !== null && $len !== (int)$constraint->value) {
            $expected = (int)$constraint->value;
            return "Field '{$field->name}': expected exactly {$expected} elements, got {$len}";
        }

        return null;
    }

    public static function validateEnumValue(
        string          $typeExpr,
        mixed           $value,
        string          $fieldName,
        bool            $isStrict,
        MaxiParseResult $result,
        int             $lineNumber,
        ?string         $filename = null,
    ): void {
        if ($value === null) {
            return;
        }

        $enumInfo = self::parseEnumTypeExpr($typeExpr);
        if ($enumInfo === null) {
            return;
        }

        $strValue = (string)$value;
        if (!array_key_exists($strValue, $enumInfo['aliasMap'])) {
            $joined = implode(',', array_keys($enumInfo['aliasMap']));
            $msg = "Value '{$strValue}' not in enum [{$joined}] for field '{$fieldName}'";

            if ($isStrict) {
                throw new MaxiException($msg, MaxiErrorCode::ConstraintViolationError, $lineNumber, null, $filename);
            }
            $result->addWarning($msg, MaxiErrorCode::ConstraintViolationError, $lineNumber);
        }
    }

    /**
     * Validate enum alias uniqueness rules (E501).
     * - No duplicate aliases
     * - No duplicate full values
     * - Alias must not equal another entry's full value string
     */
    private static function validateEnumAliases(MaxiFieldDef $field, string $typeAlias, ?string $filename): void
    {
        if ($field->typeExpr === null || !str_starts_with($field->typeExpr, 'enum')) {
            return;
        }
        if (!preg_match('/^enum(?:<(\w+)>)?\[([^\]]*)\]$/', $field->typeExpr, $m)) {
            return;
        }

        $tokens = array_values(array_filter(array_map('trim', explode(',', $m[2])), fn($v) => $v !== ''));
        $seenAliases = [];
        $seenFullValues = [];

        foreach ($tokens as $token) {
            if (str_contains($token, ':')) {
                $colonPos = strpos($token, ':');
                $alias = substr($token, 0, $colonPos);
                $fullStr = substr($token, $colonPos + 1);
            } else {
                $alias = $token;
                $fullStr = $token;
            }

            if (isset($seenAliases[$alias])) {
                throw new MaxiException(
                    "Duplicate enum alias '{$alias}' in field '{$field->name}' of type '{$typeAlias}'",
                    MaxiErrorCode::EnumAliasError,
                    null,
                    null,
                    $filename,
                );
            }
            $seenAliases[$alias] = true;

            if (isset($seenFullValues[$fullStr])) {
                throw new MaxiException(
                    "Duplicate enum value '{$fullStr}' in field '{$field->name}' of type '{$typeAlias}'",
                    MaxiErrorCode::EnumAliasError,
                    null,
                    null,
                    $filename,
                );
            }
            $seenFullValues[$fullStr] = true;

            if ($alias !== $fullStr && isset($seenFullValues[$alias])) {
                throw new MaxiException(
                    "Enum alias '{$alias}' conflicts with another entry's full value in field '{$field->name}' of type '{$typeAlias}'",
                    MaxiErrorCode::EnumAliasError,
                    null,
                    null,
                    $filename,
                );
            }
        }

        foreach ($tokens as $token) {
            if (str_contains($token, ':')) {
                $colonPos = strpos($token, ':');
                $alias = substr($token, 0, $colonPos);
                $fullStr = substr($token, $colonPos + 1);
            } else {
                $alias = $token;
                $fullStr = $token;
            }
            if ($alias !== $fullStr && isset($seenAliases[$fullStr])) {
                throw new MaxiException(
                    "Enum value '{$fullStr}' conflicts with another entry's alias in field '{$field->name}' of type '{$typeAlias}'",
                    MaxiErrorCode::EnumAliasError,
                    null,
                    null,
                    $filename,
                );
            }
        }
    }

    /** @return array{baseType:string,aliasMap:array<string,mixed>}|null */
    private static function parseEnumTypeExpr(string $typeExpr): ?array
    {
        $t = trim($typeExpr);
        if (!str_starts_with($t, 'enum')) {
            return null;
        }

        if (!preg_match('/^enum(?:<(\w+)>)?\[([^\]]*)\]$/', $t, $m)) {
            return null;
        }

        $baseType = $m[1] !== '' ? $m[1] : 'str';
        $tokens = array_values(array_filter(array_map('trim', explode(',', $m[2])), fn($v) => $v !== ''));

        $aliasMap = [];
        foreach ($tokens as $token) {
            if (str_contains($token, ':')) {
                $colonPos = strpos($token, ':');
                $alias = substr($token, 0, $colonPos);
                $fullStr = substr($token, $colonPos + 1);
            } else {
                $alias = $token;
                $fullStr = $token;
            }
            $fullVal = ($baseType === 'int') ? (int)$fullStr : $fullStr;
            $aliasMap[$alias] = $fullVal;
            if ($alias !== $fullStr) {
                $aliasMap[$fullStr] = $fullVal;
            }
        }

        return ['baseType' => $baseType, 'aliasMap' => $aliasMap];
    }

    public static function getBaseTypeName(?string $typeExpr): ?string
    {
        if ($typeExpr === null) {
            return 'str';
        }

        $t = trim($typeExpr);
        $noArr = rtrim($t, '[]');
        $noArr = preg_replace('/(\[\])+$/', '', $t);

        if (in_array($noArr, self::PRIMITIVES, true)) {
            return $noArr;
        }
        if ($noArr === 'map' || str_starts_with($noArr, 'map<')) {
            return 'map';
        }
        if (str_starts_with($noArr, 'enum')) {
            return 'enum';
        }

        return null;
    }
}
