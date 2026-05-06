<?php

declare(strict_types=1);

namespace Maxi\Internal;

use Maxi\Core\MaxiErrorCode;
use Maxi\Core\MaxiException;
use Maxi\Core\MaxiParseResult;
use Maxi\Core\MaxiRecord;
use Maxi\Core\MaxiSchema;

/**
 * Builds and validates an object registry for MAXI reference fields.
 */
class ReferenceResolver
{
    private const NON_REF_TYPES = ['int', 'decimal', 'float', 'str', 'bool', 'bytes'];

    /**
     * Build an object registry (alias → [id → object]) from all parsed records.
     * @return array<string, array<string, array<string, mixed>>>
     *         alias → id-string → field-name-keyed array
     */
    public static function buildObjectRegistry(MaxiParseResult $result): array
    {
        $registry = [];

        foreach ($result->records as $record) {
            $typeDef = $result->schema->getType($record->alias);
            if ($typeDef === null) {
                continue;
            }

            $idField = $typeDef->getIdField();
            if ($idField === null) {
                continue;
            }

            $idIndex = array_search($idField, $typeDef->fields, true);
            if ($idIndex === false || $idIndex >= count($record->values)) {
                continue;
            }

            $idValue = $record->values[$idIndex];
            if ($idValue === null) {
                continue;
            }

            $obj = [];
            foreach ($typeDef->fields as $i => $field) {
                $obj[$field->name] = $record->values[$i] ?? null;
            }

            $registry[$record->alias][(string)$idValue] = $obj;
        }

        foreach ($result->records as $record) {
            $typeDef = $result->schema->getType($record->alias);
            if ($typeDef === null) {
                continue;
            }

            foreach ($typeDef->fields as $i => $field) {
                $value = $record->values[$i] ?? null;

                if ($value === null) {
                    continue;
                }

                $refAlias = self::getReferencedTypeAlias($field->typeExpr, $result->schema);
                if ($refAlias === null) {
                    continue;
                }

                $refTypeDef = $result->schema->getType($refAlias);
                if ($refTypeDef === null) {
                    continue;
                }

                $refIdField = $refTypeDef->getIdField();
                if ($refIdField === null) {
                    continue;
                }

                if (is_array($value) && !array_is_list($value)) {
                    $refIdValue = $value[$refIdField->name] ?? null;
                    if ($refIdValue === null) {
                        continue;
                    }

                    $idKey = (string)$refIdValue;
                    if (!isset($registry[$refAlias][$idKey])) {
                        $registry[$refAlias][$idKey] = $value;
                    }

                    $parentIdField = $typeDef->getIdField();
                    if ($parentIdField !== null) {
                        $parentIdIdx = array_search($parentIdField, $typeDef->fields, true);
                        if ($parentIdIdx !== false && $parentIdIdx < count($record->values)) {
                            $parentId = (string)($record->values[$parentIdIdx]);
                            if (isset($registry[$record->alias][$parentId])) {
                                $registry[$record->alias][$parentId][$field->name] = $refIdValue;
                            }
                        }
                    }
                }

                if (is_array($value) && array_is_list($value)) {
                    $elemTypeExpr = null;
                    $te = trim($field->typeExpr ?? '');
                    if (preg_match('/^(.+)\[\]\s*$/', $te, $m)) {
                        $elemTypeExpr = trim($m[1]);
                    }
                    if ($elemTypeExpr === null) {
                        continue;
                    }

                    $elemRefAlias = self::getReferencedTypeAlias($elemTypeExpr, $result->schema);
                    if ($elemRefAlias === null) {
                        continue;
                    }

                    $elemTypeDef = $result->schema->getType($elemRefAlias);
                    if ($elemTypeDef === null) {
                        continue;
                    }

                    $elemIdField = $elemTypeDef->getIdField();
                    if ($elemIdField === null) {
                        continue;
                    }

                    $replacedValues = [];
                    foreach ($value as $elem) {
                        if (is_array($elem) && !array_is_list($elem)) {
                            $elemIdValue = $elem[$elemIdField->name] ?? null;
                            if ($elemIdValue !== null) {
                                $eIdKey = (string)$elemIdValue;
                                if (!isset($registry[$elemRefAlias][$eIdKey])) {
                                    $registry[$elemRefAlias][$eIdKey] = $elem;
                                }
                                $replacedValues[] = $elemIdValue;
                            } else {
                                $replacedValues[] = $elem;
                            }
                        } else {
                            $replacedValues[] = $elem;
                        }
                    }

                    $parentIdField = $typeDef->getIdField();
                    if ($parentIdField !== null) {
                        $parentIdIdx = array_search($parentIdField, $typeDef->fields, true);
                        if ($parentIdIdx !== false && $parentIdIdx < count($record->values)) {
                            $parentId = (string)($record->values[$parentIdIdx]);
                            if (isset($registry[$record->alias][$parentId])) {
                                $registry[$record->alias][$parentId][$field->name] = $replacedValues;
                            }
                        }
                    }
                }
            }
        }

        return $registry;
    }

    /**
     * Validate that every scalar value in a reference field resolves to a known id.
     *
     * @param array<string, array<string, array<string, mixed>>> $registry
     *        Output of buildObjectRegistry().
     */
    public static function validateReferences(
        MaxiParseResult $result,
        array           $registry,
        ?string         $filename = null,
        array           $options = [],
    ): void {
        $allowForwardReferences = $options['allowForwardReferences'] ?? true;

        foreach ($result->records as $record) {
            $typeDef = $result->schema->getType($record->alias);
            if ($typeDef === null) {
                continue;
            }

            foreach ($typeDef->fields as $i => $field) {
                $value = $record->values[$i] ?? null;

                if ($value === null) {
                    continue;
                }

                if (is_array($value) || is_object($value)) {
                    continue;
                }

                $refAlias = self::getReferencedTypeAlias($field->typeExpr, $result->schema);
                if ($refAlias === null) {
                    continue;
                }

                $idKey = (string)$value;
                $typeRegistry = $registry[$refAlias] ?? null;

                if ($typeRegistry === null || !isset($typeRegistry[$idKey])) {
                    $msg = "Unresolved reference: field '{$field->name}' in '{$record->alias}'"
                        . " references {$refAlias} id '{$value}', but no such object found";

                    if (!$allowForwardReferences) {
                        throw new MaxiException(
                            $msg,
                            MaxiErrorCode::UnresolvedReferenceError,
                            $record->lineNumber,
                            null,
                            $filename,
                        );
                    }

                    $result->addWarning($msg, MaxiErrorCode::UnresolvedReferenceError, $record->lineNumber);
                }
            }
        }
    }

    /**
     * Return the type alias that $typeExpr references, or null if the expression
     * is a primitive, map, enum, or undefined.
     */
    public static function getReferencedTypeAlias(?string $typeExpr, MaxiSchema $schema): ?string
    {
        if ($typeExpr === null) {
            return null;
        }

        $t = trim($typeExpr);

        $t = preg_replace('/(\[\])+$/', '', $t);

        if (in_array($t, self::NON_REF_TYPES, true)) {
            return null;
        }
        if ($t === 'map' || str_starts_with($t, 'map<')) {
            return null;
        }
        if (str_starts_with($t, 'enum')) {
            return null;
        }

        if ($schema->hasType($t)) {
            return $t;
        }

        return ($schema->nameToAlias ?? [])[$t] ?? null;
    }
}

