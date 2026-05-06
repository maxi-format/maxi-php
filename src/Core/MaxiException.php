<?php

declare(strict_types=1);

namespace Maxi\Core;

class MaxiException extends \RuntimeException
{
    public function __construct(
        string                  $message,
        public readonly string  $errorCode,
        public readonly ?int    $maxi_line = null,
        public readonly ?int    $maxi_column = null,
        public readonly ?string $maxi_filename = null,
        ?\Throwable             $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function __toString(): string
    {
        $loc = $this->maxi_line !== null
            ? ' at line ' . $this->maxi_line . ($this->maxi_column !== null ? ', column ' . $this->maxi_column : '')
            : '';
        $file = $this->maxi_filename !== null ? ' in ' . $this->maxi_filename : '';

        return 'MaxiException [' . $this->errorCode . ']' . $file . $loc . ': ' . $this->message;
    }
}
