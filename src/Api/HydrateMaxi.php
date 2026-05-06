<?php

declare(strict_types=1);

namespace Maxi\Api;

use Maxi\Core\MaxiHydrateResult;
use Maxi\Core\MaxiParseResult;
use Maxi\Core\MaxiRecord;
use Maxi\Core\MaxiSchema;
use Maxi\Core\MaxiTypeDef;
use Maxi\Registry\MaxiSchemaRegistry;

/**
 * Parse MAXI text and hydrate records into typed PHP objects.
 *
 * @param array<string, class-string> $classMap e.g. ['U' => User::class, 'O' => Order::class]
 * @param array{filename?:string|null,loadSchema?:callable|null} $options
 *
 * @example
 * $result = parseMaxiAs($maxi, ['U' => User::class]);
 * foreach ($result->data['U'] as $user) { ... }
 */
function parseMaxiAs(string $input, array $classMap, array $options = []): MaxiHydrateResult
{
    if (empty($classMap) || array_is_list($classMap)) {
        throw new \InvalidArgumentException('parseMaxiAs: classMap must be a [alias => ClassName] associative array.');
    }

    $parseResult = parseMaxi($input, $options);
    return hydrateResult($parseResult, $classMap);
}

/**
 * Convenience variant — pass an array of class names instead of an alias → class map.
 * Each class must carry #[MaxiType] or be registered via MaxiSchemaRegistry::define().
 *
 * @param array<class-string> $classes e.g. [User::class, Order::class]
 * @param array{filename?:string|null,loadSchema?:callable|null} $options
 *
 * @example
 * $result = parseMaxiAutoAs($maxi, [User::class, Order::class]);
 */
function parseMaxiAutoAs(string $input, array $classes, array $options = []): MaxiHydrateResult
{
    if (!array_is_list($classes)) {
        throw new \InvalidArgumentException('parseMaxiAutoAs: second argument must be a list of class names.');
    }

    $classMap = [];
    foreach ($classes as $class) {
        $schema = MaxiSchemaRegistry::get($class);
        if ($schema === null) {
            $short = is_string($class) ? $class : gettype($class);
            throw new \RuntimeException(
                "parseMaxiAutoAs: no maxiSchema found for class '{$short}'. " .
                "Attach #[MaxiType] to the class or use MaxiSchemaRegistry::define()."
            );
        }
        $classMap[$schema['alias']] = $class;
    }

    return parseMaxiAs($input, $classMap, $options);
}

/**
 * Serialize class instances back to MAXI, auto-collecting schema from the registry.
 *
 * $objects can be:
 *   - list<object>                 – all instances share the same class/alias
 *   - array<alias, list<object>>   – alias-keyed map
 *
 * Any options['types'] is merged in (caller wins over auto-collected types).
 *
 * @param list<object>|array<string,list<object>> $objects
 * @param array<string,mixed> $options
 */
function dumpMaxiAuto(array $objects, array $options = []): string
{
    // Normalise to alias → rows map
    if (array_is_list($objects) && count($objects) > 0) {
        $firstSchema = MaxiSchemaRegistry::get($objects[0]);
        $alias = $firstSchema['alias'] ?? ($options['defaultAlias'] ?? null);
        if ($alias === null) {
            throw new \RuntimeException(
                'dumpMaxiAuto: cannot determine alias. ' .
                'Either attach #[MaxiType] to the class or pass options["defaultAlias"].'
            );
        }
        $dataMap = [$alias => $objects];
    } elseif (!array_is_list($objects)) {
        $dataMap = $objects;
    } else {
        throw new \InvalidArgumentException('dumpMaxiAuto: $objects must be a list or an [alias => instances] map.');
    }

    $collectedTypes = [];

    foreach ($dataMap as $rows) {
        foreach ($rows as $obj) {
            if (is_object($obj) || is_array($obj)) {
                collectSchemasDeep($obj, $collectedTypes);
            }
        }
    }

    // Caller-supplied types win
    if (!empty($options['types'])) {
        $callerTypes = $options['types'];
        if (!is_array($callerTypes)) {
            $callerTypes = [];
        }
        foreach ($callerTypes as $t) {
            $alias = is_array($t) ? ($t['alias'] ?? null) : null;
            if ($alias !== null) {
                $collectedTypes[$alias] = $t;
            }
        }
    }

    $dumpOptions = $options;
    if (!empty($collectedTypes)) {
        $dumpOptions['types'] = array_values($collectedTypes);
    }

    // Convert object rows to array rows for dumpMaxi
    $arrayDataMap = [];
    foreach ($dataMap as $alias => $rows) {
        $arrayDataMap[$alias] = array_map(
            fn($obj) => is_object($obj) ? (array)$obj : $obj,
            $rows,
        );
    }

    return dumpMaxi($arrayDataMap, $dumpOptions);
}

