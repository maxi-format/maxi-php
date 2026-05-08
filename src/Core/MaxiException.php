<?php

declare(strict_types=1);

namespace Maxi\Core;

class MaxiException extends \RuntimeException
{
    public function __construct(
        string                  $message,
        public readonly string  $errorCode,
        public readonly ?int    $maxiLine = null,
        public readonly ?int    $maxiColumn = null,
        public readonly ?string $maxiFilename = null,
        ?\Throwable             $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function __toString(): string
    {
        $loc = $this->maxiLine !== null
            ? ' at line ' . $this->maxiLine . ($this->maxiColumn !== null ? ', column ' . $this->maxiColumn : '')
            : '';
        $file = $this->maxiFilename !== null ? ' in ' . $this->maxiFilename : '';

        return 'MaxiException [' . $this->errorCode . ']' . $file . $loc . ': ' . $this->message;
    }
}
