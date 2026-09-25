<?php

declare(strict_types=1);

namespace BAGArt\AsyncKernel\Exceptions;

final class ASKInterruptException extends ASKException
{
    public function __construct(
        public readonly string $source,
        string $message = 'Interrupt signal received',
    ) {
        parent::__construct($message, 499);
    }
}
