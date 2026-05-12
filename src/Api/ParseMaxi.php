<?php

declare(strict_types=1);

namespace Maxi\Api;

use Maxi\Core\MaxiParseResult;
use Maxi\Internal\RecordParser;
use Maxi\Internal\ReferenceResolver;
use Maxi\Internal\SchemaParser;

/**
 * Parse MAXI input (string) into a structured MaxiParseResult.
 *
 * Options:
 *   filename    – string|null     used in error messages
 *   loadSchema  – callable|null   fn(string $path): string  for @schema imports
 *   allowAdditionalFields  – 'ignore'|'warning'|'error'  (default: 'ignore')
 *   allowMissingFields     – 'null'|'warning'|'error'    (default: 'null')
 *   allowTypeCoercion      – 'coerce'|'warning'|'error'  (default: 'coerce')
 *   allowConstraintViolations – 'warning'|'error'        (default: 'warning')
 *   allowForwardReferences – bool                        (default: true)
 *   allowUnknownTypes      – 'ignore'|'warning'|'error'  (default: 'warning')
 *
 * @param array{filename?:string|null,loadSchema?:callable|null,allowAdditionalFields?:string,allowMissingFields?:string,allowTypeCoercion?:string,allowConstraintViolations?:string,allowForwardReferences?:bool,allowUnknownTypes?:string} $options
 */
function parseMaxi(string $input, array $options = []): MaxiParseResult
{
    $result = new MaxiParseResult();

    ['schemaSection' => $schemaSection, 'recordsSection' => $recordsSection] = splitSections($input);

    (new SchemaParser($schemaSection, $result, $options))->parse();

    if ($recordsSection !== null) {
        (new RecordParser($recordsSection, $result, $options))->parse();
    }

    if (count($result->records) > 0 && count($result->schema->types) > 0) {
        if (hasReferenceFields($result)) {
            $registry = ReferenceResolver::buildObjectRegistry($result);
            $result->objectRegistry = $registry;
            ReferenceResolver::validateReferences($result, $registry, $options['filename'] ?? null, $options);
        }
    }

    return $result;
}

/**
 * @return array{schemaSection:string,recordsSection:string|null}
 */
function splitSections(string $input): array
{
    if (preg_match('/^[ \t]*###[ \t]*(?:\r?\n|$)/m', $input, $m, PREG_OFFSET_CAPTURE)) {
        $sepOffset = $m[0][1];
        $schemaSection = trim(substr($input, 0, $sepOffset));
        $rest = trim(substr($input, $sepOffset + strlen($m[0][0])));
        return ['schemaSection' => $schemaSection, 'recordsSection' => ($rest !== '') ? $rest : null];
    }

    $hasDirective = (bool)preg_match('/^[ \t]*@/m', $input);
    $hasExplicitTypeDef = (bool)preg_match('/^[ \t]*[A-Za-z_][A-Za-z0-9_-]*[ \t]*:/m', $input);
    $hasInheritanceType = (bool)preg_match('/^[ \t]*[A-Za-z_][A-Za-z0-9_-]*[ \t]*<[^>]+>[ \t]*\(/m', $input);

    if ($hasDirective || $hasExplicitTypeDef || $hasInheritanceType) {
        if ($hasDirective && !$hasExplicitTypeDef && !$hasInheritanceType) {
            $schemaLines = [];
            $recordLines = [];
            foreach (preg_split('/\r?\n/', $input) as $line) {
                $trimmed = trim($line);
                if (preg_match('/^[ \t]*@/', $line) || $trimmed === '' || str_starts_with($trimmed, '#')) {
                    $schemaLines[] = $line;
                } else {
                    $recordLines[] = $line;
                }
            }
            $recordsText = trim(implode("\n", $recordLines));
            return [
                'schemaSection' => trim(implode("\n", $schemaLines)),
                'recordsSection' => ($recordsText !== '') ? $recordsText : null,
            ];
        }
        return ['schemaSection' => $input, 'recordsSection' => null];
    }

    return ['schemaSection' => '', 'recordsSection' => $input];
}

/** Return true if any field in any type references another user-defined type. */
function hasReferenceFields(MaxiParseResult $result): bool
{
    static $nonRef = ['str', 'int', 'decimal', 'float', 'bool', 'bytes'];

    foreach ($result->schema->types as $typeDef) {
        foreach ($typeDef->fields as $field) {
            $te = $field->typeExpr;
            if ($te === null) {
                continue;
            }
            if (in_array($te, $nonRef, true)) {
                continue;
            }
            if (str_starts_with($te, 'enum') || $te === 'map' || str_starts_with($te, 'map<')) {
                continue;
            }
            return true;
        }
    }
    return false;
}
