<?php

declare(strict_types=1);

namespace Maxi\Attribute;

/**
 * Marks a PHP class as a MAXI type.
 * Place this attribute on the class declaration.
 *
 * @example
 * #[MaxiType(alias: 'U', name: 'User')]
 * class User { ... }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class MaxiType
{
    /**
     * @param string $alias Short MAXI alias (e.g. 'U')
     * @param string|null $name Optional long type name (e.g. 'User')
     * @param string[] $parents Parent aliases for inheritance (e.g. ['Base'])
     */
    public function __construct(
        public readonly string  $alias,
        public readonly ?string $name = null,
        public readonly array   $parents = [],
    ) {
    }
}
