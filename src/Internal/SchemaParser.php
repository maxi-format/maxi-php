<?php

declare(strict_types=1);

namespace Maxi\Internal;

use Maxi\Core\MaxiErrorCode;
use Maxi\Core\MaxiException;
use Maxi\Core\MaxiFieldDef;
use Maxi\Core\MaxiParseResult;
use Maxi\Core\MaxiTypeDef;
use Maxi\Core\ParsedConstraint;

/**
 * Schema phase parser – handles directives, type definitions, imports and inheritance.
 */
class SchemaParser
{
    private const PRIMITIVE_TYPES = ['str', 'int', 'decimal', 'float', 'bool', 'bytes', 'map'];

    /** @var array<string, true> Paths currently being loaded (circular-import guard). */
    private array $loadingStack = [];

    /** @var array<string, true> Aliases defined in *this* file (not imported). */
    private array $localAliases = [];

    /** Set to true for recursively-loaded imported schemas. */
    private bool $isImported = false;

    /**
     * @param array{
     *   filename?: string|null,
     *   loadSchema?: callable|null,
     * } $options
     */
    public function __construct(
        private readonly string          $schemaText,
        private readonly MaxiParseResult $result,
        private readonly array           $options = [],
    ) {
    }

    public function parse(): void
    {
        if (trim($this->schemaText) === '') {
            return;
        }

        $lines = preg_split('/\r?\n/', $this->schemaText);
        $lineNumber = 1;
        $total = count($lines);

        for ($i = 0; $i < $total; $i++, $lineNumber++) {
            $line = trim($lines[$i]);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, '@')) {
                $this->parseDirective($line, $lineNumber);
                continue;
            }

