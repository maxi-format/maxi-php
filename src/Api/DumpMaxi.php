<?php

declare(strict_types=1);

namespace Maxi\Api;

use Maxi\Core\MaxiFieldDef;
use Maxi\Core\MaxiParseResult;
use Maxi\Core\MaxiRecord;
use Maxi\Core\MaxiTypeDef;
use Maxi\Core\ParsedConstraint;

/**
 * Serialize a MAXI parse result, array of objects, or alias-keyed map back to a MAXI string.
 *
 * Accepted $data shapes:
 *   - MaxiParseResult           → round-trip dump
 *   - list<array>               → requires options['defaultAlias']
 *   - array<alias, list<array>> → alias-keyed rows
 *   - single array (object)     → requires options['defaultAlias']
 *
 * Options (all optional):
 *   multiline        bool   – each field/record on its own line
 *   includeTypes     bool   – emit type definitions  (default true)
 *   version          string – override @version directive
 *   schemaFile       string – emit @schema import
 *   types            array  – type descriptors for plain-array input
 *   defaultAlias     string – alias used when $data is a flat list/object
 *   collectReferences bool  – auto-collect nested referenced objects (default true)
 */
function dumpMaxi(mixed $data, array $options = []): string
{
    if ($data instanceof MaxiParseResult) {
        return dumpFromParseResult($data, $options);
    }

    $dataMap = [];
    if (is_array($data) && array_is_list($data)) {
        $alias = $options['defaultAlias'] ?? null;
        if ($alias === null) {
            throw new \InvalidArgumentException('dumpMaxi requires `options["defaultAlias"]` when dumping a list.');
        }
        $dataMap[$alias] = $data;
    } elseif (is_array($data)) {
        $firstVal = reset($data);
        if (!is_array($firstVal) || !array_is_list($firstVal)) {
            $alias = $options['defaultAlias'] ?? null;
            if ($alias === null) {
                throw new \InvalidArgumentException('dumpMaxi requires `options["defaultAlias"]` when dumping a single object.');
            }
            $dataMap[$alias] = [$data];
        } else {
            $dataMap = $data;
        }
    }

    return dumpFromObjects($dataMap, $options);
}

function dumpFromParseResult(MaxiParseResult $result, array $options): string
{
    $multiline = $options['multiline'] ?? false;
    $includeTypes = $options['includeTypes'] ?? true;
    $out = [];

    if (($result->schema->version ?? '1.0.0') !== '1.0.0') {
        $out[] = '@version:' . $result->schema->version;
    }
    foreach ($result->schema->imports ?? [] as $imp) {
        $out[] = "@schema:{$imp}";
    }

    if ($includeTypes && count($result->schema->types) > 0) {
        if (count($out) > 0) {
            $out[] = '';
        }
        foreach ($result->schema->types as $td) {
            $out[] = dumpTypeDef($td, $multiline);
        }
    }

    if (count($out) > 0) {
        $out[] = '###';
    }

    foreach ($result->records as $record) {
        $out[] = dumpRecord($record, $multiline);
    }

    return implode("\n", $out);
}

/**
 * @param array<string, list<array<string,mixed>>> $dataMap
 */
