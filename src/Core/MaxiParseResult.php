<?php

declare(strict_types=1);

namespace Maxi\Core;

class MaxiParseResult
{
    public MaxiSchema $schema;
    /** @var MaxiRecord[] */
    public array $records = [];
    /** @var MaxiWarning[] */
    public array $warnings = [];

    /**
     * Object registry built by ReferenceResolver — alias → [id → field-map].
     * @var array<string,array<string,array<string,mixed>>>|null
     */
    public ?array $objectRegistry = null;

    public function __construct()
    {
        $this->schema = new MaxiSchema();
    }

    public function addWarning(string $message, ?string $code = null, ?int $line = null, ?int $column = null): void
    {
        $this->warnings[] = new MaxiWarning($message, $code, $line, $column);
    }
}