// ─────────────────────────────────────────────────────────────────────────────
// Internal hydration helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @param array<string, class-string> $classMap
 */
function hydrateResult(MaxiParseResult $result, array $classMap): MaxiHydrateResult
{
    $hydrateResult = new MaxiHydrateResult($result->schema);

    // Build schema-by-alias map: prefer parsed schema, fall back to registry descriptor
    $schemaByAlias = [];
    foreach ($classMap as $alias => $class) {
        $parsed = $result->schema->getType($alias);
        if ($parsed !== null) {
            $schemaByAlias[$alias] = $parsed;
        } else {
            $descriptor = MaxiSchemaRegistry::get($class);
            if ($descriptor !== null) {
                $schemaByAlias[$alias] = $descriptor;
            }
        }
    }

    // First pass: construct instances, build instance registry for id-based refs
    /** @var array<string, array<string, object>> alias → id → instance */
    $instanceRegistry = [];

    foreach ($result->records as $record) {
        $class = $classMap[$record->alias] ?? null;
        if ($class === null) {
            continue;
        }

        $schema = $schemaByAlias[$record->alias] ?? null;
        $fieldMap = recordToFieldMap($record, $schema);
        $instance = constructInstance($class, $fieldMap);

        $hydrateResult->data[$record->alias][] = $instance;

        $idFieldName = findIdField($schema);
        if ($idFieldName !== null) {
            $idVal = $fieldMap[$idFieldName] ?? null;
            if ($idVal !== null) {
                $instanceRegistry[$record->alias][(string)$idVal] = $instance;
            }
        }
    }

    // Second pass: resolve scalar id-references to actual instances
    resolveHydratedReferences($hydrateResult->data, $schemaByAlias, $instanceRegistry, $result->schema);

    foreach ($result->warnings as $w) {
        $hydrateResult->addWarning($w->message, $w->code, $w->line, $w->column);
    }

    return $hydrateResult;
}

/**
 * Map record positional values to a [fieldName => value] array.
 *
 * @param MaxiTypeDef|array<string,mixed>|null $schema
 * @return array<string, mixed>
 */
function recordToFieldMap(MaxiRecord $record, mixed $schema): array
{
    $fields = [];
    if ($schema instanceof MaxiTypeDef) {
        $fields = $schema->fields;
    } elseif (is_array($schema)) {
        $fields = $schema['fields'] ?? [];
    }

    $map = [];
    if (count($fields) > 0) {
        foreach ($fields as $i => $field) {
            $fieldName = is_object($field) ? $field->name : ($field['name'] ?? $i);
            $map[$fieldName] = $record->values[$i] ?? null;
        }
    } else {
        foreach ($record->values as $i => $value) {
            $map[$i] = $value;
        }
    }
    return $map;
}

/**
 * Construct an instance of $class from a field-map.
 * Strategy: try named-constructor first, then no-arg + property assignment.
 *
 * @param class-string $class
 * @param array<string,mixed> $fieldMap
 */
function constructInstance(string $class, array $fieldMap): object
{
    // Try passing the whole field-map as a single associative array to the constructor
    try {
        $ref = new \ReflectionClass($class);

        // Try named arguments if constructor accepts corresponding params
        $ctor = $ref->getConstructor();
        if ($ctor !== null) {
            $params = $ctor->getParameters();
            $args = [];
            $allMatch = true;

            foreach ($params as $param) {
                $name = $param->getName();
                if (array_key_exists($name, $fieldMap)) {
                    $args[$name] = $fieldMap[$name];
                } elseif ($param->isOptional()) {
                    $args[$name] = $param->getDefaultValue();
                } else {
                    $allMatch = false;
                    break;
                }
            }

            if ($allMatch) {
                return $ref->newInstanceArgs($args);
            }
        }

        // No-arg constructor + property assignment (covers data-class patterns)
        $instance = $ref->getConstructor() !== null ? $ref->newInstanceWithoutConstructor() : $ref->newInstance();
        foreach ($fieldMap as $key => $value) {
            if ($ref->hasProperty((string)$key)) {
                $prop = $ref->getProperty((string)$key);
                $prop->setAccessible(true);
                $prop->setValue($instance, $value);
            } else {
                $instance->{$key} = $value;
            }
        }
        return $instance;

    } catch (\Throwable) {
        // Last resort: create via prototype and assign
        $instance = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        foreach ($fieldMap as $key => $value) {
            $instance->{$key} = $value;
        }
        return $instance;
    }
}

/**
 * Find the identifier field name in a schema (MaxiTypeDef or plain array).
 *
 * @param MaxiTypeDef|array<string,mixed>|null $schema
 */