function dumpFromObjects(array $dataMap, array $options): string
{
    $multiline = $options['multiline'] ?? false;
    $includeTypes = $options['includeTypes'] ?? true;
    $out = [];

    $types = normalizeTypesOption($options['types'] ?? null);

    resolveInheritanceForDump($types);

    $version = $options['version'] ?? null;
    $schemaFile = $options['schemaFile'] ?? null;

    if ($version !== null && $version !== '1.0.0') {
        $out[] = "@version:{$version}";
    }
    if ($schemaFile !== null) {
        $out[] = "@schema:{$schemaFile}";
    }

    if ($includeTypes && count($types) > 0) {
        if (count($out) > 0) {
            $out[] = '';
        }
        foreach ($types as $t) {
            $out[] = dumpTypeInfo($t, $multiline);
        }
    }

    if (count($out) > 0) {
        $out[] = '###';
    }

    $recordsToDump = [];
    foreach ($dataMap as $alias => $rows) {
        $recordsToDump[$alias] = array_merge($recordsToDump[$alias] ?? [], $rows);
    }

    $collectRefs = $options['collectReferences'] ?? true;
    if ($collectRefs) {
        $seenObjects = new \SplObjectStorage();
        collectReferencedObjectsIterative($types, $recordsToDump, $seenObjects);
    }

    foreach ($recordsToDump as $alias => $rows) {
        $t = $types[$alias] ?? null;
        if (!$multiline && $t !== null && !$collectRefs) {
            $fields = $t['fields'] ?? [];
            $fieldCount = count($fields);
            foreach ($rows as $obj) {
                $vals = [];
                for ($fi = 0; $fi < $fieldCount; $fi++) {
                    $fname = $fields[$fi]['name'];
                    if (!array_key_exists($fname, $obj)) {
                        $vals[] = '';
                        continue;
                    }
                    $v = $obj[$fname];
                    if ($v === null) {
                        $vals[] = '~';
                    } elseif (is_int($v) || is_float($v)) {
                        $vals[] = (string)$v;
                    } elseif (is_bool($v)) {
                        $vals[] = $v ? '1' : '0';
                    } elseif (is_string($v)) {
                        if ($v === '' || $v === '~') {
                            $vals[] = '"' . $v . '"';
                        } elseif ($v[0] <= ' ' || $v[-1] <= ' ' || strpbrk($v, '|()[]{}~,:\\"') !== false) {
                            $vals[] = '"' . str_replace(
                                    ['\\', '"', "\n", "\r", "\t"],
                                    ['\\\\', '\\"', '\\n', '\\r', '\\t'],
                                    $v
                                ) . '"';
                        } else {
                            $vals[] = $v;
                        }
                    } elseif (is_array($v)) {
                        $vals[] = dumpValue($v, $fields[$fi], $allTypes = $types, $options);
                    } else {
                        $vals[] = (string)$v;
                    }
                }
                $li = count($vals) - 1;
                while ($li >= 0 && $vals[$li] === '') {
                    $li--;
                }
                if ($li < count($vals) - 1) {
                    $out[] = $alias . '(' . implode('|', array_slice($vals, 0, $li + 1)) . ')';
                } else {
                    $out[] = $alias . '(' . implode('|', $vals) . ')';
                }
            }
        } else {
            foreach ($rows as $obj) {
                $out[] = dumpObjectAsRecord($alias, $obj, $t, $types, $multiline, $options);
            }
        }
    }

    return implode("\n", $out);
}

/**
 * @param mixed $typesOption array of type descriptors or MaxiTypeDef objects
 * @return array<string, array{alias:string,name:string|null,parents:string[],fields:array}>
 */
function normalizeTypesOption(mixed $typesOption): array
{
    if ($typesOption === null) {
        return [];
    }

    $out = [];

    $items = is_array($typesOption) ? $typesOption : [];
    foreach ($items as $item) {
        if ($item instanceof MaxiTypeDef) {
            $fields = [];
            foreach ($item->fields as $f) {
                $fields[] = [
                    'name' => $f->name,
                    'typeExpr' => $f->typeExpr,
                    'annotation' => $f->annotation,
                    'constraints' => $f->constraints ?? [],
                    'elementConstraints' => $f->elementConstraints ?? [],
                    'defaultValue' => $f->defaultValue,
                ];
            }
            $out[$item->alias] = [
                'alias' => $item->alias,
                'name' => $item->name,
                'parents' => $item->parents,
                'fields' => $fields,
            ];
        } elseif (is_array($item) && isset($item['alias'])) {
            $out[$item['alias']] = $item + ['name' => null, 'parents' => [], 'fields' => []];
        }
    }

    return $out;
}

/**
 * @param array<string, array{alias:string,name:string|null,parents:string[],fields:array}> $types
 */
