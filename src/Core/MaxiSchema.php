<?php

declare(strict_types=1);

namespace Maxi\Core;

class MaxiSchema
{
    public string $version = '1.0.0';
    /** @var string[] */
    public array $imports = [];
    /** @var array<string, MaxiTypeDef> */
    public array $types = [];

    /**
     * Maps type name (long name) → alias. Built after schema parsing.
     * @var array<string, string>
     */
    public array $nameToAlias = [];

    public function addType(MaxiTypeDef $typeDef): void
    {
        $this->types[$typeDef->alias] = $typeDef;
        if ($typeDef->name !== null) {
            $this->nameToAlias[$typeDef->name] = $typeDef->alias;
        }
    }

    public function getType(string $aliasOrName): ?MaxiTypeDef
    {
        return $this->types[$aliasOrName]
            ?? ($this->types[$this->nameToAlias[$aliasOrName] ?? ''] ?? null);
    }

    public function hasType(string $aliasOrName): bool
    {
        return isset($this->types[$aliasOrName])
            || isset($this->nameToAlias[$aliasOrName]);
    }

    /** Resolve a type name or alias to its alias. */
    public function resolveAlias(string $aliasOrName): ?string
    {
        if (isset($this->types[$aliasOrName])) {
            return $aliasOrName;
        }
        return $this->nameToAlias[$aliasOrName] ?? null;
    }
}