function findIdField(mixed $schema): ?string
{
    if ($schema === null) {
        return null;
    }

    if ($schema instanceof MaxiTypeDef) {
        return $schema->getIdentifierFieldName();
    }

    // Plain-array descriptor from registry
    $fields = $schema['fields'] ?? [];
    foreach ($fields as $f) {
        $constraints = is_array($f) ? ($f['constraints'] ?? []) : [];
        foreach ($constraints as $c) {
            $type = is_array($c) ? ($c['type'] ?? '') : '';
            if ($type === 'id') {
                return is_array($f) ? ($f['name'] ?? null) : null;
            }
        }
    }
    foreach ($fields as $f) {
        $name = is_array($f) ? ($f['name'] ?? '') : '';
        if ($name === 'id') {
            return $name;
        }
    }
    return null;
}

/**
 * Walk all hydrated instances and replace scalar id-references with
 * the actual hydrated instance from $instanceRegistry.
 *
 * @param array<string, list<object>> $objects
 * @param array<string, MaxiTypeDef|array<string,mixed>> $schemaByAlias
 * @param array<string, array<string, object>> $instanceRegistry
 */
function resolveHydratedReferences(
    array      &$objects,
    array      $schemaByAlias,
    array      $instanceRegistry,
    MaxiSchema $parsedSchema,
): void {
    static $nonRef = ['str', 'int', 'decimal', 'float', 'bool', 'bytes'];

    foreach ($objects as $alias => $instances) {
        $schema = $schemaByAlias[$alias] ?? null;
        if ($schema === null) {
            continue;
        }

        $fields = $schema instanceof MaxiTypeDef ? $schema->fields : ($schema['fields'] ?? []);

        foreach ($instances as $instance) {
            foreach ($fields as $field) {
                $fieldName = is_object($field) ? $field->name : ($field['name'] ?? '');
                $typeExpr = is_object($field) ? $field->typeExpr : ($field['typeExpr'] ?? null);

                if ($typeExpr === null) {
                    continue;
                }

                $refAlias = getRefAlias($typeExpr, $parsedSchema, $nonRef);
                if ($refAlias === null) {
                    continue;
                }

                $refRegistry = $instanceRegistry[$refAlias] ?? null;
                if ($refRegistry === null) {
                    continue;
                }

                // Get current value from instance
                $currentVal = null;
                try {
                    $currentVal = isset($instance->{$fieldName}) ? $instance->{$fieldName} : null;
                } catch (\Throwable) {
                    continue;
                }

                // Only replace scalar id references (not already-resolved objects)
                if ($currentVal === null || is_object($currentVal) || is_array($currentVal)) {
                    continue;
                }

                $resolved = $refRegistry[(string)$currentVal] ?? null;
                if ($resolved !== null) {
                    try {
                        $ref = new \ReflectionClass($instance);
                        if ($ref->hasProperty($fieldName)) {
                            $prop = $ref->getProperty($fieldName);
                            $prop->setAccessible(true);
                            $prop->setValue($instance, $resolved);
                        } else {
                            $instance->{$fieldName} = $resolved;
                        }
                    } catch (\Throwable) {
                        $instance->{$fieldName} = $resolved;
                    }
                }
            }
        }
    }
}

function getRefAlias(string $typeExpr, MaxiSchema $parsedSchema, array $nonRef): ?string
{
    $t = trim(preg_replace('/(\[\])+$/', '', $typeExpr));
    if (in_array($t, $nonRef, true)) {
        return null;
    }
    if ($t === 'map' || str_starts_with($t, 'map<')) {
        return null;
    }
    if (str_starts_with($t, 'enum')) {
        return null;
    }
    if ($parsedSchema->hasType($t)) {
        return $t;
    }
    return ($parsedSchema->nameToAlias ?? [])[$t] ?? null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Auto-dump schema collection helper
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @param object|array<string,mixed> $obj
 * @param array<string, array<string,mixed>> $collected alias → descriptor (modified in-place)
 */
function collectSchemasDeep(object|array $obj, array &$collected): void
{
    $schema = MaxiSchemaRegistry::get($obj);
    if ($schema === null) {
        return;
    }
    $alias = $schema['alias'] ?? null;
    if ($alias === null || isset($collected[$alias])) {
        return;
    }
    $collected[$alias] = $schema;

    foreach ($schema['fields'] ?? [] as $field) {
        $fieldName = is_array($field) ? ($field['name'] ?? null) : null;
        if ($fieldName === null) {
            continue;
        }
        $v = is_object($obj) ? ($obj->{$fieldName} ?? null) : ($obj[$fieldName] ?? null);
        if ($v === null) {
            continue;
        }
        $items = (is_array($v) && array_is_list($v)) ? $v : [$v];
        foreach ($items as $item) {
            if (is_object($item) || is_array($item)) {
                collectSchemasDeep($item, $collected);
            }
        }
    }
}