function resolveInheritanceForDump(array &$types): void
{
    $resolved = [];

    $resolve = function (string $alias) use (&$resolve, &$types, &$resolved): void {
        if (isset($resolved[$alias])) {
            return;
        }
        $t = $types[$alias] ?? null;
        if ($t === null || empty($t['parents'])) {
            $resolved[$alias] = true;
            return;
        }

        $inheritedFields = [];
        $ownFieldNames = array_flip(array_column($t['fields'], 'name'));

        foreach ($t['parents'] as $parentAlias) {
            $resolve($parentAlias);
            $parent = $types[$parentAlias] ?? null;
            if ($parent === null) {
                continue;
            }
            foreach ($parent['fields'] as $pf) {
                if (!isset($ownFieldNames[$pf['name']])) {
                    $inheritedFields[] = $pf;
                    $ownFieldNames[$pf['name']] = true;
                }
            }
        }

        if (count($inheritedFields) > 0) {
            $types[$alias]['fields'] = array_merge($inheritedFields, $t['fields']);
        }
        $resolved[$alias] = true;
    };

    foreach (array_keys($types) as $alias) {
        $resolve($alias);
    }
}

/**
 * @param array<string,array> $types
 * @param array<string,list<array<string,mixed>>> $recordsToDump
 */
function collectReferencedObjectsIterative(
    array             $types,
    array             &$recordsToDump,
    \SplObjectStorage $seenObjects,
): void {
    /** @var list<array{alias:string,obj:array<string,mixed>}> */
    $work = [];

    foreach ($recordsToDump as $alias => $rows) {
        foreach ($rows as $obj) {
            if (is_array($obj) && !$seenObjects->contains((object)$obj)) {
                $seenObjects->attach((object)$obj);
                $work[] = ['alias' => $alias, 'obj' => $obj];
            }
        }
    }

    while (!empty($work)) {
        $item = array_pop($work);
        $alias = $item['alias'];
        $obj = $item['obj'];
        $t = $types[$alias] ?? null;
        if ($t === null) {
            continue;
        }

        foreach ($t['fields'] ?? [] as $field) {
            $v = $obj[$field['name']] ?? null;
            if ($v === null || !is_array($v)) {
                continue;
            }

            $baseType = preg_replace('/\[\]$/', '', $field['typeExpr'] ?? '');
            $nestedType = $types[$baseType] ?? null;
            if ($nestedType === null) {
                continue;
            }

            $idFieldName = null;
            foreach ($nestedType['fields'] as $nf) {
                if ($nf['name'] === 'id') {
                    $idFieldName = 'id';
                    break;
                }
            }
            if ($idFieldName === null) {
                continue;
            }

            $items = array_is_list($v) ? $v : [$v];
            foreach ($items as $nestedObj) {
                if (!is_array($nestedObj)) {
                    continue;
                }
                $objKey = (object)$nestedObj;
                if ($seenObjects->contains($objKey)) {
                    continue;
                }
                if (isset($nestedObj[$idFieldName])) {
                    $recordsToDump[$nestedType['alias']][] = $nestedObj;
                    $seenObjects->attach($objKey);
                    $work[] = ['alias' => $nestedType['alias'], 'obj' => $nestedObj];
                }
            }
        }
    }
}

/** Dump a MaxiTypeDef (from a parsed schema). */
function dumpTypeDef(MaxiTypeDef $td, bool $multiline): string
{
    $header = $td->name !== null ? "{$td->alias}:{$td->name}" : $td->alias;
    $parents = count($td->parents) > 0 ? '<' . implode(',', $td->parents) . '>' : '';

    if (!$multiline) {
        $fields = implode('|', array_map('Maxi\Api\dumpFieldDef', $td->fields));
        return "{$header}{$parents}({$fields})";
    }

    $body = implode("|\n", array_map(fn($f) => '  ' . dumpFieldDef($f), $td->fields));
    return "{$header}{$parents}(\n{$body}\n)";
}

