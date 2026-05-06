<?php

declare(strict_types=1);

namespace Maxi\Core;

/**
 * A single parsed constraint, e.g. {type: 'required'} or {type: 'comparison', value: ['>=', 3]}.
 */
final class ParsedConstraint
{
    public function __construct(
        /** @var 'required'|'id'|'comparison'|'pattern'|'mime'|'decimal-precision'|'exact-length' */
        public readonly string $type,
        public readonly mixed  $value = null,
    ) {
    }
}
