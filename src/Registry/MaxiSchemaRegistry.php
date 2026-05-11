<?php

declare(strict_types=1);

namespace Maxi\Registry;

use Maxi\Attribute\MaxiField as MaxiFieldAttr;
use Maxi\Attribute\MaxiType as MaxiTypeAttr;

/**
 * Registry that maps PHP classes to MAXI schema descriptors.
 *
 * Resolution order (first match wins):
 *   1. PHP Attributes — #[MaxiType] on the class + #[MaxiField] on properties
 *   2. Static `$class::$maxiSchema` property (array descriptor)
 *   3. Manual entry registered via MaxiSchemaRegistry::define()
 *
 * Mirrors the JS defineMaxiSchema / getMaxiSchema API.
 *
 * The returned descriptor has the shape used by dumpMaxi() / dumpFromObjects():
 * [
 *   'alias'   => string,
 *   'name'    => string|null,
 *   'parents' => string[],
 *   'fields'  => [
 *     ['name'=>string, 'typeExpr'=>string|null, 'annotation'=>string|null,
 *      'required'=>bool, 'id'=>bool, 'defaultValue'=>mixed, 'constraints'=>string|null],
 *     ...
 *   ],
 * ]
 */
final class MaxiSchemaRegistry
{
    /** @var array<string, array<string,mixed>> FQCN → descriptor */
    private static array $manual = [];

    /** @var array<string, array<string,mixed>> FQCN → descriptor (reflection cache) */
    private static array $reflectionCache = [];

    /**
     * Manually register a schema descriptor for a class.
     * Useful for third-party classes that cannot carry PHP Attributes.
     *
     * @param string $class Fully-qualified class name
     * @param array<string, mixed> $schema Descriptor array with at least 'alias' and 'fields'
     */
    public static function define(string $class, array $schema): void
    {
        if (!isset($schema['alias']) || !is_string($schema['alias'])) {
            throw new \InvalidArgumentException(
                "MaxiSchemaRegistry::define(): schema must contain a string 'alias'."
            );
        }
        self::$manual[$class] = $schema;
    }

    /**
     * Remove a manually registered schema.
     * Does not affect static properties or PHP Attributes.
     */
    public static function undefine(string $class): void
    {
        unset(self::$manual[$class]);
        unset(self::$reflectionCache[$class]);
    }

    /**
     * Reset the entire registry (manual entries + reflection cache).
     * Primarily useful in tests.
     */
    public static function reset(): void
    {
        self::$manual = [];
        self::$reflectionCache = [];
    }

    /**
     * Look up the MAXI schema descriptor for a class or instance.
     *
     * @param string|object $classOrInstance
     * @return array<string,mixed>|null
     */
    public static function get(string|object $classOrInstance): ?array
    {
        $class = is_object($classOrInstance) ? get_class($classOrInstance) : $classOrInstance;

        $fromReflection = self::fromReflection($class);
        if ($fromReflection !== null) {
            return $fromReflection;
        }

        if (class_exists($class, false)) {
            try {
                $ref = new \ReflectionClass($class);
                if ($ref->hasProperty('maxiSchema')) {
                    $prop = $ref->getProperty('maxiSchema');
                    if ($prop->isStatic()) {
                        $value = $prop->getValue();
                        if (is_array($value)) {
                            return $value;
                        }
                    }
                }
            } catch (\ReflectionException) {
            }
        }

        return self::$manual[$class] ?? null;
    }

    /** @return array<string,mixed>|null */
    private static function fromReflection(string $class): ?array
    {
        if (array_key_exists($class, self::$reflectionCache)) {
            return self::$reflectionCache[$class] ?: null;
        }

        if (!class_exists($class, false) && !class_exists($class)) {
            self::$reflectionCache[$class] = false;
            return null;
        }

        try {
            $ref = new \ReflectionClass($class);
            $typeAttrs = $ref->getAttributes(MaxiTypeAttr::class);

            if (count($typeAttrs) === 0) {
                self::$reflectionCache[$class] = false;
                return null;
            }

            /** @var MaxiTypeAttr $typeAttr */
            $typeAttr = $typeAttrs[0]->newInstance();
            $descriptor = self::buildDescriptor($ref, $typeAttr);

            self::$reflectionCache[$class] = $descriptor;
            return $descriptor;

        } catch (\ReflectionException) {
            self::$reflectionCache[$class] = false;
            return null;
        }
    }