            $result = $this->parseTypeDefinition($lines, $i, $lineNumber);
            if ($result !== null) {
                $i = $result['nextIndex'];
                $lineNumber = $result['nextLine'];
            }
        }

        $this->resolveInheritance();
        $this->validateSchemaConstraints();
        ConstraintValidator::validateSchemaConstraints($this->result->schema, $this->options['filename'] ?? null);
        $this->validateDefaultValues();
        $this->buildNameIndex();

        if (!$this->isImported) {
            $this->validateFieldTypeReferences();
        }
    }

    private function buildNameIndex(): void
    {
        $nameToAlias = [];
        foreach ($this->result->schema->types as $alias => $td) {
            if ($td->name !== null && !isset($nameToAlias[$td->name])) {
                $nameToAlias[$td->name] = $alias;
            }
        }
        $this->result->schema->nameToAlias = $nameToAlias;
    }

    private function parseDirective(string $line, int $lineNumber): void
    {
        if (!preg_match('/^@([a-zA-Z_][a-zA-Z0-9_-]*):(.+)$/', $line, $m)) {
            throw new MaxiException(
                "Invalid directive syntax: {$line}",
                MaxiErrorCode::InvalidSyntaxError,
                $lineNumber,
                null,
                $this->options['filename'] ?? null,
            );
        }

        $name = $m[1];
        $value = trim($m[2]);

        switch ($name) {
            case 'version':
                $this->parseVersionDirective($value, $lineNumber);
                break;


            case 'schema':
                $this->parseSchemaDirective($value, $lineNumber);
                break;

            default:
                $this->result->addWarning(
                    "Unknown directive '@{$name}' ignored",
                    MaxiErrorCode::UnknownDirectiveError,
                    $lineNumber,
                );
        }
    }

    private function parseVersionDirective(string $value, int $lineNumber): void
    {
        if (!preg_match('/^\d+\.\d+\.\d+$/', $value)) {
            throw new MaxiException(
                "Invalid version format: {$value}",
                MaxiErrorCode::InvalidSyntaxError,
                $lineNumber,
                null,
                $this->options['filename'] ?? null,
            );
        }

        if ($value !== '1.0.0') {
            throw new MaxiException(
                "Unsupported version: {$value}. Parser supports v1.0.0",
                MaxiErrorCode::UnsupportedVersionError,
                $lineNumber,
                null,
                $this->options['filename'] ?? null,
            );
        }

        $this->result->schema->version = $value;
    }

    private function parseSchemaDirective(string $pathOrUrl, int $lineNumber): void
    {
        if (isset($this->loadingStack[$pathOrUrl])) {
            return;
        }

        $this->result->schema->imports[] = $pathOrUrl;
        $this->loadExternalSchema($pathOrUrl, $lineNumber);
    }

    /**
     * @param string[] $lines
     * @return array{nextIndex:int,nextLine:int}|null
     */
    private function parseTypeDefinition(array $lines, int $startIndex, int $startLine): ?array
    {
        $firstLine = $lines[$startIndex];
        $trimmed = trim($firstLine);

        if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '@')) {
            return null;
        }

        $looksLikeAliasParen = (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_-]*\s*\(/', $trimmed);
        $looksLikeExplicitType = (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_-]*\s*:\s*[A-Za-z_][A-Za-z0-9_-]*\s*(<[^>]+>)?\s*\(/', $trimmed);
        $looksLikeInheritance = (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_-]*\s*<[^>]+>\s*\(/', $trimmed);

        if (!$looksLikeExplicitType && !$looksLikeInheritance && $looksLikeAliasParen) {
            $afterParen = ltrim(substr($trimmed, strpos($trimmed, '(') + 1));
            if (preg_match('/^(\d|-\d|~)/', $afterParen)) {
                return null;
            }
        } elseif (!$looksLikeExplicitType && !$looksLikeInheritance) {
            return null;
        }

        $fullDef = '';
        $i = $startIndex;
        $lineNum = $startLine;
        $inString = false;
        $escapeNext = false;
        $sawOpen = false;
        $parenDepth = 0;
        $total = count($lines);

        for (; $i < $total; $i++, $lineNum++) {
            $currentLine = $lines[$i];
            $fullDef .= $currentLine . "\n";

            $len = strlen($currentLine);
            for ($k = 0; $k < $len; $k++) {
                $ch = $currentLine[$k];

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

                if ($ch === '(') {
                    $sawOpen = true;
                    $parenDepth++;
                    continue;
                }

                if ($ch === ')') {
                    if (!$sawOpen) {
                        throw new MaxiException(
                            'Unmatched closing parenthesis in type definition',
                            MaxiErrorCode::InvalidSyntaxError,
                            $lineNum,
                            null,
                            $this->options['filename'] ?? null,
                        );
                    }
                    $parenDepth--;
                    if ($parenDepth < 0) {
                        throw new MaxiException(
                            'Unmatched closing parenthesis in type definition',
                            MaxiErrorCode::InvalidSyntaxError,
                            $lineNum,
                            null,
                            $this->options['filename'] ?? null,
                        );
                    }
                    if ($parenDepth === 0) {
                        break;
                    }
                }
            }

            if ($sawOpen && $parenDepth === 0) {
                break;
            }
        }

        if (!$sawOpen) {
            return null;
        }

        if ($parenDepth !== 0) {
            throw new MaxiException(
                'Unclosed parenthesis in type definition (possible malformed constraint)',
                MaxiErrorCode::ConstraintSyntaxError,
                $startLine,
                null,
                $this->options['filename'] ?? null,
            );
        }

        $this->parseCompleteTypeDefinition($fullDef, $startLine);

        return ['nextIndex' => $i, 'nextLine' => $lineNum];
    }

    private function parseCompleteTypeDefinition(string $def, int $lineNumber): void
    {
        $trimmed = trim($def);
        $openIdx = strpos($trimmed, '(');

        if ($openIdx === false) {
            throw new MaxiException(
                "Invalid type definition syntax: {$trimmed}",
                MaxiErrorCode::InvalidSyntaxError,
                $lineNumber,
                null,
                $this->options['filename'] ?? null,
            );
        }

        $closeIdx = $this->findMatchingParen($trimmed, $openIdx);
        if ($closeIdx === -1) {
            throw new MaxiException(
                "Invalid type definition syntax: {$trimmed}",
                MaxiErrorCode::InvalidSyntaxError,
                $lineNumber,
                null,
                $this->options['filename'] ?? null,
            );
        }

        $tail = trim(substr($trimmed, $closeIdx + 1));
        if ($tail !== '') {
            throw new MaxiException(
                "Invalid type definition syntax: {$trimmed}",
                MaxiErrorCode::InvalidSyntaxError,
                $lineNumber,
                null,
                $this->options['filename'] ?? null,
            );
        }

        $header = trim(substr($trimmed, 0, $openIdx));
        $fieldsStr = trim(substr($trimmed, $openIdx + 1, $closeIdx - $openIdx - 1));

        if (!preg_match(
            '/^([A-Za-z_][A-Za-z0-9_-]*)(?::([A-Za-z_][A-Za-z0-9_-]*))?(?:<\s*([^>]+?)\s*>)?\s*$/',
            $header,
            $hm
        )) {
            throw new MaxiException(
                "Invalid type definition header: {$header}",
                MaxiErrorCode::InvalidSyntaxError,
                $lineNumber,
                null,
                $this->options['filename'] ?? null,
            );
        }

        $alias = $hm[1];
        $typeName = ($hm[2] ?? '') !== '' ? $hm[2] : null;
        $parentsStr = $hm[3] ?? '';

        if ($typeName !== null && !preg_match('/^[a-zA-Z]/', $typeName)) {
            throw new MaxiException(
                "Invalid type name '{$typeName}': type names must start with a letter [a-zA-Z] per §3.3.2",
                MaxiErrorCode::UnknownTypeError,
                $lineNumber,
                null,
                $this->options['filename'] ?? null,
            );
        }

        if (isset($this->localAliases[$alias])) {
            throw new MaxiException(
                "Duplicate type alias '{$alias}'",
                MaxiErrorCode::DuplicateTypeError,
                $lineNumber,
                null,
                $this->options['filename'] ?? null,
            );
        }
        $this->localAliases[$alias] = true;

        $parents = [];
        if ($parentsStr !== '') {
            foreach (explode(',', $parentsStr) as $p) {
                $p = trim($p);
                if ($p !== '') {
                    $parents[] = $p;
                }
            }
        }

        $typeDef = new MaxiTypeDef($alias, $typeName, $parents);

        if ($fieldsStr !== '') {
            foreach ($this->parseFieldList($fieldsStr, $lineNumber) as $field) {
                $typeDef->addField($field);
            }
        }

        $this->result->schema->addType($typeDef);
    }

    /** @return MaxiFieldDef[] */
    private function parseFieldList(string $fieldsStr, int $lineNumber): array
    {
        $normalized = preg_replace(['/\r?\n/', '/\t/', '/\s+/'], [' ', ' ', ' '], $fieldsStr);
        $normalized = trim($normalized);

        $fields = [];
        foreach ($this->splitTopLevel($normalized, '|') as $fieldStr) {
            $fieldStr = trim($fieldStr);
            if ($fieldStr !== '') {
                $fields[] = $this->parseField($fieldStr, $lineNumber);
            }
        }
        return $fields;
    }

    private function parseField(string $fieldStr, int $lineNumber): MaxiFieldDef
    {
        $remaining = trim($fieldStr);
        $constraints = [];
        $elementConstraints = [];
        $defaultValue = MaxiFieldDef::missing();

        $colonIdx = $this->findTopLevelChar($remaining, ':');
        $namePart = $remaining;
        $restPart = '';

        if ($colonIdx !== -1) {
            $namePart = trim(substr($remaining, 0, $colonIdx));
            $restPart = trim(substr($remaining, $colonIdx + 1));
        }

        if ($restPart !== '') {
            $trailing = $this->extractTrailingGroup($restPart, '(', ')');
            if ($trailing !== null) {
                $constraints = $this->parseConstraints($trailing['inner'], $lineNumber);
                $restPart = trim($trailing['before']);

                if (preg_match('/\[\]\s*$/', $restPart)) {
                    $withoutBrackets = trim(preg_replace('/\[\]\s*$/', '', $restPart));
                    $innerTrailing = $this->extractTrailingGroup($withoutBrackets, '(', ')');
                    if ($innerTrailing !== null) {
                        $elementConstraints = $this->parseConstraints($innerTrailing['inner'], $lineNumber);
                        $restPart = trim($innerTrailing['before']) . '[]';
                    }
                }
            }
        }

        if ($constraints === []) {
            $trailing = $this->extractTrailingGroup($namePart, '(', ')');
            if ($trailing !== null) {
                $constraints = $this->parseConstraints($trailing['inner'], $lineNumber);
                $namePart = trim($trailing['before']);
            }

            $eqIdxName = $this->findTopLevelChar($namePart, '=');
            if ($eqIdxName !== -1) {
                $defaultValue = trim(substr($namePart, $eqIdxName + 1));
                $namePart = trim(substr($namePart, 0, $eqIdxName));
            }

            if ($constraints === []) {
                $trailing2 = $this->extractTrailingGroup($namePart, '(', ')');
                if ($trailing2 !== null) {
                    $constraints = $this->parseConstraints($trailing2['inner'], $lineNumber);
                    $namePart = trim($trailing2['before']);
                }
            }
        }

        if ($defaultValue === MaxiFieldDef::missing()) {
            $eqIdxName = $this->findTopLevelChar($namePart, '=');
            if ($eqIdxName !== -1) {
                $defaultValue = trim(substr($namePart, $eqIdxName + 1));
                $namePart = trim(substr($namePart, 0, $eqIdxName));

                if ($constraints === []) {
                    $trailing = $this->extractTrailingGroup($namePart, '(', ')');
                    if ($trailing !== null) {
                        $constraints = $this->parseConstraints($trailing['inner'], $lineNumber);
                        $namePart = trim($trailing['before']);
                    }
                }
            } elseif ($restPart !== '') {
                $eqIdxRest = $this->findTopLevelChar($restPart, '=');
                if ($eqIdxRest !== -1) {
                    $defaultValue = trim(substr($restPart, $eqIdxRest + 1));
                    $restPart = trim(substr($restPart, 0, $eqIdxRest));
                }
            }
        }

        if (is_string($defaultValue) && $defaultValue !== '' && $defaultValue !== MaxiFieldDef::missing()) {
            if (str_starts_with($defaultValue, '"') && str_ends_with($defaultValue, '"')) {
                $defaultValue = $this->unescapeString(substr($defaultValue, 1, -1));
            }
        }

        $typeExpr = null;
        $annotation = null;

        if ($restPart !== '') {
            $atIdx = $this->findTopLevelChar($restPart, '@');
            if ($atIdx !== -1) {
                $typeExpr = trim(substr($restPart, 0, $atIdx)) ?: null;
                $annotation = trim(substr($restPart, $atIdx + 1)) ?: null;
            } else {
                $typeExpr = trim($restPart) ?: null;
            }
        }

        return new MaxiFieldDef(
            name: $namePart,
            typeExpr: $typeExpr,
            annotation: $annotation,
            constraints: $constraints !== [] ? $constraints : null,
            elementConstraints: $elementConstraints !== [] ? $elementConstraints : null,
            defaultValue: $defaultValue,
        );
    }

    /** @return ParsedConstraint[] */
    private function parseConstraints(string $constraintStr, int $lineNumber): array
    {
        $constraints = [];

        foreach ($this->splitConstraintParts($constraintStr) as $part) {
            $trimmed = trim($part);
            if ($trimmed === '') {
                continue;
            }

            if ($trimmed === '!') {
                $constraints[] = new ParsedConstraint('required');
                continue;
            }

            if ($trimmed === 'id') {
                $constraints[] = new ParsedConstraint('id');
                continue;
            }

            if (preg_match('/^=(\d+)$/', $trimmed, $m)) {
                $constraints[] = new ParsedConstraint('exact-length', (int)$m[1]);
                continue;
            }

            if (preg_match('/^(>=|>|<=|<|=)\s*(.+)$/', $trimmed, $m)) {
                $op = $m[1];
                $valStr = trim($m[2]);
                $numValue = is_numeric($valStr) ? $valStr + 0 : $valStr;
                $constraints[] = new ParsedConstraint('comparison', ['operator' => $op, 'value' => $numValue]);
                continue;
            }

            if (str_starts_with($trimmed, 'pattern:')) {
                $pattern = trim(substr($trimmed, strlen('pattern:')));
                if (@preg_match('/' . str_replace('/', '\/', $pattern) . '/', '') === false) {
                    throw new MaxiException(
                        "Invalid regex pattern: {$pattern}",
                        MaxiErrorCode::ConstraintSyntaxError,
                        $lineNumber,
                        null,
                        $this->options['filename'] ?? null,
                    );
                }
                $constraints[] = new ParsedConstraint('pattern', $pattern);
                continue;
            }

            if (str_starts_with($trimmed, 'mime:')) {
                $mimeSpec = trim(substr($trimmed, strlen('mime:')));
                $mimeTypes = $this->parseMimeSpec($mimeSpec, $lineNumber);
                $constraints[] = new ParsedConstraint('mime', $mimeTypes);
                continue;
            }

            if (preg_match('/^(\d+:)?(\d+)?\.(\d+(?::\d+)?)?$/', $trimmed)) {
                $constraints[] = $this->parseDecimalPrecision($trimmed);
                continue;
            }

            throw new MaxiException(
                "Unknown constraint: {$trimmed}",
                MaxiErrorCode::ConstraintSyntaxError,
                $lineNumber,
                null,
                $this->options['filename'] ?? null,
            );
        }

        return $constraints;
    }

    /** @return string[] */
    private function splitConstraintParts(string $str): array
    {
        return $this->splitTopLevel($str, ',');
    }

    /** @return string[] */
    private function parseMimeSpec(string $mimeSpec, int $lineNumber): array
    {
        $s = trim($mimeSpec);
        if ($s === '') {
            return [];
        }

        if (!str_starts_with($s, '[')) {
            $single = (str_starts_with($s, '"') && str_ends_with($s, '"'))
                ? $this->unescapeString(substr($s, 1, -1))
                : $s;
            return array_filter([trim($single)], fn($x) => $x !== '');
        }

        if (!str_ends_with($s, ']')) {
            throw new MaxiException(
                "Invalid mime constraint value: {$mimeSpec}",
                MaxiErrorCode::ConstraintSyntaxError,
                $lineNumber,
                null,
                $this->options['filename'] ?? null,
            );
        }

        $content = trim(substr($s, 1, -1));
        if ($content === '') {
            return [];
        }

        $items = [];
        $cur = '';
        $inString = false;
        $escapeNext = false;
        $len = strlen($content);

        for ($i = 0; $i < $len; $i++) {
            $ch = $content[$i];

            if ($escapeNext) {
                $cur .= $ch;
                $escapeNext = false;
                continue;
            }
            if ($inString) {
                if ($ch === '\\') {
                    $cur .= $ch;
                    $escapeNext = true;
                    continue;
                }
                if ($ch === '"') {
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
            if ($ch === ',') {
                $item = trim($cur);
                if ($item !== '') {
                    $items[] = $item;
                }
                $cur = '';
                continue;
            }
            $cur .= $ch;
        }
        if (trim($cur) !== '') {
            $items[] = trim($cur);
        }

        return array_values(array_filter(
            array_map(function (string $t): string {
                $t = trim($t);
                if (str_starts_with($t, '"') && str_ends_with($t, '"')) {
                    return trim($this->unescapeString(substr($t, 1, -1)));
                }
                return $t;
            }, $items),
            fn($x) => $x !== '',
        ));
    }

    private function parseDecimalPrecision(string $raw): ParsedConstraint
    {
        $dotIdx = strpos($raw, '.');
        $intPart = substr($raw, 0, $dotIdx);
        $fracPart = substr($raw, $dotIdx + 1);

        $intMin = $intMax = $fracMin = $fracMax = null;

        if ($intPart !== '') {
            if (str_contains($intPart, ':')) {
                [$a, $b] = explode(':', $intPart, 2);
                $intMin = $a !== '' ? (int)$a : null;
                $intMax = $b !== '' ? (int)$b : null;
            } else {
                $intMax = (int)$intPart;
            }
        }

        if ($fracPart !== '') {
            if (str_contains($fracPart, ':')) {
                [$a, $b] = explode(':', $fracPart, 2);
                $fracMin = $a !== '' ? (int)$a : null;
                $fracMax = $b !== '' ? (int)$b : null;
            } else {
                $fracMax = (int)$fracPart;
            }
        }

        return new ParsedConstraint('decimal-precision', [
            'raw' => $raw,
            'intMin' => $intMin,
            'intMax' => $intMax,
            'fracMin' => $fracMin,
            'fracMax' => $fracMax,
        ]);
    }

    private function resolveInheritance(): void
    {
        $visited = [];
        $visiting = [];

        $resolve = function (string $alias) use (&$resolve, &$visited, &$visiting): void {
            if (isset($visited[$alias])) {
                return;
            }
            if (isset($visiting[$alias])) {
                throw new MaxiException(
                    "Circular inheritance detected involving type '{$alias}'",
                    MaxiErrorCode::CircularInheritanceError,
                );
            }

            $typeDef = $this->result->schema->getType($alias);
            if ($typeDef === null || $typeDef->inheritanceResolved) {
                return;
            }

            $visiting[$alias] = true;

            $inheritedFields = [];
            foreach ($typeDef->parents as $parentAlias) {
                $parentType = $this->result->schema->getType($parentAlias);
                if ($parentType === null) {
                    throw new MaxiException(
                        "Type '{$alias}' inherits from '{$parentAlias}', but '{$parentAlias}' is not defined",
                        MaxiErrorCode::UndefinedParentError,
                    );
                }

                $resolve($parentAlias);

                foreach ($parentType->fields as $field) {
                    $found = false;
                    foreach ($inheritedFields as $f) {
                        if ($f->name === $field->name) {
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $inheritedFields[] = $field;
                    }
                }
            }

            $finalFields = $inheritedFields;
            foreach ($typeDef->fields as $ownField) {
                $existingIdx = -1;
                foreach ($finalFields as $idx => $f) {
                    if ($f->name === $ownField->name) {
                        $existingIdx = $idx;
                        break;
                    }
                }
                if ($existingIdx >= 0) {
                    $finalFields[$existingIdx] = $ownField;
                } else {
                    $finalFields[] = $ownField;
                }
            }

            $typeDef->fields = array_values($finalFields);
            $typeDef->inheritanceResolved = true;
            $typeDef->invalidateCache();

            unset($visiting[$alias]);
            $visited[$alias] = true;
        };

        foreach (array_keys($this->result->schema->types) as $alias) {
            $resolve($alias);
        }
    }

    /**
     * Validate annotation/type compatibility and constraint conflicts.
     * Full implementation lives in ConstraintValidator (Step 5).
     * This stub covers the most common cases used during schema parsing.
     */
    private function validateSchemaConstraints(): void
    {
        $annotationTypeMap = [
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

        foreach ($this->result->schema->types as $alias => $typeDef) {
            foreach ($typeDef->fields as $field) {
                if ($field->annotation === null) {
                    continue;
                }

                $allowedTypes = $annotationTypeMap[$field->annotation] ?? null;
                $baseType = $this->getBaseTypeName($field->typeExpr);

                if ($allowedTypes === null) {
                    if ($baseType === 'bytes') {
                        throw new MaxiException(
                            "Unsupported binary format annotation '@{$field->annotation}' on bytes field '{$field->name}' in type '{$alias}'. Supported: @base64, @hex",
                            MaxiErrorCode::UnsupportedBinaryFormatError,
                            null,
                            null,
                            $this->options['filename'] ?? null,
                        );
                    }
                    continue;
                }

                if ($baseType !== null && !in_array($baseType, $allowedTypes, true)) {
                    throw new MaxiException(
                        "Type annotation '@{$field->annotation}' cannot be applied to '{$baseType}' field '{$field->name}' in type '{$alias}'",
                        MaxiErrorCode::InvalidConstraintValueError,
                        null,
                        null,
                        $this->options['filename'] ?? null,
                    );
                }
            }
        }
    }

    private function validateDefaultValues(): void
    {
        foreach ($this->result->schema->types as $alias => $typeDef) {
            foreach ($typeDef->fields as $field) {
                if ($field->defaultValue === MaxiFieldDef::missing()) {
                    continue;
                }

                $defVal = (string)$field->defaultValue;
                $typeExpr = $field->typeExpr;
                if ($typeExpr === null) {
                    continue;
                }

                if ($typeExpr === 'int') {
                    if (!preg_match('/^-?\d+$/', $defVal)) {
                        throw new MaxiException(
                            "Invalid default value '{$field->defaultValue}' for field '{$field->name}' of type 'int' in '{$alias}'",
                            MaxiErrorCode::InvalidDefaultValueError,
                            null,
                            null,
                            $this->options['filename'] ?? null,
                        );
                    }
                } elseif ($typeExpr === 'float' || $typeExpr === 'decimal') {
                    if (!is_numeric($defVal)) {
                        throw new MaxiException(
                            "Invalid default value '{$field->defaultValue}' for field '{$field->name}' of type '{$typeExpr}' in '{$alias}'",
                            MaxiErrorCode::InvalidDefaultValueError,
                            null,
                            null,
                            $this->options['filename'] ?? null,
                        );
                    }
                } elseif ($typeExpr === 'bool') {
                    if (!in_array($defVal, ['true', 'false', '1', '0'], true)) {
                        throw new MaxiException(
                            "Invalid default value '{$field->defaultValue}' for field '{$field->name}' of type 'bool' in '{$alias}'",
                            MaxiErrorCode::InvalidDefaultValueError,
                            null,
                            null,
                            $this->options['filename'] ?? null,
                        );
                    }
                }
            }
        }
    }

    private function validateFieldTypeReferences(): void
    {
        foreach ($this->result->schema->types as $alias => $typeDef) {
            foreach ($typeDef->fields as $field) {
                $refType = $this->extractReferencedType($field->typeExpr);
                if ($refType === null) {
                    continue;
                }

                $known = $this->result->schema->hasType($refType)
                    || isset(($this->result->schema->nameToAlias ?? [])[$refType]);

                if (!$known) {
                    throw new MaxiException(
                        "Field '{$field->name}' in type '{$alias}' references unknown type '{$refType}'",
                        MaxiErrorCode::UnknownTypeError,
                        null,
                        null,
                        $this->options['filename'] ?? null,
                    );
                }
            }
        }
    }

    private function loadExternalSchema(string $pathOrUrl, int $lineNumber): void
    {
        if (!isset($this->options['loadSchema'])) {
            throw new MaxiException(
                "Cannot load schema '{$pathOrUrl}': no loadSchema function provided",
                MaxiErrorCode::SchemaLoadError,
                $lineNumber,
                null,
                $this->options['filename'] ?? null,
            );
        }

        $this->loadingStack[$pathOrUrl] = true;

        try {
            $schemaContent = ($this->options['loadSchema'])($pathOrUrl);

            $childOptions = $this->options;
            $childOptions['filename'] = $pathOrUrl;

            $externalParser = new self($schemaContent, $this->result, $childOptions);
            $externalParser->isImported = true;
            $externalParser->loadingStack = $this->loadingStack;

            $externalParser->parse();
        } catch (MaxiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new MaxiException(
                "Failed to load schema '{$pathOrUrl}': {$e->getMessage()}",
                MaxiErrorCode::SchemaLoadError,
                $lineNumber,
                null,
                $this->options['filename'] ?? null,
                $e,
            );
        } finally {
            unset($this->loadingStack[$pathOrUrl]);
        }
    }

    /**
     * Find character position of $ch at the top level
     * (not inside parens, brackets, braces or strings).
     */
    private function findTopLevelChar(string $s, string $ch): int
    {
        $inString = false;
        $escapeNext = false;
        $paren = $bracket = $brace = 0;
        $len = strlen($s);

        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];

            if ($escapeNext) {
                $escapeNext = false;
                continue;
            }
            if ($inString) {
                if ($c === '\\') {
                    $escapeNext = true;
                    continue;
                }
                if ($c === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($c === '"') {
                $inString = true;
                continue;
            }

            if ($c === '(') {
                $paren++;
            } elseif ($c === ')') {
                $paren = max(0, $paren - 1);
            } elseif ($c === '[') {
                $bracket++;
            } elseif ($c === ']') {
                $bracket = max(0, $bracket - 1);
            } elseif ($c === '{') {
                $brace++;
            } elseif ($c === '}') {
                $brace = max(0, $brace - 1);
            }

            if ($c === $ch && $paren === 0 && $bracket === 0 && $brace === 0) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * Find the matching closing paren for the open paren at $openIdx.
     */
    private function findMatchingParen(string $s, int $openIdx): int
    {
        if ($openIdx < 0 || $s[$openIdx] !== '(') {
            return -1;
        }

        $depth = 0;
        $inString = false;
        $escapeNext = false;
        $len = strlen($s);

        for ($i = $openIdx; $i < $len; $i++) {
            $ch = $s[$i];

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

            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return -1;
    }

    /**
     * If $s ends with a balanced openCh…closeCh group, extract it.
     * Returns ['before' => string, 'inner' => string] or null.
     *
     * @return array{before:string,inner:string}|null
     */
    private function extractTrailingGroup(string $s, string $openCh, string $closeCh): ?array
    {
        $trimmed = rtrim($s);
        if ($trimmed === '' || $trimmed[strlen($trimmed) - 1] !== $closeCh) {
            return null;
        }
        $closeIdx = strlen($trimmed) - 1;

        $inString = false;
        $depth = 1;
        $startIdx = -1;

        for ($i = $closeIdx - 1; $i >= 0; $i--) {
            $ch = $trimmed[$i];
            if ($ch === '"') {
                $inString = !$inString;
                continue;
            }
            if ($inString) {
                continue;
            }
            if ($ch === $closeCh) {
                $depth++;
            } elseif ($ch === $openCh) {
                $depth--;
                if ($depth === 0) {
                    $startIdx = $i;
                    break;
                }
            }
        }

        if ($startIdx === -1 || $depth !== 0) {
            return null;
        }

        $angleBal = 0;
        for ($i = 0; $i < $startIdx; $i++) {
            $ch = $trimmed[$i];
            if ($ch === '<' && ($i + 1 < strlen($trimmed) && $trimmed[$i + 1] !== '=')) {
                $angleBal++;
            } elseif ($ch === '>' && ($i === 0 || $trimmed[$i - 1] !== '=') && ($i === 0 || $trimmed[$i - 1] !== '<')) {
                $angleBal = max(0, $angleBal - 1);
            }
        }
        if ($angleBal > 0) {
            return null;
        }

        return [
            'before' => substr($trimmed, 0, $startIdx),
            'inner' => substr($trimmed, $startIdx + 1, $closeIdx - $startIdx - 1),
        ];
    }

    /**
     * Split $s on $delim only at the top level
     * (not inside parens, brackets, braces or strings).
     *
     * @return string[]
     */
    private function splitTopLevel(string $s, string $delim): array
    {
        $out = [];
        $cur = '';
        $inString = false;
        $escapeNext = false;
        $paren = $bracket = $brace = 0;
        $len = strlen($s);

        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];

            if ($escapeNext) {
                $cur .= $ch;
                $escapeNext = false;
                continue;
            }
            if ($inString) {
                if ($ch === '\\') {
                    $cur .= $ch;
                    $escapeNext = true;
                    continue;
                }
                if ($ch === '"') {
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

            if ($ch === '(') {
                $paren++;
            } elseif ($ch === ')') {
                $paren = max(0, $paren - 1);
            } elseif ($ch === '[') {
                $bracket++;
            } elseif ($ch === ']') {
                $bracket = max(0, $bracket - 1);
            } elseif ($ch === '{') {
                $brace++;
            } elseif ($ch === '}') {
                $brace = max(0, $brace - 1);
            }

            if ($ch === $delim && $paren === 0 && $bracket === 0 && $brace === 0) {
                $out[] = $cur;
                $cur = '';
                continue;
            }

            $cur .= $ch;
        }

        $out[] = $cur;
        return $out;
    }

    private function unescapeString(string $str): string
    {
        return str_replace(
            ['\\n', '\\r', '\\t', '\\"', '\\\\'],
            ["\n", "\r", "\t", '"', '\\'],
            $str
        );
    }

    private function extractReferencedType(?string $typeExpr): ?string
    {
        if ($typeExpr === null) {
            return null;
        }

        $t = trim($typeExpr);

        if (str_starts_with($t, 'enum')) {
            return null;
        }

        if (preg_match('/^map\s*<\s*(.+)\s*>\s*$/', $t, $m)) {
            $inside = $m[1];
            $depth = 0;
            $parenDepth = 0;
            $lastComma = -1;
            $len = strlen($inside);

            for ($i = 0; $i < $len; $i++) {
                $c = $inside[$i];
                if ($c === '(') {
                    $parenDepth++;
                } elseif ($c === ')') {
                    $parenDepth = max(0, $parenDepth - 1);
                } elseif ($parenDepth === 0 && $c === '<') {
                    $depth++;
                } elseif ($parenDepth === 0 && $c === '>') {
                    $depth--;
                } elseif ($c === ',' && $depth === 0 && $parenDepth === 0) {
                    $lastComma = $i;
                }
            }

            $valueType = $lastComma >= 0 ? trim(substr($inside, $lastComma + 1)) : trim($inside);
            return $this->extractReferencedType($valueType);
        }

        if ($t === 'map') {
            return null;
        }

        $t = trim(preg_replace('/\([^)]*\)\s*$/', '', $t));

        while (str_ends_with($t, '[]')) {
            $t = trim(substr($t, 0, -2));
            $t = trim(preg_replace('/\([^)]*\)\s*$/', '', $t));
        }

        if ($t === '') {
            return null;
        }

        if (in_array($t, self::PRIMITIVE_TYPES, true)) {
            return null;
        }

        return $t;
    }

    private function getBaseTypeName(?string $typeExpr): ?string
    {
        if ($typeExpr === null) {
            return null;
        }

        $t = trim($typeExpr);
        $t = preg_replace('/\([^)]*\)\s*$/', '', $t);
        $t = trim($t);

        while (str_ends_with($t, '[]')) {
            $t = rtrim(substr($t, 0, -2));
        }

        $t = strtolower($t);
        if (str_starts_with($t, 'enum')) {
            return 'enum';
        }
        if (str_starts_with($t, 'map')) {
            return 'map';
        }

        return $t !== '' ? $t : null;
    }
}