/** Dump a plain type-info array (from dumpFromObjects). */
function dumpTypeInfo(array $t, bool $multiline): string
{
    $alias = $t['alias'];
    $name = $t['name'] ?? null;
    $parents = $t['parents'] ?? [];
    $fields = $t['fields'] ?? [];

    $header = $name !== null ? "{$alias}:{$name}" : $alias;
    $parStr = count($parents) > 0 ? '<' . implode(',', $parents) . '>' : '';

    if (!$multiline) {
        $fieldStr = implode('|', array_map('Maxi\Api\dumpFieldArray', $fields));
        return "{$header}{$parStr}({$fieldStr})";
    }

    $body = implode("|\n", array_map(fn($f) => '  ' . dumpFieldArray($f), $fields));
    return "{$header}{$parStr}(\n{$body}\n)";
}

/** Dump a MaxiFieldDef object. */
function dumpFieldDef(MaxiFieldDef $field): string
{
    $result = $field->name;

    $hasElemConstraints = $field->elementConstraints !== null && count($field->elementConstraints) > 0;
    $hasArrayType = $field->typeExpr !== null && str_contains($field->typeExpr, '[]');

    if ($hasElemConstraints && $hasArrayType) {
        $lastBracket = strrpos($field->typeExpr, '[]');
        $baseType = substr($field->typeExpr, 0, $lastBracket);
        $suffix = substr($field->typeExpr, $lastBracket);

        $result .= ":{$baseType}";
        $elemStrs = array_filter(array_map('Maxi\Api\dumpConstraintObj', $field->elementConstraints));
        if (count($elemStrs) > 0) {
            $result .= '(' . implode(',', $elemStrs) . ')';
        }
        $result .= $suffix;

        if ($field->constraints !== null && count($field->constraints) > 0) {
            $arrStrs = array_filter(array_map('Maxi\Api\dumpConstraintObj', $field->constraints));
            if (count($arrStrs) > 0) {
                $result .= '(' . implode(',', $arrStrs) . ')';
            }
        }

        if ($field->annotation !== null) {
            $result .= "@{$field->annotation}";
        }
    } else {
        if ($field->typeExpr !== null) {
            $result .= ":{$field->typeExpr}";
        }
        if ($field->annotation !== null) {
            $result .= "@{$field->annotation}";
        }
        if ($field->constraints !== null && count($field->constraints) > 0) {
            $cs = array_filter(array_map('Maxi\Api\dumpConstraintObj', $field->constraints));
            if (count($cs) > 0) {
                $result .= '(' . implode(',', $cs) . ')';
            }
        }
    }

    if ($field->defaultValue !== MaxiFieldDef::missing()) {
        $defVal = $field->defaultValue;
        $defStr = is_string($defVal) && needsQuoting($defVal)
            ? '"' . escapeString($defVal) . '"'
            : (string)$defVal;
        $result .= "={$defStr}";
    }

    return $result;
}

/** Dump a field from a plain-array descriptor. */
function dumpFieldArray(array $field): string
{
    $name = $field['name'];
    $typeExpr = $field['typeExpr'] ?? null;
    $annotation = $field['annotation'] ?? null;
    $constraints = $field['constraints'] ?? [];
    $elemConstraints = $field['elementConstraints'] ?? [];
    $defaultValue = $field['defaultValue'] ?? null;

    $result = $name;

    $hasElemConstraints = count($elemConstraints) > 0;
    $hasArrayType = $typeExpr !== null && str_contains($typeExpr, '[]');

    if ($hasElemConstraints && $hasArrayType) {
        $lastBracket = strrpos($typeExpr, '[]');
        $baseType = substr($typeExpr, 0, $lastBracket);
        $suffix = substr($typeExpr, $lastBracket);

        $result .= ":{$baseType}";
        $elemStrs = array_filter(array_map('Maxi\Api\dumpConstraintRaw', $elemConstraints));
        if (count($elemStrs) > 0) {
            $result .= '(' . implode(',', $elemStrs) . ')';
        }
        $result .= $suffix;

        if (count($constraints) > 0) {
            $arrStrs = array_filter(array_map('Maxi\Api\dumpConstraintRaw', $constraints));
            if (count($arrStrs) > 0) {
                $result .= '(' . implode(',', $arrStrs) . ')';
            }
        }

        if ($annotation !== null) {
            $result .= "@{$annotation}";
        }
    } else {
        if ($typeExpr !== null) {
            $result .= ":{$typeExpr}";
        }
        if ($annotation !== null) {
            $result .= "@{$annotation}";
        }
        if (count($constraints) > 0) {
            $cs = array_filter(array_map('Maxi\Api\dumpConstraintRaw', $constraints));
            if (count($cs) > 0) {
                $result .= '(' . implode(',', $cs) . ')';
            }
        }
    }

    if ($defaultValue !== null) {
        $defStr = is_string($defaultValue) && needsQuoting($defaultValue)
            ? '"' . escapeString($defaultValue) . '"'
            : (string)$defaultValue;
        $result .= "={$defStr}";
    }

    return $result;
}

