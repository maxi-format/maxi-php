<?php

declare(strict_types=1);

namespace Maxi\Core;

class MaxiTypeDef
{
    /** @var MaxiFieldDef[] */
    public array $fields;

    /** @var string[] */
    public array $parents;

    public bool $inheritanceResolved = false;

    /** Cached id-field index. -2 = not computed, -1 = no id field. */
    private int $idFieldIndex = -2;
    /** @var bool[]|null */
    private ?array $requiredFlags = null;
    /** @var (string[]|null)[]|null */
    private ?array $enumValues = null;
    /** @var (array<string,mixed>|null)[]|null */
    private ?array $enumAliasMaps = null;
    private bool $hasRuntimeConstraints = false;

    /**
     * @param string[]        $parents
     * @param MaxiFieldDef[]  $fields
     */
    public function __construct(
        public readonly string  $alias,
        public readonly ?string $name = null,
        array                   $parents = [],
        array                   $fields = [],
    ) {
        $this->parents = $parents;
        $this->fields = $fields;
    }

    public function addField(MaxiFieldDef $field): void
    {
        $this->fields[] = $field;
        $this->invalidateCache();
    }

    public function invalidateCache(): void
    {
        $this->idFieldIndex = -2;
        $this->requiredFlags = null;
        $this->enumValues = null;
        $this->enumAliasMaps = null;
    }

    private function ensureCache(): void
    {
        if ($this->idFieldIndex !== -2) {
            return;
        }

        $len = count($this->fields);

        $this->idFieldIndex = -1;
        foreach ($this->fields as $i => $f) {
            foreach ($f->constraints ?? [] as $c) {
                if ($c->type === 'id') {
                    $this->idFieldIndex = $i;
                    break 2;
                }
            }
        }
        if ($this->idFieldIndex === -1) {
            foreach ($this->fields as $i => $f) {
                if ($f->name === 'id') {
                    $this->idFieldIndex = $i;
                    break;
                }
            }
        }
        if ($this->idFieldIndex === -1 && $len > 0) {
            $this->idFieldIndex = 0;
        }

        $this->requiredFlags = array_fill(0, $len, false);
        foreach ($this->fields as $i => $f) {
            foreach ($f->constraints ?? [] as $c) {
                if ($c->type === 'required') {
                    $this->requiredFlags[$i] = true;
                    break;
                }
            }
        }

        $this->enumValues = array_fill(0, $len, null);
        $this->enumAliasMaps = array_fill(0, $len, null);
        $this->hasRuntimeConstraints = false;
        foreach ($this->fields as $i => $f) {
            $am = $f->getEnumAliasMap();
            $this->enumAliasMaps[$i] = $am;
            $this->enumValues[$i] = $am !== null ? array_flip(array_map('strval', $am)) : null;

            foreach ($f->constraints ?? [] as $c) {
                if (in_array($c->type, ['comparison', 'pattern', 'exact-length'], true)) {
                    $this->hasRuntimeConstraints = true;
                }
            }
        }
    }

    public function getIdFieldIndex(): int
    {
        $this->ensureCache();
        return $this->idFieldIndex;
    }

    public function getIdField(): ?MaxiFieldDef
    {
        $this->ensureCache();
        return $this->idFieldIndex >= 0 ? $this->fields[$this->idFieldIndex] : null;
    }

    public function getIdentifierFieldName(): ?string
    {
        return $this->getIdField()?->name;
    }

    public function hasRuntimeConstraints(): bool
    {
        $this->ensureCache();
        return $this->hasRuntimeConstraints;
    }

    /** @return bool[]|null */
    public function getRequiredFlags(): ?array
    {
        $this->ensureCache();
        return $this->requiredFlags;
    }

    /** @return (string[]|null)[]|null */
    public function getEnumValuesCache(): ?array
    {
        $this->ensureCache();
        return $this->enumValues;
    }

    /** @return (array<string,mixed>|null)[]|null */
    public function getEnumAliasMapsCache(): ?array
    {
        $this->ensureCache();
        return $this->enumAliasMaps;
    }
}
