<?php

declare(strict_types=1);

namespace Maxi\Core;

final class MaxiRecord
{
    public function __construct(
        public readonly string $alias,
        /** @var mixed[] */
        public readonly array  $values = [],
        public readonly ?int   $lineNumber = null,
    ) {
    }
}
