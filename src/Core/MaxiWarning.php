<?php

declare(strict_types=1);

namespace Maxi\Core;

final class MaxiWarning
{
    public function __construct(
        public readonly string  $message,
        public readonly ?string $code = null,
        public readonly ?int    $line = null,
        public readonly ?int    $column = null,
    ) {
    }
}
