<?php

declare(strict_types=1);

namespace Maxi\Internal;

use Maxi\Core\MaxiErrorCode;
use Maxi\Core\MaxiException;
use Maxi\Core\MaxiFieldDef;
use Maxi\Core\MaxiParseResult;
use Maxi\Core\MaxiRecord;
use Maxi\Core\MaxiTypeDef;

/**
 * Records section parser.
 */
class RecordParser
{
    private static mixed $EXPLICIT_NULL = null;
    private static bool $explicitNullInit = false;

    private readonly ?string $filename;
    private readonly string $allowConstraintViolations;
    private readonly string $allowMissingFields;
    private readonly string $allowTypeCoercion;
    private readonly string $allowUnknownTypes;
    private readonly string $allowAdditionalFields;
    private readonly bool $allowForwardReferences;

    /** @var array<string, array<string,true>> alias → set of seen id values */
    private array $seenIds = [];

    /**
     * @param array{filename?:string|null,allowConstraintViolations?:string,allowMissingFields?:string,allowTypeCoercion?:string,allowUnknownTypes?:string,allowAdditionalFields?:string,allowForwardReferences?:bool} $options
     */
    public function __construct(
        private readonly string          $recordsText,
        private readonly MaxiParseResult $result,
        private readonly array           $options = [],
    ) {
        $this->filename = $options['filename'] ?? null;
        $this->allowConstraintViolations = $options['allowConstraintViolations'] ?? 'warning';
        $this->allowMissingFields = $options['allowMissingFields'] ?? 'null';
        $this->allowTypeCoercion = $options['allowTypeCoercion'] ?? 'coerce';
        $this->allowUnknownTypes = $options['allowUnknownTypes'] ?? 'warning';
        $this->allowAdditionalFields = $options['allowAdditionalFields'] ?? 'ignore';
        $this->allowForwardReferences = $options['allowForwardReferences'] ?? true;

        if (!self::$explicitNullInit) {
            self::$EXPLICIT_NULL = new \stdClass();
            self::$explicitNullInit = true;
        }
    }

    public function parse(): void
    {
        $text = $this->recordsText;
        if ($text === '' || trim($text) === '') {
            return;
        }

        $lines = preg_split('/\r?\n/', $text);
        $lineNumber = 0;
        $totalLines = count($lines);
        $pendingAlias = null;
        $pendingLine = 0;
        $pendingBuffer = '';
        $parenDepth = 0;
        $bracketDepth = 0;
        $braceDepth = 0;
        $inString = false;
        $escapeNext = false;

        for ($li = 0; $li < $totalLines; $li++) {
            $lineNumber = $li + 1;
            $line = $lines[$li];

            if ($pendingAlias !== null) {
                $len = strlen($line);
                for ($ci = 0; $ci < $len; $ci++) {
                    $ch = $line[$ci];

                    if ($escapeNext) {
                        $escapeNext = false;
                        continue;
                    }
                    if ($inString) {
                        if ($ch === '\\') {
                            $escapeNext = true;
                        } elseif ($ch === '"') {
                            $inString = false;
                        }
                        continue;
                    }
                    if ($ch === '"') {
                        $inString = true;
                        continue;
                    }
                    if ($ch === '(') {
                        $parenDepth++;
                    } elseif ($ch === ')') {
                        $parenDepth--;
                        if ($parenDepth === 0) {
                            $pendingBuffer .= "\n" . substr($line, 0, $ci);
                            $record = $this->parseSingleRecord($pendingAlias, $pendingBuffer, $pendingLine);
                            $this->result->records[] = $record;
                            $pendingAlias = null;
                            $pendingBuffer = '';
                            break;
                        }
                    } elseif ($ch === '[') {
                        $bracketDepth++;
                    } elseif ($ch === ']') {
                        $bracketDepth = max(0, $bracketDepth - 1);
                    } elseif ($ch === '{') {
                        $braceDepth++;
                    } elseif ($ch === '}') {
                        $braceDepth = max(0, $braceDepth - 1);
                    }
                }

                if ($pendingAlias !== null) {
                    $pendingBuffer .= "\n" . $line;
                }
                continue;
            }

            $trimmed = ltrim($line);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }

            $parenPos = strpos($trimmed, '(');
            if ($parenPos !== false && $parenPos > 0) {
                $alias = rtrim(substr($trimmed, 0, $parenPos));

                if (strpos($alias, ':') !== false) {
                    $aliasPart = trim(explode(':', $alias, 2)[0]);
                    throw new MaxiException(
                        "Type definition '{$aliasPart}:...' found in data section (after ###). Type definitions must appear before ###.",
                        MaxiErrorCode::StreamError,
                        $lineNumber,
                        null,
                        $this->filename,
                    );
                }

                $lastNonSpace = rtrim($trimmed);
                if ($lastNonSpace[-1] === ')') {
                    $valuesStr = substr($lastNonSpace, $parenPos + 1, -1);
                    $record = $this->parseSingleRecord($alias, $valuesStr, $lineNumber);
                    $this->result->records[] = $record;
                    continue;
                }

                $pendingAlias = $alias;
                $pendingLine = $lineNumber;
                $pendingBuffer = substr($trimmed, $parenPos + 1);
                $parenDepth = 1;
                $bracketDepth = 0;
                $braceDepth = 0;
                $inString = false;
                $escapeNext = false;

                $rem = $pendingBuffer;
                $len = strlen($rem);
                for ($ci = 0; $ci < $len; $ci++) {
                    $ch = $rem[$ci];
                    if ($escapeNext) {
                        $escapeNext = false;
                        continue;
                    }
                    if ($inString) {
                        if ($ch === '\\') {
                            $escapeNext = true;
                        } elseif ($ch === '"') {
                            $inString = false;
                        }
                        continue;
                    }
                    if ($ch === '"') {
                        $inString = true;
                        continue;
                    }
                    if ($ch === '(') {
                        $parenDepth++;
                    } elseif ($ch === ')') {
                        $parenDepth--;
                        if ($parenDepth === 0) {
                            $pendingBuffer = substr($rem, 0, $ci);
                            $record = $this->parseSingleRecord($pendingAlias, $pendingBuffer, $pendingLine);
                            $this->result->records[] = $record;
                            $pendingAlias = null;
                            $pendingBuffer = '';
                            break;
                        }
                    } elseif ($ch === '[') {
                        $bracketDepth++;
                    } elseif ($ch === ']') {
                        $bracketDepth = max(0, $bracketDepth - 1);
                    } elseif ($ch === '{') {
                        $braceDepth++;
                    } elseif ($ch === '}') {
                        $braceDepth = max(0, $braceDepth - 1);
                    }
                }
                continue;
            }

            if (strpos($trimmed, ':') !== false && preg_match('/^([A-Za-z_][A-Za-z0-9_-]*)[ \t]*:/', $trimmed, $m)) {
                throw new MaxiException(
                    "Type definition '{$m[1]}:...' found in data section (after ###). Type definitions must appear before ###.",
                    MaxiErrorCode::StreamError,
                    $lineNumber,
                    null,
                    $this->filename,
                );
            }

            $fc = $trimmed[0];
            if (!(($fc >= 'A' && $fc <= 'Z') || ($fc >= 'a' && $fc <= 'z') || $fc === '_')) {
                throw new MaxiException(
                    "Invalid syntax in data section: unexpected character at line {$lineNumber}",
                    MaxiErrorCode::InvalidSyntaxError,
                    $lineNumber,
                    null,
                    $this->filename,
                );
            }
        }

