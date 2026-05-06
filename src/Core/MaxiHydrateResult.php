<?php

declare(strict_types=1);

namespace Maxi\Core;

class MaxiHydrateResult
{
    public MaxiSchema $schema;
    /** @var array<string, object[]> alias → hydrated object list */
    public array $data = [];
    /** @var MaxiWarning[] */
    public array $warnings = [];

    public function __construct(MaxiSchema $schema)
    {
        $this->schema = $schema;
    }

    public function addWarning(string $message, ?string $code = null, ?int $line = null, ?int $column = null): void
    {
        $this->warnings[] = new MaxiWarning($message, $code, $line, $column);
    }
}