/** Dump a ParsedConstraint object. */
function dumpConstraintObj(ParsedConstraint $c): string
{
    $v = $c->value;

    return match ($c->type) {
        'required' => '!',
        'id' => 'id',
        'comparison' => ($v['operator'] ?? '') . ($v['value'] ?? ''),
        'pattern' => 'pattern:' . (string)$v,
        'mime' => is_array($v) && count($v) > 1
            ? 'mime:[' . implode(',', $v) . ']'
            : 'mime:' . (is_array($v) ? ($v[0] ?? '') : (string)$v),
        'decimal-precision' => is_array($v) ? ($v['raw'] ?? (string)$v) : (string)$v,
        'exact-length' => '=' . (string)$v,
        default => '',
    };
}

/** Dump a raw constraint array (from plain-array type descriptors). */
function dumpConstraintRaw(mixed $c): string
{
    if ($c instanceof ParsedConstraint) {
        return dumpConstraintObj($c);
    }
    if (!is_array($c)) {
        return '';
    }

    $type = $c['type'] ?? '';
    $v = $c['value'] ?? null;

    return match ($type) {
        'required' => '!',
        'id' => 'id',
        'comparison' => ($c['operator'] ?? $v['operator'] ?? '') . ($v['value'] ?? $v ?? ''),
        'pattern' => 'pattern:' . (string)$v,
        'mime' => is_array($v) && count($v) > 1
            ? 'mime:[' . implode(',', $v) . ']'
            : 'mime:' . (is_array($v) ? ($v[0] ?? '') : (string)$v),
        'decimal-precision' => is_array($v) ? ($v['raw'] ?? (string)$v) : (string)$v,
        'exact-length' => '=' . (string)$v,
        default => '',
    };
}

/** Dump a MaxiRecord (from a parsed result). */
function dumpRecord(MaxiRecord $record, bool $multiline): string
{
    $values = array_map(fn($v) => dumpValue($v, null, [], []), $record->values);

    if (!$multiline) {
        return "{$record->alias}(" . implode('|', $values) . ')';
    }

    $body = implode("|\n", array_map(fn($v) => "  {$v}", $values));
    return "{$record->alias}(\n{$body}\n)";
}

/** Dump an object (array) as a MAXI record line using a plain-array type descriptor. */
function dumpObjectAsRecord(
    string $alias,
    array  $obj,
    ?array $typeDef,
    array  $allTypes,
    bool   $multiline,
    array  $options,
): string {
    if ($typeDef !== null) {
        $fields = $typeDef['fields'] ?? [];
        $vals = [];
        foreach ($fields as $f) {
            if (!array_key_exists($f['name'], $obj)) {
                $vals[] = '';
                continue;
            }
            $v = $obj[$f['name']];
            if ($v === null) {
                $vals[] = '~';
            } else {
                $vals[] = dumpValue($v, $f, $allTypes, $options);
            }
        }
    } else {
        $vals = [];
        foreach ($obj as $v) {
            $vals[] = $v === null ? '~' : dumpValue($v, null, $allTypes, $options);
        }
    }

    $lastIdx = count($vals) - 1;
    while ($lastIdx >= 0 && $vals[$lastIdx] === '') {
        $lastIdx--;
    }
    $vals = array_slice($vals, 0, $lastIdx + 1);

    if (!$multiline) {
        return "{$alias}(" . implode('|', $vals) . ')';
    }

    $body = implode("|\n", array_map(fn($v) => "  {$v}", $vals));
    return "{$alias}(\n{$body}\n)";
}