        if ($pendingAlias !== null) {
            if ($bracketDepth !== 0) {
                throw new MaxiException(
                    "Malformed array: unmatched bracket in record '{$pendingAlias}'",
                    MaxiErrorCode::ArraySyntaxError,
                    $pendingLine,
                    null,
                    $this->filename,
                );
            }
            throw new MaxiException(
                "Unclosed record parentheses for '{$pendingAlias}'",
                MaxiErrorCode::InvalidSyntaxError,
                $pendingLine,
                null,
                $this->filename,
            );
        }
    }

    /** @var array<string, bool> Per-alias cache: true = can use fast path */
    private array $fastPathCache = [];

    public function parseSingleRecord(string $alias, string $valuesStr, int $lineNumber): MaxiRecord
    {
        $typeDef = $this->result->schema->getType($alias);

        if ($typeDef === null) {
            $msg = "Unknown type alias '{$alias}'";
            if ($this->allowUnknownTypes === 'error') {
                throw new MaxiException($msg, MaxiErrorCode::UnknownTypeError, $lineNumber, null, $this->filename);
            }
            if ($this->allowUnknownTypes !== 'ignore') {
                $this->result->addWarning($msg, MaxiErrorCode::UnknownTypeError, $lineNumber);
            }
            $values = $this->parseFieldValues($valuesStr, null, $lineNumber);
            return new MaxiRecord($alias, $values, $lineNumber);
        }

        if (!isset($this->fastPathCache[$alias])) {
            $hasNonDefaultFlags = $this->allowConstraintViolations !== 'warning'
                || $this->allowMissingFields !== 'null'
                || $this->allowTypeCoercion !== 'coerce'
                || $this->allowUnknownTypes !== 'warning'
                || $this->allowAdditionalFields !== 'ignore'
                || !$this->allowForwardReferences;

            $canFast = true
                && !$hasNonDefaultFlags
                && !$typeDef->hasRuntimeConstraints()
                && $typeDef->getIdFieldIndex() >= 0;
            if ($canFast) {
                $hasTypeField = false;
                foreach ($typeDef->fields as $f) {
                    if ($f->name === 'type') {
                        $hasTypeField = true;
                        break;
                    }
                }
                $canFast = !$hasTypeField;
            }
            $this->fastPathCache[$alias] = $canFast;
        }

        if ($this->fastPathCache[$alias] && strpbrk($valuesStr, '"()[]{}') === false) {
            return $this->parseSingleRecordFast($alias, $valuesStr, $lineNumber, $typeDef);
        }

        $typeDef->getRequiredFlags();

        $values = $this->parseFieldValues($valuesStr, $typeDef, $lineNumber);

        // 'type' field auto-inference
        {
            $typeFieldIndex = -1;
            foreach ($typeDef->fields as $idx => $f) {
                if ($f->name === 'type') {
                    $typeFieldIndex = $idx;
                    break;
                }
            }
            if ($typeFieldIndex !== -1 && count($values) === count($typeDef->fields) - 1) {
                $typeFieldDef = $typeDef->fields[$typeFieldIndex];
                $inferred = null;
                if ($typeFieldDef->defaultValue !== MaxiFieldDef::missing()
                    && $typeFieldDef->defaultValue !== null) {
                    $inferred = $typeFieldDef->defaultValue;
                } elseif ($typeDef->name !== null) {
                    $inferred = strtolower($typeDef->name);
                } else {
                    $inferred = strtolower($typeDef->alias);
                }
                array_splice($values, $typeFieldIndex, 0, [$inferred]);
            }
        }

        if ($this->allowAdditionalFields === 'error') {
            $fieldCount = count($typeDef->fields);
            $valueCount = count($values);
            if ($valueCount > $fieldCount) {
                throw new MaxiException(
                    "Record '{$alias}' has {$valueCount} values but type defines {$fieldCount} fields",
                    MaxiErrorCode::SchemaMismatchError,
                    $lineNumber,
                    null,
                    $this->filename,
                );
            }
        }

        $fieldCount = count($typeDef->fields);
        $requiredFlags = $typeDef->getRequiredFlags() ?? [];
        $finalValues = [];
        $explicitNull = self::$EXPLICIT_NULL;
        $missing = MaxiFieldDef::missing();
        $valueCount = count($values);

        for ($idx = 0; $idx < $fieldCount; $idx++) {
            $field = $typeDef->fields[$idx];
            $value = $idx < $valueCount ? $values[$idx] : null;

            if ($value === $explicitNull) {
                if (($requiredFlags[$idx] ?? false)) {
                    $msg = "Field '{$field->name}' is required; explicit null (~) is not allowed";
                    if ($this->allowMissingFields === 'error') {
                        throw new MaxiException($msg, MaxiErrorCode::MissingRequiredFieldError, $lineNumber, null, $this->filename);
                    }
                    if ($this->allowMissingFields !== 'null') {
                        $this->result->addWarning($msg, MaxiErrorCode::MissingRequiredFieldError, $lineNumber);
                    }
                }
                $value = null;
            } elseif ($value === null || $value === '') {
                if ($field->defaultValue !== $missing) {
                    $value = $field->defaultValue;
                } else {
                    $value = null;
                }
            }

            if (($requiredFlags[$idx] ?? false) && $value === null) {
                $msg = "Required field '{$field->name}' is null in record '{$alias}'";
                if ($this->allowMissingFields === 'error') {
                    throw new MaxiException($msg, MaxiErrorCode::MissingRequiredFieldError, $lineNumber, null, $this->filename);
                }
                if ($this->allowMissingFields !== 'null') {
                    $this->result->addWarning($msg, MaxiErrorCode::MissingRequiredFieldError, $lineNumber);
                }
            }

            $finalValues[$idx] = $value;
        }

        $enumCache = $typeDef->getEnumValuesCache() ?? [];
        foreach ($enumCache as $idx => $enumVals) {
            if ($enumVals === null) {
                continue;
            }
            $value = $finalValues[$idx] ?? null;
            if ($value !== null) {
                $strValue = is_string($value) ? $value : (string)$value;
                if (!isset($enumVals[$strValue])) {
                    $fieldName = $typeDef->fields[$idx]->name;
                    $enumList = implode(',', array_keys($enumVals));
                    $msg = "Value '{$strValue}' not in enum [{$enumList}] for field '{$fieldName}'";
                    if ($this->allowConstraintViolations === 'error') {
                        throw new MaxiException($msg, MaxiErrorCode::ConstraintViolationError, $lineNumber, null, $this->filename);
                    }
                    $this->result->addWarning($msg, MaxiErrorCode::ConstraintViolationError, $lineNumber);
                }
            }
        }

        if ($typeDef->hasRuntimeConstraints()) {
            ConstraintValidator::validateRecordConstraints(
                $finalValues,
                $typeDef,
                $this->allowConstraintViolations === 'error',
                $this->result,
                $lineNumber,
                $this->filename,
            );
        }

        $idIdx = $typeDef->getIdFieldIndex();

        if ($idIdx >= 0 && $idIdx < count($finalValues)) {
            $idValue = $finalValues[$idIdx];
            if ($idValue !== null) {
                $idKey = (string)$idValue;
                if (isset($this->seenIds[$alias][$idKey])) {
                    $msg = "Duplicate identifier '{$idValue}' for type '{$alias}'";
                    if ($this->allowConstraintViolations === 'error') {
                        throw new MaxiException($msg, MaxiErrorCode::DuplicateIdentifierError, $lineNumber, null, $this->filename);
                    }
                    $this->result->addWarning($msg, MaxiErrorCode::DuplicateIdentifierError, $lineNumber);
                }
                $this->seenIds[$alias][$idKey] = true;
            }
        }

        return new MaxiRecord($alias, $finalValues, $lineNumber);
    }

    private function parseSingleRecordFast(string $alias, string $valuesStr, int $lineNumber, MaxiTypeDef $typeDef): MaxiRecord
    {
        $parts = explode('|', $valuesStr);
        $fields = $typeDef->fields;
        $fieldCount = count($fields);
        $values = [];
        $missing = MaxiFieldDef::missing();

        foreach ($parts as $fi => $part) {
            $part = trim($part);
            if ($part === '') {
                $values[] = ($fi < $fieldCount && $fields[$fi]->defaultValue !== $missing)
                    ? $fields[$fi]->defaultValue
                    : null;
                continue;
            }
            if ($part === '~') {
                $values[] = null;
                continue;
            }
            if ($fi >= $fieldCount) {
                $values[] = $part;
                continue;
            }
            $baseType = $fields[$fi]->baseType ?? ($fields[$fi]->typeExpr ?? 'str');
            switch ($baseType) {
                case 'int':
                    if (ctype_digit($part) || ($part[0] === '-' && ctype_digit(substr($part, 1)))) {
                        $values[] = (int)$part;
                    } elseif (preg_match('/^-?\d+\.?\d*$/', $part)) {
                        $this->result->addWarning(
                            "Type coercion: value '{$part}' coerced to int, fractional part lost",
                            MaxiErrorCode::TypeMismatchError,
                            $lineNumber,
                        );
                        $values[] = (int)$part;
                    } else {
                        $this->result->addWarning(
                            "Type mismatch: field expects int, got '{$part}'",
                            MaxiErrorCode::TypeMismatchError,
                            $lineNumber,
                        );
                        $values[] = $part;
                    }
                    break;
                case 'bool':
                    $values[] = ($part === 'true' || $part === '1');
                    break;
                case 'float':
                    $values[] = (float)$part;
                    break;
                case 'decimal':
                    $values[] = ($part[-1] === '.') ? rtrim($part, '.') : $part;
                    break;
                default:
                    $values[] = $part;
                    break;
            }
        }

        for ($i = count($values); $i < $fieldCount; $i++) {
            $values[] = ($fields[$i]->defaultValue !== $missing) ? $fields[$i]->defaultValue : null;
        }

        $idIdx = $typeDef->getIdFieldIndex();
        if ($idIdx >= 0 && $idIdx < count($values)) {
            $idValue = $values[$idIdx];
            if ($idValue !== null) {
                $idKey = (string)$idValue;
                if (isset($this->seenIds[$alias][$idKey])) {
                    $msg = "Duplicate identifier '{$idValue}' for type '{$alias}'";
                    if ($this->allowConstraintViolations === 'error') {
                        throw new MaxiException($msg, MaxiErrorCode::DuplicateIdentifierError, $lineNumber, null, $this->filename);
                    }
                    $this->result->addWarning($msg, MaxiErrorCode::DuplicateIdentifierError, $lineNumber);
                }
                $this->seenIds[$alias][$idKey] = true;
            }
        }

        $enumCache = $typeDef->getEnumValuesCache() ?? [];
        foreach ($enumCache as $idx => $enumVals) {
            if ($enumVals === null) continue;
            $value = $values[$idx] ?? null;
            if ($value !== null) {
                $strValue = is_string($value) ? $value : (string)$value;
                if (!isset($enumVals[$strValue])) {
                    $fieldName = $fields[$idx]->name;
                    $enumList = implode(',', array_keys($enumVals));
                    $this->result->addWarning(
                        "Value '{$strValue}' not in enum [{$enumList}] for field '{$fieldName}'",
                        MaxiErrorCode::ConstraintViolationError,
                        $lineNumber,
                    );
                }
            }
        }

        return new MaxiRecord($alias, $values, $lineNumber);
    }

    /** @return mixed[] */
    private function parseFieldValues(string $valuesStr, ?MaxiTypeDef $typeDef, int $lineNumber): array
    {
        $isSimple = strpbrk($valuesStr, '"()[]{}') === false;

        $fields = $typeDef?->fields ?? [];
        $values = [];

        if ($isSimple) {
            $parts = explode('|', $valuesStr);
            foreach ($parts as $fi => $part) {
                $values[] = $this->parseFieldValue(trim($part), $fields[$fi] ?? null, $lineNumber);
            }
            return $values;
        }

        $valueStrings = $this->splitTopLevel($valuesStr, '|');
        foreach ($valueStrings as $vi => $vs) {
            $vs = trim($vs);
            $fieldDef = $fields[$vi] ?? null;
            $values[] = $this->parseFieldValue($vs, $fieldDef, $lineNumber);
        }
        return $values;
    }

    private function parseFieldValue(string $valueStr, ?MaxiFieldDef $fieldDef, int $lineNumber): mixed
    {
        if ($valueStr === '') {
            return $fieldDef?->defaultValue !== MaxiFieldDef::missing()
                ? $fieldDef->defaultValue
                : null;
        }

        if ($valueStr === '~') {
            return self::$EXPLICIT_NULL;
        }

        $c0 = $valueStr[0];
        $cLast = $valueStr[-1];

        if ($c0 === '[') {
            if ($cLast !== ']') {
                throw new MaxiException(
                    'Malformed array: unmatched opening bracket',
                    MaxiErrorCode::ArraySyntaxError,
                    $lineNumber,
                    null,
                    $this->filename,
                );
            }
            return $this->parseArray($valueStr, $fieldDef, $lineNumber);
        }

        if ($c0 === '{' && $cLast === '}') {
            return $this->parseMap($valueStr, $fieldDef, $lineNumber);
        }

        if ($c0 === '(' && $cLast === ')') {
            return $this->parseInlineObject($valueStr, $fieldDef, $lineNumber);
        }

        if ($c0 === '"' && $cLast === '"') {
            return $this->parseQuotedString($valueStr);
        }

        $typeExpr = $fieldDef?->typeExpr ?? 'str';
        $baseType = $fieldDef?->baseType ?? $typeExpr;

        if ($baseType === 'int') {
            if (ctype_digit($valueStr) || ($valueStr[0] === '-' && ctype_digit(substr($valueStr, 1)))) {
                return (int)$valueStr;
            }
            if ($this->allowTypeCoercion === 'error') {
                throw new MaxiException(
                    "Type mismatch: field expects int, got '{$valueStr}'",
                    MaxiErrorCode::TypeMismatchError,
                    $lineNumber,
                    null,
                    $this->filename,
                );
            }
            if (preg_match('/^-?\d+\.?\d*$/', $valueStr)) {
                if ($this->allowTypeCoercion === 'warning') {
                    $this->result->addWarning(
                        "Type coercion: value '{$valueStr}' coerced to int, fractional part lost",
                        MaxiErrorCode::TypeMismatchError,
                        $lineNumber,
                    );
                }
                return (int)$valueStr;
            }
            $this->result->addWarning(
                "Type mismatch: field expects int, got '{$valueStr}'",
                MaxiErrorCode::TypeMismatchError,
                $lineNumber,
            );
            return $valueStr;
        }

        if ($baseType === 'bool') {
            if ($valueStr === '1' || $valueStr === 'true') {
                return true;
            }
            if ($valueStr === '0' || $valueStr === 'false') {
                return false;
            }
            if ($this->allowTypeCoercion === 'error') {
                throw new MaxiException(
                    "Type mismatch: field expects bool, got '{$valueStr}'",
                    MaxiErrorCode::TypeMismatchError,
                    $lineNumber,
                    null,
                    $this->filename,
                );
            }
            $this->result->addWarning(
                "Type coercion: value '{$valueStr}' is not a valid bool",
                MaxiErrorCode::TypeMismatchError,
                $lineNumber,
            );
            return $valueStr;
        }

        if ($fieldDef?->typeExpr !== null && $baseType === 'str') {
            return $valueStr;
        }

        if (str_starts_with($typeExpr, 'enum')) {
            if (!preg_match('/^enum<(\w+)>/', $typeExpr, $em) || $em[1] === 'str') {
                return $valueStr;
            }
        }

        $annotation = $fieldDef?->annotation;
        if ($baseType === 'bytes' && $annotation === 'base64') {
            if ($this->looksLikeBase64($valueStr)) {
                $mod = strlen($valueStr) & 3;
                if ($mod !== 0) {
                    return $valueStr . ($mod === 1 ? '===' : ($mod === 2 ? '==' : '='));
                }
            }
            return $valueStr;
        }

        if ($baseType === 'float') {
            if (preg_match('/^-?\d+\.?\d*(?:[eE][+-]?\d+)?$/', $valueStr)) {
                return (float)$valueStr;
            }
            if ($this->allowTypeCoercion === 'error') {
                throw new MaxiException(
                    "Type mismatch: field expects float, got '{$valueStr}'",
                    MaxiErrorCode::TypeMismatchError,
                    $lineNumber,
                    null,
                    $this->filename,
                );
            }
            $this->result->addWarning(
                "Type coercion: value '{$valueStr}' is not a valid float",
                MaxiErrorCode::TypeMismatchError,
                $lineNumber,
            );
            return $valueStr;
        }

        if ($baseType === 'decimal') {
            if (preg_match('/^-?\d+\.$/', $valueStr)) {
                return rtrim($valueStr, '.');
            }
            if (preg_match('/^-?\d+(\.\d+)?$/', $valueStr)) {
                return $valueStr;
            }
            if ($this->allowTypeCoercion === 'error') {
                throw new MaxiException(
                    "Type mismatch: field expects decimal, got '{$valueStr}'",
                    MaxiErrorCode::TypeMismatchError,
                    $lineNumber,
                    null,
                    $this->filename,
                );
            }
            $this->result->addWarning(
                "Type coercion: value '{$valueStr}' is not a valid decimal",
                MaxiErrorCode::TypeMismatchError,
                $lineNumber,
            );
            return $valueStr;
        }

        if (true) { // auto number coercion
            $fc = $valueStr[0];
            if ($fc >= '0' && $fc <= '9' || $fc === '-') {
                if (ctype_digit($valueStr) || ($fc === '-' && isset($valueStr[1]) && ctype_digit(substr($valueStr, 1)))) {
                    return (int)$valueStr;
                }
                if (preg_match('/^-?\d+\.\d+$/', $valueStr)) {
                    return (float)$valueStr;
                }
                if (preg_match('/^-?\d+\.$/', $valueStr)) {
                    return (int)$valueStr;
                }
                if (preg_match('/^-?\d+\.?\d*[eE][+-]?\d+$/', $valueStr)) {
                    return (float)$valueStr;
                }
            }
        }

        return $valueStr;
    }

    /** @return mixed[] */
    private function parseArray(string $arrayStr, ?MaxiFieldDef $fieldDef, int $lineNumber): array
    {
        $content = trim(substr($arrayStr, 1, -1));
        if ($content === '') {
            return [];
        }

        $elemType = $this->getArrayElementType($fieldDef?->typeExpr);
        $elemFieldDef = $elemType !== null
            ? new MaxiFieldDef(name: '_elem', typeExpr: $elemType)
            : null;

        if (strpbrk($content, '"()[]{}') === false) {
            $parts = explode(',', $content);
            $elements = [];
            foreach ($parts as $part) {
                $elements[] = $this->parseFieldValue(trim($part), $elemFieldDef, $lineNumber);
            }
            return $elements;
        }

        $elements = [];
        $currentElement = '';
        $depth = 0;
        $inString = false;
        $escapeNext = false;
        $len = strlen($content);

        for ($i = 0; $i < $len; $i++) {
            $char = $content[$i];

            if ($escapeNext) {
                $currentElement .= $char;
                $escapeNext = false;
                continue;
            }
            if ($char === '\\' && $inString) {
                $currentElement .= $char;
                $escapeNext = true;
                continue;
            }
            if ($char === '"') {
                $inString = !$inString;
                $currentElement .= $char;
                continue;
            }

            if (!$inString) {
                if ($char === '(' || $char === '[' || $char === '{') {
                    $depth++;
                } elseif ($char === ')' || $char === ']' || $char === '}') {
                    $depth--;
                }
                if ($char === ',' && $depth === 0) {
                    $elements[] = $this->parseFieldValue(trim($currentElement), $elemFieldDef, $lineNumber);
                    $currentElement = '';
                    continue;
                }
            }

            $currentElement .= $char;
        }

        if (trim($currentElement) !== '') {
            $elements[] = $this->parseFieldValue(trim($currentElement), $elemFieldDef, $lineNumber);
        }

        return $elements;
    }

    /** @return array<string, mixed> */
    private function parseMap(string $mapStr, ?MaxiFieldDef $fieldDef, int $lineNumber): array
    {
        $content = trim(substr($mapStr, 1, -1));
        if ($content === '') {
            return [];
        }

        $mapValueType = $this->getMapValueType($fieldDef?->typeExpr);
        $hasExplicitMapType = $fieldDef?->typeExpr !== null;
        $valueFieldDef = $mapValueType !== null
            ? new MaxiFieldDef(name: '_val', typeExpr: $mapValueType)
            : ($hasExplicitMapType ? new MaxiFieldDef(name: '_val', typeExpr: 'str') : null);

        $mapKeyType = $this->getMapKeyType($fieldDef?->typeExpr);
        $keyFieldDef = $mapKeyType !== null
            ? new MaxiFieldDef(name: '_key', typeExpr: $mapKeyType)
            : null;

        $map = [];
        $currentEntry = '';
        $depth = 0;
        $inString = false;
        $escapeNext = false;
        $len = strlen($content);

        for ($i = 0; $i < $len; $i++) {
            $char = $content[$i];

            if ($escapeNext) {
                $currentEntry .= $char;
                $escapeNext = false;
                continue;
            }
            if ($char === '\\' && $inString) {
                $currentEntry .= $char;
                $escapeNext = true;
                continue;
            }
            if ($char === '"') {
                $inString = !$inString;
                $currentEntry .= $char;
                continue;
            }

            if (!$inString) {
                if ($char === '(' || $char === '[' || $char === '{') {
                    $depth++;
                } elseif ($char === ')' || $char === ']' || $char === '}') {
                    $depth--;
                }
                if ($char === ',' && $depth === 0) {
                    $this->parseMapEntry(trim($currentEntry), $map, $lineNumber, $valueFieldDef, $keyFieldDef);
                    $currentEntry = '';
                    continue;
                }
            }

            $currentEntry .= $char;
        }

        if (trim($currentEntry) !== '') {
            $this->parseMapEntry(trim($currentEntry), $map, $lineNumber, $valueFieldDef, $keyFieldDef);
        }

        return $map;
    }

    private function parseMapEntry(
        string        $entryStr,
        array         &$map,
        int           $lineNumber,
        ?MaxiFieldDef $valueFieldDef,
        ?MaxiFieldDef $keyFieldDef,
    ): void {
        $colonIndex = -1;
        $depth = 0;
        $inString = false;
        $escapeNext = false;
        $len = strlen($entryStr);

        for ($i = 0; $i < $len; $i++) {
            $ch = $entryStr[$i];

            if ($escapeNext) {
                $escapeNext = false;
                continue;
            }
            if ($inString) {
                if ($ch === '\\') {
                    $escapeNext = true;
                    continue;
                }
                if ($ch === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inString = true;
                continue;
            }

            if ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']' || $ch === '}') {
                $depth = max(0, $depth - 1);
            }

            if ($ch === ':' && $depth === 0) {
                $colonIndex = $i;
                break;
            }
        }

        if ($colonIndex === -1) {
            throw new MaxiException(
                "Invalid map entry format: {$entryStr}",
                MaxiErrorCode::InvalidSyntaxError,
                $lineNumber,
                null,
                $this->filename,
            );
        }

        $keyStr = trim(substr($entryStr, 0, $colonIndex));
        $valueStr = trim(substr($entryStr, $colonIndex + 1));

        $strKeyFieldDef = $keyFieldDef ?? new MaxiFieldDef(name: '_key', typeExpr: 'str');
        $key = $this->parseFieldValue($keyStr, $strKeyFieldDef, $lineNumber);

        if ($keyFieldDef !== null) {
            $this->validateInlineTypeConstraints($key, $keyFieldDef->typeExpr, 'map key', $lineNumber);
        }

        $value = $this->parseFieldValue($valueStr, $valueFieldDef, $lineNumber);

        if ($valueFieldDef !== null) {
            $this->validateInlineTypeConstraints($value, $valueFieldDef->typeExpr, 'map value', $lineNumber);
        }

        $map[(string)$key] = $value;
    }

    private function validateInlineTypeConstraints(
        mixed   $value,
        ?string $typeExpr,
        string  $fieldName,
        int     $lineNumber,
    ): void {
        if ($typeExpr === null) {
            return;
        }
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\((.+)\)\s*$/', $typeExpr, $m)) {
            return;
        }

        $parts = array_filter(array_map('trim', explode(',', $m[1])));
        foreach ($parts as $part) {
            if (!preg_match('/^(>=|>|<=|<)\s*(.+)$/', $part, $cmp)) {
                continue;
            }
            $operator = $cmp[1];
            $limit = (float)$cmp[2];

            $actual = is_string($value) ? strlen($value) : (is_numeric($value) ? (float)$value : null);
            if ($actual === null) {
                continue;
            }

            $violated = match ($operator) {
                '>=' => $actual < $limit,
                '>' => $actual <= $limit,
                '<=' => $actual > $limit,
                '<' => $actual >= $limit,
                default => false,
            };

            if ($violated) {
                $msg = "{$fieldName}: value {$actual} violates constraint {$operator}{$limit}";
                if ($this->allowConstraintViolations === 'error') {
                    throw new MaxiException($msg, MaxiErrorCode::ConstraintViolationError, $lineNumber, null, $this->filename);
                }
                $this->result->addWarning($msg, MaxiErrorCode::ConstraintViolationError, $lineNumber);
            }
        }
    }

    private function parseInlineObject(string $objStr, ?MaxiFieldDef $fieldDef, int $lineNumber): mixed
    {
        $innerValuesStr = substr($objStr, 1, -1);
        $typeAlias = $this->getInlineObjectTypeAlias($fieldDef?->typeExpr);

        if ($typeAlias === null) {
            return ['values' => $this->parseFieldValues($innerValuesStr, null, $lineNumber)];
        }

        $typeDef = $this->result->schema->getType($typeAlias);

        if ($typeDef === null) {
            $resolvedAlias = ($this->result->schema->nameToAlias ?? [])[$typeAlias] ?? null;
            $typeDef = $resolvedAlias ? $this->result->schema->getType($resolvedAlias) : null;
        }

        if ($typeDef === null) {
            if ($this->allowUnknownTypes === 'error') {
                throw new MaxiException(
                    "Unknown type alias '{$typeAlias}' for inline object",
                    MaxiErrorCode::UnknownTypeError,
                    $lineNumber,
                    null,
                    $this->filename,
                );
            }
            $this->result->addWarning(
                "Unknown type alias '{$typeAlias}' for inline object",
                MaxiErrorCode::UnknownTypeError,
                $lineNumber,
            );
            return ['values' => $this->parseFieldValues($innerValuesStr, null, $lineNumber)];
        }

        $values = $this->parseFieldValues($innerValuesStr, $typeDef, $lineNumber);
        $obj = [];

        foreach ($typeDef->fields as $idx => $field) {
            $v = $values[$idx] ?? null;
            if ($v === self::$EXPLICIT_NULL) {
                $v = null;
            } elseif ($v === null || $v === '') {
                $v = $field->defaultValue !== MaxiFieldDef::missing() ? $field->defaultValue : null;
            }
            $obj[$field->name] = $v;
        }

        return $obj;
    }

    private function getArrayElementType(?string $typeExpr): ?string
    {
        if ($typeExpr === null) {
            return null;
        }
        $t = trim($typeExpr);
        if (!preg_match('/^(.+)\[\]\s*$/', $t, $m)) {
            return null;
        }
        return trim($m[1]) ?: null;
    }

    private function getMapValueType(?string $typeExpr): ?string
    {
        if ($typeExpr === null) {
            return null;
        }
        $t = trim($typeExpr);
        if ($t === 'map') {
            return null;
        }
        if (!preg_match('/^map\s*<\s*(.+)\s*>\s*$/', $t, $m)) {
            return null;
        }
        return $this->lastMapTypePart($m[1]);
    }

    private function getMapKeyType(?string $typeExpr): ?string
    {
        if ($typeExpr === null) {
            return null;
        }
        $t = trim($typeExpr);
        if ($t === 'map') {
            return null;
        }
        if (!preg_match('/^map\s*<\s*(.+)\s*>\s*$/', $t, $m)) {
            return null;
        }
        $parts = $this->splitMapTypeParams($m[1]);
        return count($parts) >= 2 ? ($parts[0] ?: null) : null;
    }

    /** @return string[] */
    private function splitMapTypeParams(string $inside): array
    {
        $depth = 0;
        $inString = false;
        $cur = '';
        $parts = [];
        $len = strlen($inside);

        for ($i = 0; $i < $len; $i++) {
            $ch = $inside[$i];

            if ($inString) {
                if ($ch === '"' && ($i === 0 || $inside[$i - 1] !== '\\')) {
                    $inString = false;
                }
                $cur .= $ch;
                continue;
            }
            if ($ch === '"') {
                $inString = true;
                $cur .= $ch;
                continue;
            }

            if ($ch === '<') {
                $depth++;
            } elseif ($ch === '>') {
                $depth = max(0, $depth - 1);
            }

            if ($ch === ',' && $depth === 0) {
                $parts[] = trim($cur);
                $cur = '';
                continue;
            }

            $cur .= $ch;
        }

        if (trim($cur) !== '') {
            $parts[] = trim($cur);
        }

        return $parts;
    }

    private function lastMapTypePart(string $inside): ?string
    {
        $parts = $this->splitMapTypeParams($inside);
        if (count($parts) === 0) {
            return null;
        }
        return end($parts) ?: null;
    }

    private function getInlineObjectTypeAlias(?string $typeExpr): ?string
    {
        if ($typeExpr === null) {
            return null;
        }
        $t = trim($typeExpr);

        if (preg_match('/^(.+)\[\]\s*$/', $t, $m)) {
            $t = trim($m[1]);
        }

        if (preg_match('/^map\s*</', $t)) {
            $mapValueType = $this->getMapValueType($t);
            $resolved = $mapValueType ? trim($mapValueType) : null;
            return $resolved !== null ? $this->resolveTypeAlias($resolved) : null;
        }

        $primitives = ['str', 'int', 'decimal', 'bool', 'bytes', 'map', 'float'];
        if (in_array($t, $primitives, true)) {
            return null;
        }

        return $this->resolveTypeAlias($t);
    }

    private function resolveTypeAlias(string $maybeAliasOrName): ?string
    {
        if ($this->result->schema->hasType($maybeAliasOrName)) {
            return $maybeAliasOrName;
        }
        return ($this->result->schema->nameToAlias ?? [])[$maybeAliasOrName] ?? null;
    }

    private function parseQuotedString(string $str): string
    {
        $inner = substr($str, 1, -1);
        return str_replace(
            ['\\n', '\\r', '\\t', '\\"', '\\\\'],
            ["\n", "\r", "\t", '"', '\\'],
            $inner
        );
    }

    /** @return string[] */
    private function splitTopLevel(string $str, string $delimiter): array
    {
        $parts = [];
        $partStart = 0;
        $parenDepth = 0;
        $bracketDepth = 0;
        $braceDepth = 0;
        $inString = false;
        $escapeNext = false;
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            $char = $str[$i];

            if ($escapeNext) {
                $escapeNext = false;
                continue;
            }
            if ($inString) {
                if ($char === '\\') {
                    $escapeNext = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($char === '(') {
                $parenDepth++;
            } elseif ($char === ')') {
                $parenDepth--;
            } elseif ($char === '[') {
                $bracketDepth++;
            } elseif ($char === ']') {
                $bracketDepth--;
            } elseif ($char === '{') {
                $braceDepth++;
            } elseif ($char === '}') {
                $braceDepth--;
            }

            if ($char === $delimiter && $parenDepth === 0 && $bracketDepth === 0 && $braceDepth === 0) {
                $parts[] = substr($str, $partStart, $i - $partStart);
                $partStart = $i + 1;
            }
        }

        $parts[] = substr($str, $partStart);
        return $parts;
    }

    private function looksLikeBase64(string $s): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $s);
    }
}