    /**
     * Build a descriptor array from a class reflection + MaxiType attribute.
     *
     * @param \ReflectionClass<object> $ref
     * @return array<string,mixed>
     */
    private static function buildDescriptor(\ReflectionClass $ref, MaxiTypeAttr $typeAttr): array
    {
        $fields = [];

        foreach ($ref->getProperties() as $prop) {
            $fieldAttrs = $prop->getAttributes(MaxiFieldAttr::class);
            if (count($fieldAttrs) === 0) {
                continue;
            }

            /** @var MaxiFieldAttr $fieldAttr */
            $fieldAttr = $fieldAttrs[0]->newInstance();

            $fieldName = $fieldAttr->name ?? $prop->getName();

            $constraints = self::buildConstraints($fieldAttr);

            $defaultValue = $fieldAttr->defaultValue;
            if ($defaultValue === MaxiFieldAttr::missing()) {
                if ($prop->hasDefaultValue()) {
                    $defaultValue = $prop->getDefaultValue();
                } else {
                    $defaultValue = null;
                }
            }

            $fields[] = [
                'name' => $fieldName,
                'typeExpr' => $fieldAttr->typeExpr,
                'annotation' => $fieldAttr->annotation,
                'constraints' => $constraints,
                'defaultValue' => $defaultValue,
                '_constraintStr' => $fieldAttr->constraints,
            ];
        }

        return [
            'alias' => $typeAttr->alias,
            'name' => $typeAttr->name,
            'parents' => $typeAttr->parents,
            'fields' => $fields,
        ];
    }

    /**
     * Build a constraints array (compatible with dump helpers) from a MaxiField attribute.
     *
     * @return array<array<string,mixed>>
     */
    private static function buildConstraints(MaxiFieldAttr $fieldAttr): array
    {
        $constraints = [];

        if ($fieldAttr->required) {
            $constraints[] = ['type' => 'required'];
        }

        if ($fieldAttr->id) {
            $constraints[] = ['type' => 'id'];
        }

        if ($fieldAttr->constraints !== null && $fieldAttr->constraints !== '') {
            foreach (self::parseRawConstraints($fieldAttr->constraints) as $c) {
                $constraints[] = $c;
            }
        }

        return $constraints;
    }

    /**
     * Parse a raw constraint string (e.g. ">=3,<=50,pattern:^[a-z]+$") into
     * the structured array format used by dumpConstraintRaw().
     *
     * @return array<array<string,mixed>>
     */
    private static function parseRawConstraints(string $raw): array
    {
        $constraints = [];

        $parts = [];
        $current = '';
        $depth = 0;
        $len = strlen($raw);

        for ($i = 0; $i < $len; $i++) {
            $ch = $raw[$i];
            if ($ch === '(' || $ch === '[') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']') {
                $depth = max(0, $depth - 1);
            }
            if ($ch === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
            } else {
                $current .= $ch;
            }
        }
        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if ($part === '!') {
                $constraints[] = ['type' => 'required'];
                continue;
            }

            if ($part === 'id') {
                $constraints[] = ['type' => 'id'];
                continue;
            }

            if (preg_match('/^=(\d+)$/', $part, $m)) {
                $constraints[] = ['type' => 'exact-length', 'value' => (int)$m[1]];
                continue;
            }

            if (preg_match('/^(>=|>|<=|<)\s*(.+)$/', $part, $m)) {
                $numValue = is_numeric($m[2]) ? $m[2] + 0 : $m[2];
                $constraints[] = [
                    'type' => 'comparison',
                    'operator' => $m[1],
                    'value' => ['operator' => $m[1], 'value' => $numValue],
                ];
                continue;
            }

            if (str_starts_with($part, 'pattern:')) {
                $constraints[] = [
                    'type' => 'pattern',
                    'value' => trim(substr($part, strlen('pattern:'))),
                ];
                continue;
            }

            if (str_starts_with($part, 'mime:')) {
                $mimeSpec = trim(substr($part, strlen('mime:')));
                $mimes = str_starts_with($mimeSpec, '[') && str_ends_with($mimeSpec, ']')
                    ? array_map('trim', explode(',', substr($mimeSpec, 1, -1)))
                    : [$mimeSpec];
                $constraints[] = ['type' => 'mime', 'value' => $mimes];
                continue;
            }

            if (preg_match('/^(\d+:)?(\d+)?\.(\d+(?::\d+)?)?$/', $part)) {
                $constraints[] = ['type' => 'decimal-precision', 'value' => $part];
                continue;
            }
        }

        return $constraints;
    }
}

