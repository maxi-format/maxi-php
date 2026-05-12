<?php

declare(strict_types=1);

namespace Maxi\Core;

class MaxiFieldDef
{
    public static mixed $MISSING = null;

    private static bool $missingInitialised = false;

    /** @var string Pre-computed base type for fast dispatch */
    public readonly string $baseType;

    public static function missing(): mixed
    {
        if (!self::$missingInitialised) {
            self::$MISSING = new \stdClass();
            self::$missingInitialised = true;
        }
        return self::$MISSING;
    }

    public function __construct(
        public readonly string  $name,
        public readonly ?string $typeExpr = null,
        public readonly ?string $annotation = null,
        /** @var ParsedConstraint[]|null */
        public readonly ?array  $constraints = null,
        /** @var ParsedConstraint[]|null */
        public readonly ?array  $elementConstraints = null,
        public readonly mixed   $defaultValue = null,
    ) {
        if ($typeExpr === null) {
            $this->baseType = 'str';
        } elseif (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $typeExpr, $m)) {
            $this->baseType = $m[1];
        } else {
            $this->baseType = $typeExpr;
        }
    }

    public function isRequired(): bool
    {
        if ($this->constraints === null) {
            return false;
        }
        foreach ($this->constraints as $c) {
            if ($c->type === 'required') {
                return true;
            }
        }
        return false;
    }

    public function isId(): bool
    {
        if ($this->constraints === null) {
            return false;
        }
        foreach ($this->constraints as $c) {
            if ($c->type === 'id') {
                return true;
            }
        }
        return false;
    }

    public function hasDefault(): bool
    {
        return $this->defaultValue !== MaxiFieldDef::missing();
    }

    /** @return string[]|null Parsed enum values if this field is an enum type, null otherwise. */
    public function getEnumValues(): ?array
    {
        if ($this->typeExpr === null || !str_starts_with($this->typeExpr, 'enum')) {
            return null;
        }
        if (!preg_match('/^enum(?:<\w+>)?\[([^\]]*)\]$/', $this->typeExpr, $m)) {
            return null;
        }
        $values = [];
        foreach (explode(',', $m[1]) as $v) {
            $t = trim($v);
            if ($t === '') {
                continue;
            }
            if (str_starts_with($t, '"') && str_ends_with($t, '"')) {
                $t = substr($t, 1, -1);
            }
            if (str_contains($t, ':')) {
                $colonPos = strpos($t, ':');
                $t = substr($t, $colonPos + 1);
            }
            $values[] = $t;
        }
        return $values;
    }

    /**
     * Returns a map of wire-token => semantic-value for enum fields.
     * Both alias and full-value-string are keys (backward compat).
     * For enum<int> the semantic value is an int.
     * Returns null if not an enum field.
     * @return array<string, mixed>|null
     */
    public function getEnumAliasMap(): ?array
    {
        if ($this->typeExpr === null || !str_starts_with($this->typeExpr, 'enum')) {
            return null;
        }
        if (!preg_match('/^enum(?:<(\w+)>)?\[([^\]]*)\]$/', $this->typeExpr, $m)) {
            return null;
        }
        $baseType = ($m[1] !== '') ? $m[1] : 'str';
        $map = [];
        foreach (explode(',', $m[2]) as $v) {
            $t = trim($v);
            if ($t === '') {
                continue;
            }
            if (str_starts_with($t, '"') && str_ends_with($t, '"')) {
                $t = substr($t, 1, -1);
            }
            if (str_contains($t, ':')) {
                $colonPos = strpos($t, ':');
                $alias = substr($t, 0, $colonPos);
                $fullStr = substr($t, $colonPos + 1);
            } else {
                $alias = $t;
                $fullStr = $t;
            }
            $fullVal = ($baseType === 'int') ? (int)$fullStr : $fullStr;
            $map[$alias] = $fullVal;
            if ($alias !== $fullStr) {
                $map[$fullStr] = $fullVal;
            }
        }
        return $map;
    }
}

MaxiFieldDef::missing();