/**
 * Serialise a single PHP value to its MAXI string representation.
 *
 * @param mixed $value
 * @param array|null $fieldInfo plain array descriptor (from dumpFromObjects) or null
 * @param array<string,array> $allTypes
 */
function dumpValue(mixed $value, mixed $fieldInfo, array $allTypes, array $options): string
{
    if ($value === null) {
        return '~';
    }

    if (is_string($value)) {
        if ($value === '' || $value === '~' || $value[0] <= ' ' || $value[-1] <= ' ' || strpbrk($value, '|()[]{}~,:\\"') !== false) {
            return '"' . escapeString($value) . '"';
        }
        return $value;
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    if (is_array($value) && array_is_list($value)) {
        $typeExpr = $fieldInfo['typeExpr'] ?? null;
        $elemTypeExpr = null;
        if ($typeExpr !== null && preg_match('/^(.+)\[\]\s*$/', $typeExpr, $m)) {
            $elemTypeExpr = $m[1];
        }
        $elemFieldInfo = $elemTypeExpr !== null
            ? array_merge($fieldInfo ?? [], ['typeExpr' => $elemTypeExpr])
            : $fieldInfo;

        $parts = array_map(fn($v) => dumpValue($v, $elemFieldInfo, $allTypes, $options), $value);
        return '[' . implode(',', $parts) . ']';
    }

    if (is_array($value)) {
        $fieldTypeRef = null;
        if (isset($fieldInfo['typeExpr'])) {
            $fieldTypeRef = preg_replace('/\[\]$/', '', $fieldInfo['typeExpr']);
        }
        $nestedType = $fieldTypeRef !== null ? ($allTypes[$fieldTypeRef] ?? null) : null;

        if ($nestedType !== null) {
            $idFieldName = null;
            foreach ($nestedType['fields'] ?? [] as $nf) {
                if ($nf['name'] === 'id') {
                    $idFieldName = 'id';
                    break;
                }
            }

            if ($idFieldName !== null && array_key_exists($idFieldName, $value)) {
                if (($options['collectReferences'] ?? true) === false) {
                    return dumpInlineObject($value, $nestedType, $allTypes, $options);
                }
                return dumpValue($value[$idFieldName], null, $allTypes, $options);
            }
            return dumpInlineObject($value, $nestedType, $allTypes, $options);
        }

        $pairs = [];
        foreach ($value as $k => $v) {
            $keyStr = needsQuoting((string)$k) ? '"' . escapeString((string)$k) . '"' : (string)$k;
            $pairs[] = $keyStr . ':' . dumpValue($v, null, $allTypes, $options);
        }
        return '{' . implode(',', $pairs) . '}';
    }

    return (string)$value;
}

function dumpInlineObject(array $obj, array $typeDef, array $allTypes, array $options): string
{
    $fields = $typeDef['fields'] ?? [];
    $vals = [];

    foreach ($fields as $f) {
        if (!array_key_exists($f['name'], $obj)) {
            $vals[] = '';
            continue;
        }
        $v = $obj[$f['name']];
        $vals[] = $v === null ? '~' : dumpValue($v, $f, $allTypes, $options);
    }

    $lastIdx = count($vals) - 1;
    while ($lastIdx >= 0 && $vals[$lastIdx] === '') {
        $lastIdx--;
    }
    $vals = array_slice($vals, 0, $lastIdx + 1);

    return '(' . implode('|', $vals) . ')';
}

function needsQuoting(string $str): bool
{
    if ($str === '' || $str === '~') {
        return true;
    }
    if ($str[0] <= ' ' || $str[-1] <= ' ') {
        return true;
    }
    return strpbrk($str, '|()[]{}~,:\\"') !== false;
}

function escapeString(string $str): string
{
    return str_replace(
        ['\\', '"', "\n", "\r", "\t"],
        ['\\\\', '\\"', '\\n', '\\r', '\\t'],
        $str,
    );
}
